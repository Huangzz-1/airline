<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Boarding\BoardingService;
use App\Modules\Booking\ReservationService;
use App\Modules\Finance\FinanceService;
use App\Modules\Finance\GatewayPayments;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Operations\FlightChangeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\LegacyReservationFixture;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.mode' => 'mock', 'payments.bank.mode' => 'mock', 'payments.vnpay.enabled' => true, 'payments.vnpay.tmn_code' => 'GROUPTEST', 'payments.vnpay.hash_secret' => 'group-test-secret', 'payments.momo.enabled' => true, 'payments.momo.partner_code' => 'GROUPTEST', 'payments.momo.access_key' => 'group-access', 'payments.momo.secret_key' => 'group-secret', 'payments.bank.enabled' => true, 'payments.bank.name' => 'TEST BANK', 'payments.bank.account_number' => '000000000000', 'payments.bank.account_name' => 'TEST AIRLINE']);
    }

    private function flight(string $from = 'HAN', string $to = 'SGN', int $hours = 3, int $capacity = 12, ?array $layout = null): int
    {
        $ops = DB::connection('ops');
        $aircraft = $ops->table('aircraft')->insertGetId(['registration' => 'VN-T'.Str::upper(Str::random(7)), 'type' => 'A320', 'capacity' => $capacity, 'status' => 'AVAILABLE', 'seat_layout' => $layout ? json_encode($layout) : null]);
        $id = $ops->table('flights')->insertGetId(['number' => 'AS'.random_int(10000, 99999), 'origin' => $from, 'destination' => $to, 'aircraft_id' => $aircraft, 'departure' => now()->addHours($hours), 'arrival' => now()->addHours($hours + 2), 'status' => 'SCHEDULED', 'gate' => 'A2', 'sale_price' => 1000000]);
        DB::table('inventory')->insert(['flight_id' => $id, 'capacity' => $capacity, 'price' => 1000000, 'seat_layout' => $layout ? json_encode($layout) : null]);
        foreach (['ECONOMY' => 1000000, 'FLEX' => 1500000, 'BUSINESS' => 2500000] as $code => $price) {
            DB::table('fare_classes')->insert(['flight_id' => $id, 'code' => $code, 'name' => $code, 'quota' => $capacity, 'price' => $price]);
        }

        return $id;
    }

    private function input(array $flights, int $count = 2, string $type = 'ROUND_TRIP', string $fare = 'ECONOMY'): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'trip_type' => $type, 'passengers' => array_map(fn ($i) => ['name' => 'Hành khách '.$i, 'date_of_birth' => '1990-01-01'], range(1, $count)), 'segments' => array_map(fn ($id) => ['flight_id' => $id, 'fare_class' => $fare], $flights)];
    }

    private function drain(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, app(OutboxRelay::class)->drain());
        }
    }

    private function capture(User $u, string $id, string $result = 'approve', string $method = 'VNPAY'): object
    {
        $session = app(ReservationService::class)->pay($u, $id, $method, '127.0.0.1');
        $this->drain();
        $this->actingAs($u)->post('/payments/mock/'.$session->intent_id, ['result' => $result])->assertRedirect('/reservations/'.$id);
        $this->drain();

        return $session;
    }

    public function test_round_trip_party_has_one_pnr_and_server_priced_coupons(): void
    {
        $u = User::factory()->create();
        $input = $this->input([$this->flight(), $this->flight('SGN', 'HAN', 12)], 1);
        $r = $this->actingAs($u)->postJson('/api/v1/reservations', $input + ['total_amount' => 1])->assertCreated()->json();
        $this->assertSame(2000000, $r['total_amount']);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_passengers', 1);
        $this->assertDatabaseCount('reservation_segments', 2);
        $this->assertDatabaseCount('bookings', 2);
        $this->get('/reservations/'.$r['id'])->assertOk()->assertSee('Hành khách 1')->assertDontSee('Hành khách 2');
        $this->get('/journeys')->assertOk()->assertSee($r['reference']);
        $this->postJson('/api/v1/reservations', $input)->assertCreated()->assertJsonPath('id', $r['id']);
        $input['passengers'][0]['name'] = 'Một người khác';
        $this->postJson('/api/v1/reservations', $input)->assertConflict();
        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_group_hold_rolls_back_every_leg_when_last_leg_has_insufficient_capacity(): void
    {
        $u = User::factory()->create();
        $input = $this->input([$this->flight(), $this->flight('SGN', 'HAN', 12, 0)], 1);
        $this->actingAs($u)->postJson('/api/v1/reservations', $input)->assertConflict();
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('reservation_passengers', 0);
    }

    public function test_quota_failure_rolls_back_the_whole_party(): void
    {
        $flight = $this->flight();
        DB::table('fare_classes')->where('flight_id', $flight)->where('code', 'ECONOMY')->update(['quota' => 0]);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/reservations', $this->input([$flight], 1, 'ONE_WAY'))->assertConflict();
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_concurrent_parties_cannot_oversell_the_last_seats_on_multiple_legs(): void
    {
        $flights = [$this->flight(capacity: 1), $this->flight('SGN', 'HAN', 12, 1)];
        $a = User::factory()->create();
        $b = User::factory()->create();
        $statuses = $this->race([[$a, $this->input($flights, 1)], [$b, $this->input($flights, 1)]]);
        $this->assertSame([201, 409], $statuses);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('bookings', 2);
        foreach ($flights as $flight) {
            $this->assertSame(1, DB::table('bookings')->where('flight_id', $flight)->count());
        }
    }

    public function test_concurrent_same_key_with_different_itineraries_has_one_winner(): void
    {
        $u = User::factory()->create();
        $a = $this->input([$this->flight()], 1, 'ONE_WAY');
        $b = $this->input([$this->flight('HAN', 'DAD')], 1, 'ONE_WAY');
        $b['idempotency_key'] = $a['idempotency_key'];
        $this->assertSame([201, 409], $this->race([[$u, $a], [$u, $b]]));
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('bookings', 1);
    }

    private function race(array $requests): array
    {
        $start = microtime(true) + 1;
        $processes = [];
        foreach ($requests as [$user, $input]) {
            $process = new Process([PHP_BINARY, base_path('tests/reservation-worker.php'), (string) $user->id, base64_encode(json_encode($input)), (string) $start], base_path(), ['DB_DATABASE' => 'airline_test', 'DB_USERNAME' => 'airline_test', 'APP_ENV' => 'testing', 'MAIL_MAILER' => 'array']);
            $process->start();
            $processes[] = $process;
        }
        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $statuses[] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);

        return $statuses;
    }

    public function test_itinerary_validation_checks_reverse_route_chronology_and_duplicate_flights(): void
    {
        $this->actingAs(User::factory()->create());
        $first = $this->flight();
        foreach ([$this->input([$first, $this->flight('HAN', 'DAD', 12)], 1), $this->input([$first, $this->flight('SGN', 'HAN', 4)], 1), $this->input([$first, $first], 1)] as $input) {
            $this->postJson('/api/v1/reservations', $input)->assertUnprocessable();
        }
        $this->assertDatabaseCount('bookings', 0);
        $input = $this->input([$first, $this->flight('SGN', 'DAD', 12), $this->flight('DAD', 'HAN', 20)], 1, 'ROUND_TRIP');
        $input['outbound_count'] = 1;
        $this->postJson('/api/v1/reservations', $input)->assertCreated()->assertJsonPath('total_amount', 3000000);
    }

    public function test_one_capture_issues_all_tickets_and_replay_cannot_duplicate_money_or_tickets(): void
    {
        $u = User::factory()->create();
        $r = LegacyReservationFixture::create($u, $this->input([$this->flight(), $this->flight('SGN', 'HAN', 12)]));
        $s = $this->capture($u, $r->id);
        $this->assertSame(1, DB::connection('finance')->table('checkout_intents')->count());
        $this->assertSame(4000000, DB::connection('finance')->table('checkout_intents')->first()->amount);
        $this->assertDatabaseCount('tickets', 4);
        $this->assertSame(4, DB::connection('finance')->table('payments')->count());
        $this->assertSame(4000000, (int) DB::connection('finance')->table('payments')->sum('amount'));
        $this->assertSame(4, DB::table('bookings')->where('status', 'CONFIRMED')->count());
        $this->post('/payments/mock/'.$s->intent_id, ['result' => 'approve'])->assertRedirect();
        $this->drain();
        $this->assertDatabaseCount('tickets', 4);
        $this->assertSame(4, DB::connection('finance')->table('ledger_entries')->count());
        $fin = User::factory()->create(['role' => 'finance']);
        $run = app(FinanceService::class)->reconcile($fin, now()->toDateString(), now()->toDateString());
        $this->assertSame(4, DB::connection('finance')->table('reconciliation_entries')->where('run_id', $run->id)->where('result', 'MATCHED')->count());
    }

    public function test_momo_decline_releases_all_coupons_without_tickets(): void
    {
        $u = User::factory()->create();
        $r = LegacyReservationFixture::create($u, $this->input([$this->flight()], 3, 'ONE_WAY'));
        $this->capture($u, $r->id, 'decline', 'MOMO');
        $this->assertSame(3, DB::table('bookings')->where('status', 'CANCELLED')->count());
        $this->assertDatabaseCount('tickets', 0);
        $this->assertSame(0, DB::connection('finance')->table('ledger_entries')->count());
    }

    public function test_bank_payment_is_reported_once_for_the_group_and_requires_finance_confirmation(): void
    {
        $u = User::factory()->create();
        $r = LegacyReservationFixture::create($u, $this->input([$this->flight()], 2, 'ONE_WAY'));
        $s = app(ReservationService::class)->pay($u, $r->id, 'BANK', '127.0.0.1');
        $this->drain();
        $this->actingAs($u)->post('/journeys/'.$s->booking_id.'/transfer-report', ['reference' => 'GROUP-TRANSFER'])->assertRedirect();
        $this->drain();
        $this->assertDatabaseCount('tickets', 0);
        $fin = User::factory()->create(['role' => 'finance']);
        app(GatewayPayments::class)->decideBank($fin, $s->intent_id, true, 'STATEMENT-GROUP-001');
        $this->drain();
        $this->assertDatabaseCount('tickets', 2);
        $this->assertSame(2000000, (int) DB::connection('finance')->table('payments')->sum('amount'));
    }

    public function test_ownership_and_group_checkout_cannot_be_bypassed_through_individual_endpoints(): void
    {
        $u = User::factory()->create();
        $r = LegacyReservationFixture::create($u, $this->input([$this->flight()], 2, 'ONE_WAY'));
        $b = DB::table('bookings')->first();
        $this->actingAs($u)->postJson('/api/v1/bookings/'.$b->id.'/pay', ['method' => 'VNPAY'])->assertConflict();
        $this->postJson('/api/v1/bookings/'.$b->id.'/cancel')->assertConflict();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/reservations/'.$r->id)->assertNotFound();
        $this->postJson('/api/v1/reservations/'.$r->id.'/pay', ['method' => 'VNPAY'])->assertNotFound();
        $this->actingAs($u)->postJson('/api/v1/reservations/'.$r->id.'/cancel')->assertOk();
        $this->assertSame(2, DB::table('bookings')->where('status', 'CANCELLED')->count());
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/api/v1/reservations', $this->input([$b->flight_id], 1, 'ONE_WAY'))->assertForbidden();
    }

    public function test_expired_party_cannot_start_checkout(): void
    {
        $u = User::factory()->create();
        $r = LegacyReservationFixture::create($u, $this->input([$this->flight()], 2, 'ONE_WAY'));
        DB::table('reservations')->where('id', $r->id)->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($u)->postJson('/api/v1/reservations/'.$r->id.'/pay', ['method' => 'VNPAY'])->assertConflict();
        $this->assertSame(0, DB::connection('finance')->table('checkout_intents')->count());
    }

    public function test_unpaid_party_can_release_remaining_holds_after_a_leg_is_cancelled(): void
    {
        $u = User::factory()->create();
        $r = LegacyReservationFixture::create($u, $this->input([$this->flight(), $this->flight('SGN', 'HAN', 12)]));
        $first = DB::table('bookings')->where('reservation_id', $r->id)->orderBy('reference')->first();
        DB::table('bookings')->where('id', $first->id)->update(['status' => 'CANCELLED']);
        $this->actingAs($u)->get('/reservations/'.$r->id)->assertOk()->assertSee('Hủy các chỗ chưa thanh toán');
        $this->postJson('/api/v1/reservations/'.$r->id.'/cancel')->assertOk();
        $this->assertSame(4, DB::table('bookings')->where('status', 'CANCELLED')->count());
    }

    public function test_named_passengers_get_distinct_seats_qrs_and_documents_in_the_correct_cabin(): void
    {
        $layout = ['cabins' => [['code' => 'BUSINESS', 'name' => 'Thương gia', 'rows' => 1, 'blocks' => ['AC', 'DF']], ['code' => 'ECONOMY', 'name' => 'Phổ thông', 'rows' => 2, 'blocks' => ['AB', 'CD']]]];
        $flight = $this->flight(layout: $layout);
        $u = User::factory()->create(['name' => 'Người đặt hộ']);
        $r = LegacyReservationFixture::create($u, $this->input([$flight], 2, 'ONE_WAY', 'BUSINESS'));
        $this->capture($u, $r->id);
        $coupons = DB::table('bookings')->orderBy('reference')->get();
        $this->actingAs($u)->postJson('/api/v1/bookings/'.$coupons[0]->id.'/checkin', ['seat' => '2A'])->assertUnprocessable();
        $first = $this->postJson('/api/v1/bookings/'.$coupons[0]->id.'/checkin', ['seat' => '1A'])->assertOk()->json();
        $this->postJson('/api/v1/bookings/'.$coupons[1]->id.'/checkin', ['seat' => '1A'])->assertConflict();
        $second = $this->postJson('/api/v1/bookings/'.$coupons[1]->id.'/checkin', ['seat' => '1C'])->assertOk()->json();
        $this->assertNotSame($first['token'], $second['token']);
        $this->get('/journeys/'.$coupons[1]->id.'/ticket')->assertOk()->assertSee('Hành khách 2')->assertSee($r->reference)->assertDontSee('<h2>Người đặt hộ</h2>', false);
        $this->get('/journeys/'.$coupons[1]->id.'/boarding-pass')->assertOk()->assertSee('1C');
        $agent = User::factory()->create(['role' => 'airport_agent', 'airport_code' => 'HAN']);
        $this->actingAs($agent)->get('/staff/passengers?q='.$r->reference)->assertOk()->assertSee('Hành khách 1')->assertSee('Hành khách 2');
    }

    public function test_physical_cabin_limits_apply_across_fare_buckets(): void
    {
        $layout = ['cabins' => [['code' => 'BUSINESS', 'rows' => 1, 'blocks' => ['AC', 'DF']], ['code' => 'ECONOMY', 'rows' => 2, 'blocks' => ['AB', 'CD']]]];
        $flight = $this->flight(layout: $layout);
        $u = User::factory()->create();
        LegacyReservationFixture::create($u, $this->input([$flight], 8, 'ONE_WAY', 'FLEX'));
        $this->actingAs($u)->postJson('/api/v1/reservations', $this->input([$flight], 1, 'ONE_WAY', 'ECONOMY'))->assertConflict();
        $this->assertDatabaseCount('bookings', 8);
    }

    public function test_bad_party_inputs_are_rejected_without_writes(): void
    {
        $u = User::factory()->create();
        $input = $this->input([$this->flight()], 1, 'ONE_WAY');
        $this->actingAs($u);
        foreach ([['passengers' => []], ['passengers' => [['name' => 'Khách một'], ['name' => 'Khách hai']]], ['passengers' => array_fill(0, 10, ['name' => 'Người đi'])], ['passengers' => [['name' => '  ']]], ['passengers' => [['name' => 'Người đi', 'date_of_birth' => now()->addDay()->toDateString()]]]] as $change) {
            $this->postJson('/api/v1/reservations', array_replace($input, $change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_partial_refund_uses_only_the_passenger_segment_allocation(): void
    {
        $u = User::factory()->create();
        $r = LegacyReservationFixture::create($u, $this->input([$this->flight()], 2, 'ONE_WAY'));
        $this->capture($u, $r->id);
        $b = DB::table('bookings')->orderBy('reference')->first();
        $fin = app(FinanceService::class);
        $fin->requestRefund($u, $b->id, 'Hoàn riêng vé của một hành khách');
        $this->drain();
        $fin->destination($u, $b->id, ['bank_name' => 'TEST BANK', 'account_name' => 'TEST PASSENGER', 'account_number' => 'TEST000001']);
        $this->drain();
        $id = DB::connection('finance')->table('refunds')->where('booking_id', $b->id)->value('id');
        $fin->decide(User::factory()->create(['role' => 'finance']), $id, true);
        $fin->settleRefund(User::factory()->create(['role' => 'finance']), $id, 'REFUND-ONE-PASSENGER');
        $this->drain();
        $this->assertSame(1, DB::table('bookings')->where('status', 'REFUNDED')->count());
        $this->assertSame(1, DB::table('bookings')->where('status', 'CONFIRMED')->count());
        $this->assertSame(1000000, (int) DB::connection('finance')->table('ledger_entries')->sum('amount'));
        $this->actingAs($u)->get('/reservations/'.$r->id)->assertOk()->assertSee('Hành khách 2');
    }

    public function test_exchange_preserves_passenger_identity_and_checks_other_legs(): void
    {
        $u = User::factory()->create();
        $r = LegacyReservationFixture::create($u, $this->input([$this->flight(), $this->flight('SGN', 'HAN', 12)]));
        $this->capture($u, $r->id);
        $coupons = DB::table('bookings')->orderBy('reference')->get();
        $this->actingAs($u)->postJson('/api/v1/actions/exchange_request', ['request_id' => (string) Str::uuid(), 'booking_id' => $coupons[0]->id, 'flight_id' => $this->flight(hours: 11), 'reason' => 'Chuyến mới về sau giờ khởi hành chặng kế'])->assertUnprocessable();
        $this->assertDatabaseCount('exchange_requests', 0);
        $req = $this->postJson('/api/v1/actions/exchange_request', ['request_id' => (string) Str::uuid(), 'booking_id' => $coupons[0]->id, 'flight_id' => $this->flight(hours: 8), 'reason' => 'Đổi chuyến vẫn đủ giờ nối chặng'])->assertSuccessful()->json();
        $target = DB::table('bookings')->find($req['target_booking_id']);
        $this->assertSame($r->id, $target->reservation_id);
        $this->assertSame($coupons[0]->passenger_id, $target->passenger_id);
        $this->assertSame($coupons[0]->reservation_segment_id, $target->reservation_segment_id);
        $this->postJson('/api/v1/actions/exchange_request', ['request_id' => (string) Str::uuid(), 'booking_id' => $coupons[2]->id, 'flight_id' => $this->flight('SGN', 'HAN', 20), 'reason' => 'Không tạo yêu cầu chồng lên cùng người'])->assertConflict();
        $this->postJson('/api/v1/actions/exchange_request', ['request_id' => (string) Str::uuid(), 'booking_id' => $coupons[3]->id, 'flight_id' => $this->flight('SGN', 'HAN', 20), 'reason' => 'Hành khách thứ hai có thể yêu cầu riêng'])->assertSuccessful();
    }

    public function test_exchange_approval_rechecks_connection_after_another_flight_time_changes(): void
    {
        $u = User::factory()->create();
        $return = $this->flight('SGN', 'HAN', 12);
        $r = LegacyReservationFixture::create($u, $this->input([$this->flight(), $return], 1));
        $this->capture($u, $r->id);
        $source = DB::table('bookings')->orderBy('reference')->first();
        $req = $this->actingAs($u)->postJson('/api/v1/actions/exchange_request', ['request_id' => (string) Str::uuid(), 'booking_id' => $source->id, 'flight_id' => $this->flight(hours: 8), 'reason' => 'Đổi chuyến có đủ thời gian lúc đề nghị'])->assertSuccessful()->json();
        DB::connection('ops')->table('flights')->where('id', $return)->update(['departure' => now()->addHours(9), 'arrival' => now()->addHours(11)]);
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson('/api/v1/actions/exchange_decide', ['exchange_id' => $req['id'], 'approve' => true, 'scenario' => 'approve'])->assertUnprocessable();
        $this->assertDatabaseHas('bookings', ['id' => $source->id, 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('bookings', ['id' => $req['target_booking_id'], 'status' => 'HELD']);
    }

    public function test_aircraft_swap_remaps_within_the_paid_cabin_and_rotates_each_pass(): void
    {
        $this->nativeSwap(2, 'COMPLETED');
    }

    public function test_aircraft_swap_rejects_insufficient_business_capacity_even_with_enough_total_seats(): void
    {
        $this->nativeSwap(3, 'FAILED');
    }

    private function nativeSwap(int $count, string $expected): void
    {
        $oldLayout = ['cabins' => [['code' => 'BUSINESS', 'rows' => 1, 'blocks' => ['AC', 'DF']], ['code' => 'ECONOMY', 'rows' => 2, 'blocks' => ['AB', 'CD']]]];
        $newLayout = ['cabins' => [['code' => 'BUSINESS', 'rows' => 1, 'blocks' => ['A', 'D']], ['code' => 'ECONOMY', 'rows' => 3, 'blocks' => ['AB', 'CD'], 'last_row_seats' => 2]]];
        $f = $this->flight(layout: $oldLayout);
        $db = DB::connection('ops');
        $plane = $db->table('aircraft')->insertGetId(['registration' => 'VN-TARGET', 'type' => 'A320', 'capacity' => 12, 'status' => 'AVAILABLE', 'seat_layout' => json_encode($newLayout)]);
        $u = User::factory()->create();
        $r = LegacyReservationFixture::create($u, $this->input([$f], $count, 'ONE_WAY', 'BUSINESS'));
        $this->capture($u, $r->id);
        $tokens = [];
        foreach (DB::table('bookings')->orderBy('reference')->get() as $i => $b) {
            $tokens[$b->id] = app(BoardingService::class)->checkin($b->id, $u->id, ['1A', '1C', '1D'][$i])->token;
        }
        foreach ([1001, 1002] as $crew) {
            $db->table('crew_assignments')->insert(['flight_id' => $f, 'crew_member_id' => $crew, 'assigned_by' => $u->id, 'created_at' => now()]);
        }
        $ops = User::factory()->create(['role' => 'operations', 'airport_code' => 'HAN']);
        $manager = User::factory()->create(['role' => 'ops_manager', 'airport_code' => 'HAN']);
        $svc = app(FlightChangeService::class);
        $plan = $svc->propose($ops, ['flight_id' => $f, 'kind' => 'AIRCRAFT', 'target_aircraft_id' => $plane, 'reason' => 'Kiểm tra thay cấu hình cabin cùng loại tàu']);
        $svc->decide($manager, ['change_id' => $plan->id, 'approve' => true]);
        $this->drain();
        $this->assertDatabaseHas('flight_changes', ['id' => $plan->id, 'status' => $expected], 'ops');
        foreach (DB::table('bookings')->get() as $b) {
            $pass = app(BoardingService::class)->pass($b->id, $u->id);
            if ($expected === 'COMPLETED') {
                $this->assertTrue(in_array($b->seat, ['1A', '1D']));
                $this->assertNotSame($tokens[$b->id], $pass->token);
            } else {
                $this->assertSame($tokens[$b->id], $pass->token);
            }
        }
        $this->assertNull(DB::table('inventory')->where('flight_id', $f)->value('frozen_change_id'));
    }
}
