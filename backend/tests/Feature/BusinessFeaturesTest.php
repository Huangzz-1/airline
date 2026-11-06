<?php

namespace Tests\Feature;

use App\Http\Controllers\BusinessController;
use App\Models\User;
use App\Modules\Baggage\BaggageService;
use App\Modules\Boarding\BoardingOperations;
use App\Modules\Boarding\BoardingService;
use App\Modules\Boarding\PassengerChanges;
use App\Modules\Booking\ExchangeService;
use App\Modules\Booking\FareRules;
use App\Modules\Booking\ReservationEdits;
use App\Modules\Booking\ReservationService;
use App\Modules\Customer\CustomerService;
use App\Modules\Customer\SupportDesk;
use App\Modules\Finance\FinanceOperations;
use App\Modules\Finance\FinanceService;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Finance\RevenueReport;
use App\Modules\Operations\PassengerRecovery;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessFeaturesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['payments.mode' => 'mock', 'payments.bank.mode' => 'mock', 'payments.bank.enabled' => true, 'payments.bank.name' => 'TEST BANK', 'payments.bank.account_number' => '000000000000', 'payments.bank.account_name' => 'TEST AIRLINE', 'payments.vnpay.enabled' => true, 'payments.vnpay.tmn_code' => 'BUSINESS', 'payments.vnpay.hash_secret' => 'business-test-secret']);
        DB::connection('ops')->table('flights')->where('id', 1)->update(['departure' => now()->addHours(5), 'arrival' => now()->addHours(6), 'status' => 'SCHEDULED']);
        DB::table('fare_classes')->insert(['flight_id' => 1, 'code' => 'ECONOMY', 'name' => 'Phổ thông', 'quota' => 60, 'price' => 1290000]);
    }

    private function hold(?User $u = null, array $flights = [1]): array
    {
        $u ??= User::factory()->create();
        $r = app(ReservationService::class)->hold($u, ['idempotency_key' => (string) Str::uuid(), 'trip_type' => count($flights) > 1 ? 'ROUND_TRIP' : 'ONE_WAY', 'outbound_count' => 1, 'passengers' => [['name' => 'Nguyen Van An', 'date_of_birth' => '1990-01-01', 'details' => ['surname' => 'Nguyen', 'given_name' => 'Van An', 'nationality' => 'VN', 'gender' => 'M', 'document_number' => 'P12345678', 'document_expiry' => '2035-01-01']]], 'contact_details' => ['name' => 'Nguyen Van An', 'email' => $u->email, 'phone' => '0912345678'], 'segments' => array_map(fn ($id) => ['flight_id' => $id, 'fare_class' => 'ECONOMY'], $flights)]);

        return [$u, $r, DB::table('bookings')->where('reservation_id', $r->id)->orderBy('reference')->first()];
    }

    private function drain(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, app(OutboxRelay::class)->drain());
        }
    }

    private function pay(User $u, object $r, string $method = 'VNPAY'): object
    {
        $s = app(ReservationService::class)->pay($u, $r->id, $method, '127.0.0.1');
        $this->drain();
        if ($method !== 'BANK') {
            $this->actingAs($u)->post('/payments/mock/'.$s->intent_id, ['result' => 'approve'])->assertRedirect();
            $this->drain();
        }

        return $s;
    }

    private function editInput(object $r): array
    {
        $p = DB::table('reservation_passengers')->where('reservation_id', $r->id)->first();

        return ['request_id' => (string) Str::uuid(), 'passenger_id' => $p->id, 'version' => ReservationEdits::fingerprint($r, $p), 'reason' => 'Sửa đúng giấy tờ của hành khách', 'passenger' => ['surname' => 'Nguyen', 'given_name' => 'Minh Anh', 'nationality' => 'VN', 'gender' => 'F', 'date_of_birth' => '1992-01-01', 'document_number' => 'P87654321', 'document_expiry' => '2035-01-01'], 'contact' => ['name' => 'Nguyen Minh Anh', 'email' => 'contact-revised@example.test', 'phone' => '0987654321']];
    }

    public function test_unpaid_identity_edit_is_owned_encrypted_versioned_and_does_not_extend_hold(): void
    {
        [$u,$r,$b] = $this->hold();
        $input = $this->editInput($r);
        $expires = $b->expires_at;
        $this->actingAs(User::factory()->create())->postJson('/api/v1/reservations/'.$r->id.'/details', $input)->assertNotFound();
        $this->actingAs($u)->get('/reservations/'.$r->id.'/details')->assertOk()->assertSee('Lưu / Gửi yêu cầu sửa');
        $this->postJson('/api/v1/reservations/'.$r->id.'/details', $input)->assertCreated()->assertJsonPath('status', 'APPLIED');
        $p = DB::table('reservation_passengers')->find($input['passenger_id']);
        $this->assertSame('Nguyen Minh Anh', $p->name);
        $this->assertStringNotContainsString('P87654321', $p->details);
        $this->assertSame($expires, DB::table('bookings')->find($b->id)->expires_at);
        $this->postJson('/api/v1/reservations/'.$r->id.'/details', $input)->assertCreated();
        $stale = $input;
        $stale['request_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/reservations/'.$r->id.'/details', $stale)->assertConflict();
        $this->assertDatabaseCount('passenger_revisions', 1);
    }

    public function test_paid_identity_requires_independent_approval_and_revokes_old_boarding_pass(): void
    {
        [$u,$r,$b] = $this->hold();
        $this->pay($u, $r);
        app(BoardingService::class)->checkin($b->id, $u->id, '1A');
        $change = app(ReservationEdits::class)->submit($u, $r->id, $this->editInput($r));
        $this->assertSame('PENDING', $change->status);
        $this->assertDatabaseHas('reservation_passengers', ['id' => $change->passenger_id, 'name' => 'Nguyen Van An']);
        $this->actingAs($u)->postJson('/api/v1/business/revision_decide', ['id' => $change->id, 'approve' => 1, 'note' => 'Đã đối chiếu giấy tờ'])->assertForbidden();
        $agent = User::factory()->create(['role' => 'customer_service']);
        $this->actingAs($agent)->get('/workspace/passenger-revisions/'.$change->id)->assertOk()->assertSee('P87654321');
        app(ReservationEdits::class)->decide($agent, $change->id, true, 'Đã đối chiếu thông tin');
        $this->assertDatabaseHas('reservation_passengers', ['id' => $change->passenger_id, 'name' => 'Nguyen Minh Anh']);
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'CONFIRMED', 'seat' => null]);
        $this->assertNotNull(DB::table('boarding_passes')->where('booking_id', $b->id)->value('revoked_at'));
        app(ReservationEdits::class)->decide($agent, $change->id, true, 'Gửi lại cùng quyết định');
        $this->assertSame(1, DB::table('notifications')->where('title', 'Kết quả sửa thông tin hành khách')->count());
    }

    public function test_baggage_price_enters_group_payment_and_weight_limit_is_enforced(): void
    {
        [$u,$r,$b] = $this->hold();
        $this->actingAs($u)->postJson('/api/v1/bookings/'.$b->id.'/baggage', ['package' => '10:1'])->assertConflict();
        $this->postJson('/api/v1/bookings/'.$b->id.'/baggage', ['package' => '10:1590000'])->assertOk()->assertJsonPath('amount', 1590000);
        $this->assertDatabaseHas('reservations', ['id' => $r->id, 'total_amount' => 1590000]);
        $s = $this->pay($u, DB::table('reservations')->find($r->id));
        $this->assertSame(1590000, DB::connection('finance')->table('checkout_intents')->find($s->intent_id)->amount);
        $this->actingAs($u)->postJson('/api/v1/bookings/'.$b->id.'/baggage', ['package' => '0:1290000'])->assertConflict();
        app(BoardingService::class)->checkin($b->id, $u->id, '1A');
        $agent = User::factory()->create(['role' => 'airport_agent', 'airport_code' => 'HAN']);
        app(BaggageService::class)->tag($agent, ['booking_id' => $b->id, 'weight' => 18, 'request_id' => 'bag-valid-package']);
        $this->actingAs($agent)->postJson('/api/v1/baggage-tags', ['booking_id' => $b->id, 'weight' => 13, 'request_id' => 'bag-over-package'])->assertConflict();
        $this->assertDatabaseCount('baggage', 1);
    }

    public function test_fare_rule_updates_only_affect_new_bookings_and_stale_versions_are_rejected(): void
    {
        [$u,$r,$old] = $this->hold();
        $fare = DB::table('fare_classes')->where('flight_id', 1)->first();
        $input = FareRules::DEFAULTS + ['fare_id' => $fare->id, 'version' => FareRules::version($fare->rules)];
        $input['change_allowed'] = 0;
        $input['refund_allowed'] = 0;
        $input['included_baggage_kg'] = 5;
        $admin = User::factory()->create(['role' => 'data_admin']);
        $this->actingAs($admin)->postJson('/api/v1/business/fare_rules', $input)->assertOk();
        $this->postJson('/api/v1/business/fare_rules', $input)->assertConflict();
        [,, $new] = $this->hold($u);
        $this->assertTrue(FareRules::decode($old->fare_rules)['change_allowed']);
        $this->assertFalse(FareRules::decode($new->fare_rules)['change_allowed']);
        $this->assertSame(20, FareRules::decode($old->fare_rules)['included_baggage_kg']);
        $this->actingAs($admin)->get('/workspace/fare-rules')->assertOk()->assertSee('Lưu điều kiện vé');
    }

    public function test_refund_fee_is_disclosed_and_only_net_amount_is_paid_and_reported(): void
    {
        DB::table('fare_classes')->where('flight_id', 1)->update(['rules' => json_encode(array_replace(FareRules::DEFAULTS, ['refund_fee' => 200000]))]);
        [$u,$r,$b] = $this->hold();
        $this->pay($u, $r);
        $this->actingAs($u)->get('/journeys/'.$b->id)->assertOk()->assertSee('1.090.000')->assertSee('200.000');
        $service = app(FinanceService::class);
        $service->requestRefund($u, $b->id, 'Thay đổi kế hoạch cá nhân');
        $this->drain();
        $refund = DB::connection('finance')->table('refunds')->where('booking_id', $b->id)->first();
        $this->assertSame(1090000, $refund->amount);
        $service->destination($u, $b->id, ['bank_name' => 'Test Bank', 'account_name' => 'Test Person', 'account_number' => 'TEST000001']);
        $this->drain();
        $approver = User::factory()->create(['role' => 'finance']);
        $settler = User::factory()->create(['role' => 'finance']);
        $service->decide($approver, $refund->id, true);
        $service->settleRefund($settler, $refund->id, 'NET-REFUND-0001');
        $this->drain();
        $this->assertSame(-1090000, DB::connection('finance')->table('ledger_entries')->where('booking_id', $b->id)->where('kind', 'REFUND')->value('amount'));
        $report = app(RevenueReport::class)->build($settler, ['start' => now('Asia/Ho_Chi_Minh')->toDateString(), 'end' => now('Asia/Ho_Chi_Minh')->toDateString(), 'group' => 'route']);
        $this->assertSame(200000, $report['totals']['net']);
    }

    public function test_csv_matching_is_atomic_idempotent_and_never_captures_underpaid_rows(): void
    {
        [$u,$r,$b] = $this->hold();
        $s = $this->pay($u, $r, 'BANK');
        $fin = User::factory()->create(['role' => 'finance']);
        $date = now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s');
        $header = "transaction_ref,booking_reference,amount,currency,booked_at\n";
        $body = $header."BANK-IMPORT-001,{$r->reference},1290000,VND,$date\nBANK-IMPORT-002,{$r->reference},1000000,VND,$date\n";
        $upload = fn ($content) => UploadedFile::fake()->createWithContent('statement.csv', $content);
        $this->actingAs($fin)->postJson('/api/v1/business/statement_import', ['source' => 'TESTBANK', 'file' => $upload($body)])->assertCreated();
        $this->postJson('/api/v1/business/statement_import', ['source' => 'TESTBANK', 'file' => $upload($body)])->assertCreated();
        $this->assertSame(1, DB::connection('finance')->table('statement_batches')->count());
        $this->assertSame(2, DB::connection('finance')->table('statement_rows')->count());
        $bad = DB::connection('finance')->table('statement_rows')->where('result', 'UNDERPAID')->first();
        $this->postJson('/api/v1/business/statement_confirm', ['id' => $bad->id, 'consent' => 1])->assertConflict();
        $this->assertDatabaseCount('tickets', 0);
        $good = DB::connection('finance')->table('statement_rows')->where('result', 'READY')->first();
        $this->postJson('/api/v1/business/statement_confirm', ['id' => $good->id, 'consent' => 1])->assertOk();
        $this->postJson('/api/v1/business/statement_confirm', ['id' => $good->id, 'consent' => 1])->assertOk();
        $this->assertDatabaseCount('tickets', 1);
        $this->assertSame(1, DB::connection('finance')->table('payments')->count());
        $changed = str_replace('1290000', '1290001', $body);
        $this->postJson('/api/v1/business/statement_import', ['source' => 'TESTBANK', 'file' => $upload($changed)])->assertConflict();
        $this->assertSame(1, DB::connection('finance')->table('statement_batches')->count());
        $this->get('/workspace/bank-statements')->assertOk()->assertSee('BANK-IMPORT-001');
        $this->get('/workspace/bank-statements?template=1')->assertDownload('mau-giao-dich.csv');
    }

    public function test_adjustment_requires_a_different_approver_and_posts_exactly_once(): void
    {
        [$u,$r,$b] = $this->hold();
        $this->pay($u, $r);
        $fin = User::factory()->create(['role' => 'finance']);
        $other = User::factory()->create(['role' => 'finance']);
        $d = ['request_id' => (string) Str::uuid(), 'booking_id' => $b->id, 'amount' => -100000, 'reason' => 'Điều chỉnh theo chứng từ đối chiếu'];
        $a = app(FinanceOperations::class)->adjustment($fin, $d);
        $this->actingAs($fin)->postJson('/api/v1/business/adjustment_decide', ['id' => $a->id, 'approve' => 1, 'note' => 'Đã kiểm tra số tiền'])->assertConflict();
        $this->actingAs($other)->postJson('/api/v1/business/adjustment_decide', ['id' => $a->id, 'approve' => 1, 'note' => 'Đã kiểm tra số tiền'])->assertOk();
        $this->postJson('/api/v1/business/adjustment_decide', ['id' => $a->id, 'approve' => 1, 'note' => 'Gửi lại quyết định'])->assertOk();
        $this->assertSame(2, DB::connection('finance')->table('ledger_entries')->count());
        $this->assertSame(1290000, DB::connection('finance')->table('payments')->first()->amount);
        $report = app(RevenueReport::class)->build($other, ['start' => now('Asia/Ho_Chi_Minh')->toDateString(), 'end' => now('Asia/Ho_Chi_Minh')->toDateString(), 'group' => 'route']);
        $this->assertSame(1190000, $report['totals']['net']);
        $this->assertSame(-100000, $report['totals']['adjustments']);
        $this->get('/workspace/financial-adjustments')->assertOk()->assertSee('Điều chỉnh theo chứng từ');
    }

    public function test_change_fee_and_purchased_baggage_are_priced_once_on_the_replacement_ticket(): void
    {
        $rules = FareRules::DEFAULTS;
        $rules['change_fee'] = 150000;
        DB::table('fare_classes')->where('flight_id', 1)->where('code', 'ECONOMY')->update(['rules' => json_encode($rules)]);
        [$u, $r, $b] = $this->hold();
        $this->actingAs($u)->postJson('/api/v1/bookings/'.$b->id.'/baggage', ['package' => '10:1590000'])->assertOk();
        $this->pay($u, $r);
        $target = $this->flight('HAN', 'SGN', 8);
        $input = ['request_id' => (string) Str::uuid(), 'booking_id' => $b->id, 'flight_id' => $target, 'reason' => 'Change to a later flight'];
        $exchange = app(ExchangeService::class)->request($u, $input);
        $this->assertSame($exchange->id, app(ExchangeService::class)->request($u, $input)->id);
        $replacement = DB::table('bookings')->find($exchange->target_booking_id);
        $this->assertSame(1950000, (int) $replacement->amount);
        $this->assertSame(300000, (int) $replacement->baggage_amount);
        $this->assertSame(150000, (int) $replacement->change_fee_amount);
        $finance = User::factory()->create(['role' => 'finance']);
        app(ExchangeService::class)->decide($finance, ['exchange_id' => $exchange->id, 'approve' => true, 'scenario' => 'approve']);
        $this->drain();
        $intent = DB::connection('finance')->table('checkout_intents')->where('booking_id', $replacement->id)->first();
        $this->actingAs($u)->post('/payments/mock/'.$intent->id, ['result' => 'approve'])->assertRedirect();
        $this->drain();
        $refund = DB::connection('finance')->table('refunds')->where('booking_id', $b->id)->first();
        $this->assertSame(1590000, (int) $refund->amount);
        $this->assertSame('APPROVED', $refund->status);
        $service = app(FinanceService::class);
        $service->destination($u, $b->id, ['bank_name' => 'Test Bank', 'account_name' => 'Test Person', 'account_number' => 'TEST000001']);
        $this->drain();
        $service->decide(User::factory()->create(['role' => 'finance']), $refund->id, true);
        $service->settleRefund($finance, $refund->id, 'EXCHANGE-REFUND-0001');
        $this->drain();
        $this->assertSame('REFUNDED', DB::table('bookings')->find($b->id)->status);
        $this->assertSame('CONFIRMED', DB::table('bookings')->find($replacement->id)->status);
        $this->assertSame(-1590000, (int) DB::connection('finance')->table('ledger_entries')->where('booking_id', $b->id)->where('kind', 'REFUND')->value('amount'));
    }

    public function test_revenue_uses_vietnam_transaction_day_filters_and_exports_csv(): void
    {
        [$u,$r,$b] = $this->hold();
        $this->pay($u, $r);
        DB::connection('finance')->table('ledger_entries')->update(['created_at' => '2030-01-01 18:00:00']);
        $fin = User::factory()->create(['role' => 'finance']);
        $report = app(RevenueReport::class)->build($fin, ['start' => '2030-01-02', 'end' => '2030-01-02', 'group' => 'day', 'origin' => 'HAN']);
        $this->assertSame('2030-01-02', $report['rows'][0]['label']);
        $this->assertSame(1290000, $report['totals']['captured']);
        $this->actingAs($fin)->get('/workspace/revenue?start=2030-01-02&end=2030-01-02&group=route')->assertOk()->assertSee('HAN')->assertSee('1.290.000');
        $response = $this->get('/workspace/revenue?start=2030-01-02&end=2030-01-02&group=route&export=csv')->assertDownload();
        $this->assertStringContainsString('1290000', $response->streamedContent());
        $this->actingAs($u)->get('/workspace/revenue')->assertForbidden();
    }

    public function test_manual_checkin_control_enforces_scope_and_rechecks_the_window(): void
    {
        [$u,$r,$b] = $this->hold();
        $this->pay($u, $r);
        $agent = User::factory()->create(['role' => 'airport_agent', 'airport_code' => 'HAN']);
        app(BoardingOperations::class)->control($agent, 1, ['mode' => 'CLOSED', 'reason' => 'Tạm khóa để kiểm tra']);
        $this->actingAs($u)->postJson('/api/v1/bookings/'.$b->id.'/checkin', ['seat' => '1A'])->assertConflict();
        $wrong = User::factory()->create(['role' => 'airport_agent', 'airport_code' => 'SGN']);
        $this->actingAs($wrong)->postJson('/api/v1/business/checkin_control', ['id' => 1, 'mode' => 'OPEN', 'reason' => 'Mở lại thủ tục'])->assertForbidden();
        app(BoardingOperations::class)->control($agent, 1, ['mode' => 'OPEN', 'reason' => 'Mở lại sau kiểm tra']);
        $this->actingAs($u)->postJson('/api/v1/bookings/'.$b->id.'/checkin', ['seat' => '1A'])->assertOk();
        $this->actingAs($agent)->get('/workspace/boarding-control?flight=1')->assertOk()->assertSee('Nguyen Van An');
    }

    public function test_manifest_is_private_immutable_versioned_and_must_be_refinalized_after_offload(): void
    {
        DB::connection('ops')->table('flights')->where('id', 1)->update(['departure' => now()->addMinutes(45), 'arrival' => now()->addMinutes(145), 'status' => 'BOARDING']);
        [$u,$r,$b] = $this->hold();
        $this->pay($u, $r);
        $token = app(BoardingService::class)->checkin($b->id, $u->id, '1A')->token;
        [$u2,$r2,$b2] = $this->hold();
        $this->pay($u2, $r2);
        $agent = User::factory()->create(['role' => 'airport_agent', 'airport_code' => 'HAN']);
        app(BoardingService::class)->board($agent, 1, $token);
        DB::connection('ops')->table('flights')->where('id', 1)->update(['status' => 'GATE_CLOSED']);
        $service = app(BoardingOperations::class);
        $m = $service->finalize($agent, 1);
        $this->assertSame(1, $m->version);
        $this->assertStringNotContainsString('Nguyen Van An', $m->snapshot);
        $this->assertSame($m->id, $service->finalize($agent, 1)->id);
        $this->actingAs($u)->get('/manifests/'.$m->id)->assertForbidden();
        $this->actingAs($agent)->get('/manifests/'.$m->id.'?export=csv')->assertDownload();
        app(PassengerChanges::class)->offload($agent, ['booking_id' => $b2->id, 'reason' => 'Khách không có mặt tại cửa lên tàu']);
        $this->assertFalse($service->read($agent, $m->id)->current);
        $ops = User::factory()->create(['role' => 'operations', 'airport_code' => 'HAN']);
        $this->actingAs($ops)->postJson('/api/v1/actions/flight_state', ['flight_id' => 1, 'status' => 'DEPARTED', 'reason' => 'Kiểm tra danh sách sau chốt'])->assertConflict();
        $next = $service->finalize($agent, 1);
        $this->assertSame(2, $next->version);
        $this->assertTrue($service->read($agent, $next->id)->current);
        $this->postJson('/api/v1/actions/flight_state', ['flight_id' => 1, 'status' => 'DEPARTED', 'reason' => 'Danh sách đã chốt phiên bản mới'])->assertOk();
    }

    public function test_support_assignment_deadline_and_escalation_are_owned_and_idempotent(): void
    {
        $u = User::factory()->create();
        $case = app(CustomerService::class)->create($u, ['type' => 'COMPLAINT', 'subject' => 'Cần hỗ trợ lịch trình', 'message' => 'Tôi cần hỗ trợ đổi lịch trình chuyến bay']);
        $a = User::factory()->create(['role' => 'customer_service']);
        $b = User::factory()->create(['role' => 'customer_service']);
        $due = now()->addHours(2)->startOfMinute();
        $input = ['id' => $case->id, 'assigned_to' => $b->id, 'priority' => 'HIGH', 'due_at' => $due->copy()->tz('Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i'), 'reason' => 'Giao nhân viên chuyên trách'];
        $this->actingAs($a)->postJson('/api/v1/business/support_assign', $input)->assertOk();
        $this->assertTrue(Carbon::parse(DB::table('support_cases')->find($case->id)->due_at)->eq($due));
        $past = array_replace($input, ['due_at' => now('Asia/Ho_Chi_Minh')->subMinute()->format('Y-m-d\TH:i')]);
        $this->postJson('/api/v1/business/support_assign', $past)->assertUnprocessable();
        $this->actingAs($a)->postJson('/api/v1/actions/support_reply', ['case_id' => $case->id, 'status' => 'IN_PROGRESS', 'response' => 'Đang kiểm tra lịch trình cho khách'])->assertForbidden();
        app(CustomerService::class)->respond($b, $case->id, ['status' => 'IN_PROGRESS', 'response' => 'Đang kiểm tra lịch trình cho khách']);
        app(CustomerService::class)->followup($u, $case->id, 'Tôi đã gửi đủ thông tin chuyến bay');
        $this->assertTrue(Carbon::parse(DB::table('support_cases')->find($case->id)->due_at)->eq($due->startOfSecond()));
        DB::table('support_cases')->where('id', $case->id)->update(['due_at' => now()->subMinute()]);
        $this->assertSame(1, app(SupportDesk::class)->escalateOverdue());
        $this->assertSame(0, app(SupportDesk::class)->escalateOverdue());
        $this->assertSame(2, DB::table('notifications')->where('title', 'Hỗ trợ quá hạn #'.$case->id)->count());
        $this->actingAs($b)->get('/workspace/support-desk?overdue=1')->assertOk()->assertSee('Cần hỗ trợ lịch trình');
    }

    private function flight(string $from, string $to, int $hour): int
    {
        DB::connection('ops')->table('routes')->updateOrInsert(['origin' => $from, 'destination' => $to], ['duration_minutes' => 60, 'distance_km' => 600, 'active' => true]);
        $id = DB::connection('ops')->table('flights')->insertGetId(['number' => 'AS'.random_int(10000, 99999), 'origin' => $from, 'destination' => $to, 'aircraft_id' => 1, 'departure' => now()->addHours($hour), 'arrival' => now()->addHours($hour + 2), 'status' => 'SCHEDULED', 'gate' => 'A1']);
        DB::table('inventory')->insert(['flight_id' => $id, 'capacity' => 60, 'price' => 1500000]);
        DB::table('fare_classes')->insert(['flight_id' => $id, 'code' => 'ECONOMY', 'name' => 'Phổ thông', 'quota' => 60, 'price' => 1500000]);

        return $id;
    }

    public function test_recovery_checks_connections_scope_price_approval_and_customer_ownership(): void
    {
        $return = $this->flight('SGN', 'HAN', 12);
        [$u,$r,$b] = $this->hold(null, [1, $return]);
        $this->pay($u, $r);
        DB::connection('ops')->table('flights')->where('id', 1)->update(['status' => 'DELAYED']);
        $target = $this->flight('HAN', 'SGN', 8);
        $bad = $this->flight('HAN', 'SGN', 11);
        $ops = User::factory()->create(['role' => 'operations', 'airport_code' => 'HAN']);
        $manager = User::factory()->create(['role' => 'ops_manager', 'airport_code' => 'HAN']);
        $d = ['request_id' => (string) Str::uuid(), 'booking_id' => $b->id, 'target_flight_id' => $bad, 'fare_class' => 'ECONOMY', 'reason' => 'Đổi do chuyến bay bị chậm'];
        $this->actingAs($ops)->postJson('/api/v1/business/recovery_propose', $d)->assertConflict();
        $d['target_flight_id'] = $target;
        $offer = app(PassengerRecovery::class)->propose($ops, $d);
        $this->get('/workspace/passenger-recovery?flight=1')->assertOk()->assertSee('Nguyen Van An');
        $this->postJson('/api/v1/business/recovery_decide', ['id' => $offer->id, 'approve' => 1])->assertForbidden();
        app(PassengerRecovery::class)->decide($manager, $offer->id, true);
        $this->assertSame(1, DB::table('notifications')->where('title', 'Phương án chuyến bay thay thế')->count());
        $this->actingAs($u)->get('/recovery-offers/'.$offer->id)->assertOk()->assertSee('Gửi yêu cầu đổi sang chuyến này');
        $this->actingAs(User::factory()->create())->get('/recovery-offers/'.$offer->id)->assertNotFound();
        DB::table('fare_classes')->where('flight_id', $target)->update(['price' => 1600000]);
        $this->actingAs($u)->get('/recovery-offers/'.$offer->id)->assertConflict();
        DB::table('fare_classes')->where('flight_id', $target)->update(['price' => 1500000]);
        $response = $this->postJson('/api/v1/recovery-offers/'.$offer->id.'/exchange')->assertCreated();
        $targetBooking = $response->json('target_booking_id');
        $this->postJson('/api/v1/recovery-offers/'.$offer->id.'/exchange')->assertOk()->assertJsonPath('target_booking_id', $targetBooking);
        $this->get('/recovery-offers/'.$offer->id)->assertRedirect('/journeys/'.$targetBooking);
        $this->assertDatabaseCount('exchange_requests', 1);
    }

    public function test_all_new_workspaces_render_and_reject_passengers(): void
    {
        foreach (BusinessController::AREAS as $section => [$title,$roles]) {
            $u = User::factory()->create(['role' => $roles[0], 'airport_code' => 'HAN']);
            $this->actingAs($u)->get('/workspace/'.$section)->assertOk()->assertSee($title);
            $this->actingAs(User::factory()->create())->get('/workspace/'.$section)->assertForbidden();
        }
    }
}
