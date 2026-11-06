<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Booking\ReservationService;
use App\Modules\Booking\TripDetails;
use App\Modules\Finance\OutboxRelay;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TripBookingFlowTest extends TestCase
{
    private array $flights;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2030-01-10 00:00:00', 'UTC'));
        foreach (['VCA', 'PQC'] as $code) {
            DB::connection('ops')->table('airports')->insert(['code' => $code, 'city' => $code, 'name' => $code, 'timezone' => 'Asia/Ho_Chi_Minh']);
        }
        $this->flights = [
            $this->flight('HAN', 'SGN', '11 08:00', '11 09:30', 1500000),
            $this->flight('HAN', 'DAD', '11 08:00', '11 09:00', 800000),
            $this->flight('DAD', 'SGN', '11 10:15', '11 11:30', 700000),
            $this->flight('HAN', 'VCA', '11 07:00', '11 08:00', 300000),
            $this->flight('VCA', 'PQC', '11 09:15', '11 10:00', 300000),
            $this->flight('PQC', 'SGN', '11 11:15', '11 12:00', 300000),
            $this->flight('SGN', 'HAN', '13 14:00', '13 15:30', 1600000),
            $this->flight('SGN', 'DAD', '13 14:00', '13 15:00', 900000),
            $this->flight('DAD', 'HAN', '13 16:15', '13 17:30', 800000),
        ];
    }

    private function flight(string $from, string $to, string $departure, string $arrival, int $price): int
    {
        $ops = DB::connection('ops');
        $ops->table('routes')->updateOrInsert(['origin' => $from, 'destination' => $to], ['duration_minutes' => 90, 'distance_km' => 700, 'active' => true]);
        $id = $ops->table('flights')->insertGetId(['number' => 'AS'.random_int(10000, 99999), 'origin' => $from, 'destination' => $to, 'aircraft_id' => 1, 'departure' => Carbon::parse('2030-01-'.$departure, 'Asia/Ho_Chi_Minh')->utc(), 'arrival' => Carbon::parse('2030-01-'.$arrival, 'Asia/Ho_Chi_Minh')->utc(), 'status' => 'SCHEDULED', 'gate' => 'A1']);
        DB::table('inventory')->insert(['flight_id' => $id, 'capacity' => 12, 'price' => $price]);
        DB::table('fare_classes')->insert(['flight_id' => $id, 'code' => 'ECONOMY', 'name' => 'Phổ thông', 'quota' => 12, 'price' => $price]);

        return $id;
    }

    private function select(array $ids, string $type = 'ONE_WAY', ?string $outbound = null)
    {
        return $this->get('/trip/select?'.http_build_query(['flights' => implode(',', $ids), 'fare_class' => 'ECONOMY', 'trip_type' => $type, 'passenger_count' => 1, 'return_date' => '2030-01-13', 'outbound' => $outbound]));
    }

    private function details(string $url): array
    {
        $page = $this->get($url)->assertOk();

        return ['selection' => $page->viewData('selection'), 'idempotency_key' => $page->viewData('requestKey'), 'consent' => 1, 'passengers' => [
            ['surname' => 'Nguyen', 'given_name' => 'Minh Anh', 'nationality' => 'VN', 'gender' => 'F', 'date_of_birth' => '1995-01-01', 'document_number' => 'P12345678', 'document_expiry' => '2035-01-01'],
        ], 'contact' => ['name' => 'Nguyen Minh Anh', 'phone' => '0912345678', 'email' => 'contact@example.test']];
    }

    public function test_connections_are_real_filtered_and_quoted_with_stop_facet_prices(): void
    {
        $base = '/?origin=HAN&destination=SGN&date=2030-01-11';
        $this->getJson($base)->assertOk()->assertJsonPath('total', 3);
        foreach ([0 => 1500000, 1 => 1500000, 2 => 900000] as $stops => $price) {
            $this->getJson($base.'&stops[0]='.$stops)->assertJsonPath('total', 1)->assertJsonPath('data.0.stop_count', $stops)->assertJsonPath('data.0.price', $price)->assertJsonCount($stops + 1, 'data.0.legs');
        }
        $this->getJson($base.'&stops[0]=1')->assertJsonPath('data.0.id', $this->flights[1].'-'.$this->flights[2]);
        $html = $this->get($base)->assertOk();
        $html->assertSee('2+ điểm dừng')->assertSee('1 điểm dừng')->assertSee('>Chọn</button>', false)->assertDontSee('Giữ chỗ 10 phút')->assertDontSee('Nhiều chặng');
        $this->get($base.'&trip_type=ROUND_TRIP&return_date=2030-01-13')->assertOk()->assertSee('round-trip-badge', false)->assertSee('Khứ hồi');
    }

    public function test_preview_quotes_without_holding_and_rejects_multiple_passengers(): void
    {
        $url = '/trip/select?'.http_build_query(['flights' => $this->flights[0], 'fare_class' => 'ECONOMY', 'trip_type' => 'ONE_WAY', 'passenger_count' => 1]);
        $preview = $this->getJson($url)->assertOk()->assertJsonStructure(['html']);
        $this->assertStringContainsString('Nội Bài', $preview->json('html'));
        $this->assertStringContainsString('1.500.000', $preview->json('html'));
        $this->assertStringContainsString('data-trip-preview-continue', $preview->json('html'));
        $this->assertDatabaseCount('reservations', 0);
        $this->getJson(str_replace('passenger_count=1', 'passenger_count=2', $url))->assertUnprocessable();
        $page = $this->get($url)->assertOk();
        $input = $this->details($page->viewData('continueUrl'));
        $input['passengers'][] = $input['passengers'][0];
        $this->actingAs(User::factory()->create())->post('/trip/passengers', $input)->assertSessionHasErrors('passengers');
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_one_way_selection_opens_passengers_before_creating_any_booking_and_encrypts_details(): void
    {
        $this->actingAs(User::factory()->create());
        DB::table('inventory')->whereIn('flight_id', [$this->flights[1], $this->flights[2]])->update(['capacity' => 1]);
        $response = $this->select([$this->flights[1], $this->flights[2]])->assertOk()->assertViewIs('trip-preview');
        $url = $response->viewData('continueUrl');
        $this->get($url)->assertOk()->assertSee('Thông tin hành khách')->assertSee('Tóm tắt chuyến đi')->assertDontSee('Chuyến về');
        $this->assertDatabaseCount('bookings', 0);
        $input = $this->details($url);
        $post = $this->post('/trip/passengers', $input)->assertRedirect();
        $id = basename(parse_url($post->headers->get('Location'), PHP_URL_PATH));
        $this->assertDatabaseCount('bookings', 2);
        $r = DB::table('reservations')->find($id);
        $this->assertSame('ONE_WAY', $r->trip_type);
        $this->assertSame(2, $r->outbound_count);
        $this->assertSame(1500000, $r->total_amount);
        $this->assertStringNotContainsString('contact@example.test', $r->contact_details);
        $this->assertSame('contact@example.test', TripDetails::decode($r->contact_details)['email']);
        $p = DB::table('reservation_passengers')->where('reservation_id', $id)->where('position', 1)->first();
        $this->assertSame('Nguyen Minh Anh', $p->name);
        $this->assertStringNotContainsString('P12345678', $p->details);
        $this->assertSame('P12345678', TripDetails::decode($p->details)['document_number']);
        $this->get('/reservations/'.$id)->assertOk()->assertSee('Thanh toán chuyến đi')->assertSee('contact@example.test');
        $this->assertDatabaseCount('checkout_sessions', 0);
        $this->post('/trip/passengers', $input)->assertRedirect('/reservations/'.$id);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_round_trip_selects_return_and_creates_one_payment_for_all_connected_legs(): void
    {
        config(['payments.mode' => 'mock', 'payments.vnpay.enabled' => true, 'payments.vnpay.tmn_code' => 'TRIPTEST', 'payments.vnpay.hash_secret' => 'trip-test-secret']);
        $user = User::factory()->create();
        $this->actingAs($user);
        $response = $this->select([$this->flights[1], $this->flights[2]], 'ROUND_TRIP')->assertOk()->assertSee('Chọn lượt về');
        parse_str(parse_url($response->viewData('continueUrl'), PHP_URL_QUERY), $query);
        $this->assertSame('SGN', $query['origin']);
        $this->get($response->viewData('continueUrl'))->assertOk()->assertSee('Đã chọn lượt đi')->assertSee('Chọn lượt về');
        $choice = $this->select([$this->flights[7], $this->flights[8]], 'ROUND_TRIP', $query['outbound'])->assertOk()->assertSee('Chuyến về');
        $url = $choice->viewData('continueUrl');
        $this->get($url)->assertSee('Chuyến đi')->assertSee('Chuyến về');
        $post = $this->post('/trip/passengers', $this->details($url))->assertRedirect();
        $id = basename(parse_url($post->headers->get('Location'), PHP_URL_PATH));
        $r = DB::table('reservations')->find($id);
        $this->assertSame('ROUND_TRIP', $r->trip_type);
        $this->assertSame(2, $r->outbound_count);
        $this->assertSame(3200000, $r->total_amount);
        $session = app(ReservationService::class)->pay($user, $id, 'VNPAY', '127.0.0.1');
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, app(OutboxRelay::class)->drain());
        }
        $this->post('/payments/mock/'.$session->intent_id, ['result' => 'approve'])->assertRedirect('/reservations/'.$id);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, app(OutboxRelay::class)->drain());
        }
        $this->assertDatabaseCount('tickets', 4);
        $this->assertSame(1, DB::connection('finance')->table('checkout_intents')->count());
        $this->assertSame(3200000, (int) DB::connection('finance')->table('payments')->sum('amount'));
    }

    public function test_forgery_expiry_wrong_connections_and_removed_multicity_are_rejected(): void
    {
        $this->get('/trip/passengers?selection=forged')->assertUnprocessable();
        $this->select([$this->flights[0], $this->flights[2]])->assertUnprocessable();
        $response = $this->select([$this->flights[0]])->assertOk()->assertViewIs('trip-preview');
        $url = $response->viewData('continueUrl');
        $this->travel(31)->minutes();
        $this->get($url)->assertStatus(409);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/reservations', ['trip_type' => 'MULTI_CITY'])->assertUnprocessable();
        $this->get('/plan')->assertRedirect('/?trip_type=ONE_WAY&passenger_count=1');
    }

    public function test_changed_price_requires_review_and_failed_last_leg_rolls_back_the_entire_party(): void
    {
        $this->actingAs(User::factory()->create());
        $url = $this->select([$this->flights[1], $this->flights[2]])->viewData('continueUrl');
        $input = $this->details($url);
        DB::table('fare_classes')->where('flight_id', $this->flights[2])->update(['price' => 900000]);
        $this->post('/trip/passengers', $input)->assertRedirect();
        $this->assertDatabaseCount('reservations', 0);
        $new = $this->details($url);
        DB::table('inventory')->where('flight_id', $this->flights[2])->update(['capacity' => 0]);
        $this->post('/trip/passengers', $new)->assertStatus(409);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_guest_draft_is_encrypted_and_restored_after_login_without_booking(): void
    {
        $url = $this->select([$this->flights[0]])->viewData('continueUrl');
        $input = $this->details($url);
        $this->post('/trip/passengers', $input)->assertRedirect('/login');
        $draft = session('trip_draft');
        $intended = session('url.intended');
        $this->assertStringNotContainsString('P12345678', $draft['input']);
        $user = User::factory()->create(['password' => 'WorkflowPassword2026!']);
        $this->post('/login', ['email' => $user->email, 'password' => 'WorkflowPassword2026!'])->assertRedirect($intended);
        $this->get($intended)->assertOk()->assertSee('P12345678')->assertSee('contact@example.test');
        $this->assertDatabaseCount('bookings', 0);
    }
}
