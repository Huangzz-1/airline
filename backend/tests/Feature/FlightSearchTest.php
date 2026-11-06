<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Booking\BookingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlightSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2030-01-10 00:00:00', 'UTC'));
        DB::connection('ops')->table('routes')->insert(['origin' => 'DAD', 'destination' => 'HAN', 'duration_minutes' => 90, 'distance_km' => 700]);
    }

    private function flight(string $time, int $price, int $duration = 90, string $type = 'A320', ?array $layout = null): int
    {
        $ops = DB::connection('ops');
        $plane = $ops->table('aircraft')->insertGetId(['registration' => 'VN-F'.Str::upper(Str::random(7)), 'type' => $type, 'capacity' => 12, 'seat_layout' => $layout ? json_encode($layout) : null]);
        $departure = Carbon::parse('2030-01-11 '.$time, 'Asia/Ho_Chi_Minh')->utc();
        $id = $ops->table('flights')->insertGetId(['number' => 'AS'.random_int(10000, 99999), 'origin' => 'DAD', 'destination' => 'HAN', 'aircraft_id' => $plane, 'departure' => $departure, 'arrival' => $departure->copy()->addMinutes($duration), 'status' => 'SCHEDULED', 'gate' => 'A1']);
        DB::table('inventory')->insert(['flight_id' => $id, 'capacity' => 12, 'price' => $price, 'seat_layout' => $layout ? json_encode($layout) : null]);
        DB::table('fare_classes')->insert(['flight_id' => $id, 'code' => 'ECONOMY', 'name' => 'Phổ thông', 'quota' => 12, 'price' => $price]);

        return $id;
    }

    private function search(array $filters = [])
    {
        return $this->getJson('/?'.http_build_query($filters + ['origin' => 'DAD', 'destination' => 'HAN']));
    }

    public function test_price_sort_and_filters_apply_before_pagination(): void
    {
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $this->flight(sprintf('%02d:00', $i + 1), 1000000 - $i * 50000);
        }
        $this->search(['sort' => 'price_asc'])->assertOk()->assertJsonPath('total', 10)->assertJsonPath('data.0.id', $ids[9])->assertJsonCount(8, 'data');
        $page = $this->search(['sort' => 'price_asc', 'page' => 2])->assertOk()->assertJsonPath('data.0.id', $ids[1])->json();
        $this->assertStringContainsString('sort=price_asc', $page['prev_page_url']);
        $this->search(['price_max' => 575000])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ids[9]);
        $this->search(['sort' => 'price_desc'])->assertJsonPath('data.0.id', $ids[0]);
    }

    public function test_local_date_departure_arrival_periods_and_duration_are_combined(): void
    {
        $night = $this->flight('00:15', 700000, 60);
        $morning = $this->flight('06:00', 800000, 120);
        $overnight = $this->flight('23:30', 600000, 90);
        $this->search(['date' => '2030-01-11'])->assertJsonPath('total', 3);
        $this->search(['date' => '2030-01-10'])->assertJsonPath('total', 0);
        $this->search(['departure_periods' => ['night'], 'duration_max' => 75])->assertJsonPath('data.0.id', $night)->assertJsonPath('total', 1);
        $this->search(['departure_periods' => ['morning'], 'arrival_periods' => ['morning']])->assertJsonPath('data.0.id', $morning)->assertJsonPath('total', 1);
        $this->search(['departure_periods' => ['evening'], 'arrival_periods' => ['night']])->assertJsonPath('data.0.id', $overnight)->assertJsonPath('data.0.arrival_day_offset', 1);
        $this->search(['departure_periods' => ['night', 'morning']])->assertJsonPath('total', 2);
        $this->search(['sort' => 'duration'])->assertJsonPath('data.0.id', $night);
    }

    public function test_price_and_fare_filters_match_the_same_offer_and_group_link_keeps_fare(): void
    {
        $id = $this->flight('10:00', 700000);
        DB::table('fare_classes')->insert(['flight_id' => $id, 'code' => 'BUSINESS', 'name' => 'Thương gia', 'quota' => 4, 'price' => 2000000]);
        $this->search(['fare_classes' => ['BUSINESS'], 'price_max' => 1000000])->assertJsonPath('total', 0);
        $this->search(['fare_classes' => ['BUSINESS'], 'price_min' => 1500000])->assertJsonPath('total', 1)->assertJsonPath('data.0.price', 2000000)->assertJsonPath('data.0.selected_fare', 'BUSINESS')->assertJsonCount(1, 'data.0.fares');
        $user = User::factory()->create();
        $choice = $this->actingAs($user)->get('/trip/select?flights='.$id.'&trip_type=ONE_WAY&passenger_count=1&fare_class=BUSINESS')->assertOk()->assertViewIs('trip-preview');
        $this->get($choice->viewData('continueUrl'))->assertOk()->assertViewHas('data', fn ($data) => $data['outbound'][0]['fare_class'] === 'BUSINESS' && $data['passenger_count'] === 1);
    }

    public function test_available_filter_checks_quota_cabin_shared_inventory_and_expired_holds(): void
    {
        $layout = ['cabins' => [['code' => 'BUSINESS', 'rows' => 1, 'blocks' => ['AC', 'DF']], ['code' => 'ECONOMY', 'rows' => 2, 'blocks' => ['AB', 'CD']]]];
        $id = $this->flight('11:00', 700000, 90, 'A320', $layout);
        DB::table('fare_classes')->insert(['flight_id' => $id, 'code' => 'BUSINESS', 'name' => 'Thương gia', 'quota' => 12, 'price' => 2000000]);
        $user = User::factory()->create();
        $expired = app(BookingService::class)->hold($user->id, $id, (string) Str::uuid(), 'ECONOMY');
        DB::table('bookings')->where('id', $expired->id)->update(['expires_at' => now()->subMinute()]);
        app(BookingService::class)->hold($user->id, $id, (string) Str::uuid(), 'BUSINESS');
        $this->search(['fare_classes' => ['BUSINESS'], 'available_only' => 1])->assertJsonPath('total', 1)->assertJsonPath('data.0.remaining', 3);
        $this->search(['fare_classes' => ['ECONOMY'], 'available_only' => 1])->assertJsonPath('total', 1)->assertJsonPath('data.0.remaining', 8);
        DB::table('fare_classes')->where('flight_id', $id)->where('code', 'ECONOMY')->update(['quota' => 0]);
        $this->search(['fare_classes' => ['ECONOMY'], 'available_only' => 1])->assertJsonPath('total', 0);
        DB::table('inventory')->where('flight_id', $id)->update(['status' => 'CLOSED']);
        $this->search(['available_only' => 1])->assertJsonPath('total', 0);
    }

    public function test_frozen_and_inactive_fares_are_not_offered_and_aircraft_filter_is_real(): void
    {
        $first = $this->flight('09:00', 800000, 90, 'ATR72');
        $second = $this->flight('13:00', 900000, 100, 'A320');
        $this->search(['aircraft_types' => ['ATR72']])->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $first);
        DB::table('inventory')->where('flight_id', $first)->update(['frozen_change_id' => (string) Str::uuid()]);
        $this->search(['available_only' => 1])->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $second);
        DB::table('fare_classes')->where('flight_id', $second)->update(['active' => false]);
        $this->search(['available_only' => 1])->assertJsonPath('total', 0);
    }

    public function test_html_fragment_dates_and_validation_keep_search_state(): void
    {
        $this->flight('10:00', 700000);
        $this->flight('14:00', 600000);
        $response = $this->get('/?origin=DAD&destination=HAN&date=2030-01-11&price_max=650000', ['X-Flight-Results' => '1'])->assertOk()->assertJsonPath('total', 1);
        $this->assertStringContainsString('flight-filter-panel', $response->json('html'));
        $this->assertStringContainsString('600.000', $response->json('html'));
        $this->get('/?origin=DAD&destination=HAN&date=2030-01-11')->assertOk()->assertViewHas('dates', fn ($dates) => collect($dates)->firstWhere('day', '2030-01-11')['price'] === 600000);
        $this->search(['price_min' => 900000, 'price_max' => 1000])->assertUnprocessable();
        $this->search(['sort' => 'unsafe'])->assertUnprocessable();
        $this->search(['departure_periods' => ['invalid']])->assertUnprocessable();
        $this->search(['passenger_count' => 10])->assertUnprocessable();
        $this->search(['passenger_count' => 2])->assertUnprocessable();
        $this->search(['stops' => ['1']])->assertOk()->assertJsonPath('total', 0);
    }

    public function test_fallback_economy_and_closed_fares_are_consistent_with_the_plan(): void
    {
        $id = $this->flight('10:00', 700000);
        DB::table('fare_classes')->where('flight_id', $id)->delete();
        DB::table('fare_classes')->insert(['flight_id' => $id, 'code' => 'BUSINESS', 'name' => 'Thương gia', 'quota' => 4, 'price' => 2000000]);
        $this->search()->assertJsonPath('data.0.price', 700000)->assertJsonPath('data.0.selected_fare', 'ECONOMY');
        $this->get('/trip/select?flights='.$id.'&trip_type=ONE_WAY&passenger_count=1&fare_class=ECONOMY')->assertOk();
        DB::table('fare_classes')->insert(['flight_id' => $id, 'code' => 'ECONOMY', 'name' => 'Phổ thông', 'quota' => 12, 'price' => 700000, 'active' => false]);
        $this->search()->assertJsonPath('data.0.price', 2000000)->assertJsonPath('data.0.selected_fare', 'BUSINESS');
        $this->get('/trip/select?flights='.$id.'&trip_type=ONE_WAY&passenger_count=1&fare_class=ECONOMY')->assertStatus(409);
    }
}
