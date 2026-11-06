<?php

namespace Tests\Feature;

use App\Modules\Flights\FlightSearch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GlobalFlightRangesTest extends TestCase
{
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2030-01-10 00:00:00', 'UTC'));
        foreach (['CXR', 'PQC', 'DLI'] as $code) {
            DB::connection('ops')->table('airports')->insertOrIgnore(['code' => $code, 'name' => $code, 'city' => $code, 'timezone' => 'Asia/Ho_Chi_Minh']);
        }
        $this->ids[] = $this->flight('HAN', 'DAD', '2030-01-11 07:00', 60, 100000, 500000, 'VN');
        $this->ids[] = $this->flight('DAD', 'CXR', '2030-01-11 09:30', 60, 600000, 200000, 'VJ');
        $this->ids[] = $this->flight('CXR', 'SGN', '2030-01-11 12:00', 60, 300000, 700000, 'QH');
        $this->ids[] = $this->flight('SGN', 'PQC', '2030-01-11 14:00', 30, 50000, 150000, 'VU');
        $this->ids[] = $this->flight('HAN', 'SGN', '2030-01-11 16:00', 120, 400000, 800000, 'VN');
        $this->ids[] = $this->flight('SGN', 'DLI', '2030-01-13 06:30', 45, 40000, 250000, 'VU');
    }

    private function flight(string $from, string $to, string $time, int $minutes, int $economy, int $business, string $carrier): int
    {
        $ops = DB::connection('ops');
        $plane = $ops->table('aircraft')->insertGetId(['registration' => 'VN-GLOBAL-'.count($this->ids).'-'.$ops->table('aircraft')->count(), 'type' => 'A320', 'capacity' => 60]);
        $departure = Carbon::parse($time, 'Asia/Ho_Chi_Minh')->utc();
        $id = $ops->table('flights')->insertGetId(['number' => $carrier.(8500 + $plane), 'carrier_code' => $carrier, 'origin' => $from, 'destination' => $to, 'departure' => $departure, 'arrival' => $departure->copy()->addMinutes($minutes), 'aircraft_id' => $plane, 'status' => 'SCHEDULED', 'gate' => 'A1']);
        DB::table('inventory')->insert(['flight_id' => $id, 'capacity' => 60, 'price' => $economy]);
        foreach (['ECONOMY' => $economy, 'BUSINESS' => $business] as $code => $price) {
            DB::table('fare_classes')->insert(['flight_id' => $id, 'code' => $code, 'name' => $code, 'price' => $price, 'quota' => 30]);
        }

        return $id;
    }

    private function domains(array $filters = []): array
    {
        return app(FlightSearch::class)->search($filters)['facets']['ranges'];
    }

    public function test_domains_cover_all_routes_dates_carriers_and_valid_connections_not_four_leg_paths(): void
    {
        // The most expensive valid path has a common BUSINESS fare: 500k+200k+700k.
        // Selecting the highest fare independently on each leg would give an invalid 1.8m.
        $expected = ['price' => ['min' => 40000, 'max' => 1400000], 'duration' => ['min' => 30, 'max' => 360], 'layover' => ['min' => 0, 'max' => 180]];
        $this->assertSame($expected, $this->domains());
        foreach ([['origin' => 'HAN', 'destination' => 'SGN', 'date' => '2030-01-11', 'carriers' => ['VN'], 'stops' => ['0'], 'fare_classes' => ['ECONOMY']], ['origin' => 'SGN', 'destination' => 'DLI', 'date' => '2030-01-13'], ['origin' => 'DLI', 'destination' => 'PQC', 'price_max' => 1, 'layover_min' => 1000]] as $filters) {
            $this->assertSame($expected, $this->domains($filters));
        }
    }

    public function test_domains_refresh_after_fare_or_schedule_changes_and_ignore_past_cancelled_flights(): void
    {
        $this->assertSame(1400000, $this->domains()['price']['max']);
        DB::table('fare_classes')->where('flight_id', $this->ids[2])->where('code', 'BUSINESS')->update(['price' => 1700000]);
        $this->assertSame(2400000, $this->domains()['price']['max']);
        $cancelled = $this->flight('PQC', 'HAN', '2030-01-12 08:00', 400, 99000000, 99900000, 'AS');
        DB::connection('ops')->table('flights')->where('id', $cancelled)->update(['status' => 'CANCELLED']);
        $this->assertSame(2400000, $this->domains()['price']['max']);
        $this->flight('PQC', 'HAN', '2030-01-09 08:00', 400, 99000000, 99900000, 'AS');
        $this->assertSame(2400000, $this->domains()['price']['max']);
        $this->flight('PQC', 'HAN', '2030-01-15 08:00', 20, 30000, 200000, 'AS');
        $this->assertSame(30000, $this->domains()['price']['min']);
        $this->assertSame(20, $this->domains()['duration']['min']);
    }

    public function test_rendered_endpoints_stay_global_even_when_requested_filter_is_outside_data(): void
    {
        $response = $this->get('/?origin=HAN&destination=SGN&date=2030-01-11&carriers[0]=VN&price_max=1')->assertOk();
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/id="price-min"[^>]*min="40000"[^>]*max="1400000"/', $html);
        $this->assertStringContainsString('Khoảng lọc nằm ngoài dữ liệu hiện có', $html);
        $this->assertSame(0, $response->viewData('flights')->total());
    }
}
