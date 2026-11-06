<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Booking\ReservationService;
use App\Modules\Flights\FlightSearch;
use App\Modules\Operations\OperationsService;
use Carbon\Carbon;
use Database\Seeders\MarketplaceFlightSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiAirlineFlightsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2030-01-10 00:00:00', 'UTC'));
    }

    private function seedFlights(int $days = 1): array
    {
        return (new MarketplaceFlightSeeder)->generate(Carbon::parse('2030-01-11', 'Asia/Ho_Chi_Minh'), $days);
    }

    private function searchResults(array $filters = [])
    {
        return app(FlightSearch::class)->search($filters + ['origin' => 'HAN', 'destination' => 'SGN', 'date' => '2030-01-11'])['flights'];
    }

    public function test_fixture_creates_four_carriers_valid_rotations_and_preserves_existing_bookings_on_repeat(): void
    {
        $report = $this->seedFlights(2);
        $this->assertSame(144, $report['created']);
        $ops = DB::connection('ops');
        $flights = $ops->table('flights')->where('demo_source', MarketplaceFlightSeeder::SOURCE)->orderBy('departure')->get();
        $this->assertSame(16, $flights->pluck('aircraft_id')->unique()->count());
        $this->assertSame(['QH', 'VJ', 'VN', 'VU'], $flights->pluck('carrier_code')->unique()->sort()->values()->all());
        foreach ($flights->groupBy('aircraft_id') as $rotation) {
            $previous = null;
            foreach ($rotation as $f) {
                if ($previous) {
                    $this->assertSame($previous->destination, $f->origin);
                    $this->assertTrue(Carbon::parse($previous->arrival)->addMinutes(60)->lte(Carbon::parse($f->departure)));
                }
                $this->assertGreaterThanOrEqual(60, Carbon::parse($f->departure)->diffInMinutes(Carbon::parse($f->arrival)));
                $previous = $f;
            }
        }
        $first = $flights->first();
        $u = User::factory()->create();
        $pnr = app(ReservationService::class)->hold($u, ['idempotency_key' => (string) Str::uuid(), 'trip_type' => 'ONE_WAY', 'outbound_count' => 1, 'passengers' => [['name' => 'Nguyen Van An']], 'segments' => [['flight_id' => $first->id, 'fare_class' => 'ECONOMY']]]);
        DB::table('fare_classes')->where('flight_id', $first->id)->where('code', 'ECONOMY')->update(['price' => 777000]);
        $again = $this->seedFlights(2);
        $this->assertSame(0, $again['created']);
        $this->assertSame(144, $again['preserved']);
        $this->assertSame(777000, DB::table('fare_classes')->where('flight_id', $first->id)->where('code', 'ECONOMY')->value('price'));
        $this->assertSame(1, DB::table('bookings')->where('reservation_id', $pnr->id)->count());
        $this->assertSame(144, DB::table('inventory')->whereIn('flight_id', $flights->pluck('id'))->count());
    }

    public function test_carrier_and_stop_filters_count_actual_itineraries_and_show_their_airlines_in_preview(): void
    {
        $this->seedFlights();
        $this->assertSame(8, $this->searchResults(['stops' => ['0']])->total());
        $this->assertSame(2, $this->searchResults(['stops' => ['0'], 'carriers' => ['VN']])->total());
        $this->assertSame(4, $this->searchResults(['stops' => ['0'], 'carriers' => ['VN', 'VJ']])->total());
        foreach (['0', '1', '2'] as $stops) {
            $result = app(FlightSearch::class)->search(['origin' => 'HAN', 'destination' => 'SGN', 'date' => '2030-01-11', 'carriers' => ['VN'], 'stops' => [$stops]]);
            $this->assertGreaterThan(0, $result['flights']->total());
            $this->assertSame($result['flights']->total(), $result['facets']['stops'][(int) $stops]['count']);
            foreach ($result['flights'] as $f) {
                $this->assertSame(['VN'], $f->carrier_codes);
                $this->assertSame((int) $stops, $f->stop_count);
                $this->assertSame($f->price, array_sum(array_map(fn ($leg) => $leg->all_fares->firstWhere('code', $f->selected_fare)->price, $f->legs)));
                foreach (array_slice($f->legs, 1) as $i => $leg) {
                    $this->assertSame($f->legs[$i]->destination, $leg->origin);
                    $gap = Carbon::parse($f->legs[$i]->arrival)->diffInMinutes(Carbon::parse($leg->departure));
                    $this->assertGreaterThanOrEqual(60, $gap);
                    $this->assertLessThanOrEqual(360, $gap);
                }
            }
        }
        $query = http_build_query(['origin' => 'HAN', 'destination' => 'SGN', 'date' => '2030-01-11', 'carriers' => ['VN'], 'stops' => ['2']]);
        $page = $this->get('/?'.$query)->assertOk()->assertSee('Vietnam Airlines')->assertSee('Vietjet Air')->assertSee('Lịch bay mô phỏng')->assertSee('chuyến');
        $flight = $page->viewData('flights')->first();
        $this->get('/trip/select?'.http_build_query(['flights' => implode(',', array_column($flight->legs, 'id')), 'fare_class' => 'ECONOMY', 'trip_type' => 'ONE_WAY', 'passenger_count' => 1]))
            ->assertOk()->assertSee('Vietnam Airlines')->assertSee('Nối chuyến tại')->assertSee('DAD')->assertSee('CXR');
        $this->getJson('/?carriers[0]=ZZ')->assertUnprocessable();
    }

    public function test_mixed_carriers_require_selecting_every_operating_carrier_and_are_marked_self_transfer(): void
    {
        $this->seedFlights();
        $all = $this->searchResults(['stops' => ['1'], 'per_page' => 100]);
        $mixed = collect($all->items())->first(fn ($f) => $f->self_transfer);
        $this->assertNotNull($mixed);
        $selected = $this->searchResults(['stops' => ['1'], 'carriers' => ['VN']]);
        $this->assertFalse(collect($selected->items())->contains(fn ($f) => $f->self_transfer));
        $this->get('/?'.http_build_query(['origin' => 'HAN', 'destination' => 'SGN', 'date' => '2030-01-11', 'stops' => ['1']]))->assertOk()->assertSee('Tự nối chuyến');
    }

    public function test_scheduling_infers_known_carrier_from_flight_number_and_disabled_routes_are_not_reenabled(): void
    {
        $ops = DB::connection('ops');
        $route = $ops->table('routes')->where('origin', 'HAN')->where('destination', 'SGN')->first();
        $u = User::factory()->create(['role' => 'operations', 'airport_code' => 'HAN']);
        $f = app(OperationsService::class)->execute($u, 'schedule', ['number' => 'VN8301', 'route_id' => $route->id, 'aircraft_id' => 1, 'departure' => now()->addDays(2)->toIso8601String(), 'arrival' => now()->addDays(2)->addHours(3)->toIso8601String(), 'sale_price' => 1500000, 'gate' => 'A1']);
        $this->assertSame('VN', $f->carrier_code);
        $ops->table('routes')->where('id', $route->id)->update(['active' => false]);
        try {
            $this->seedFlights();
            $this->fail('Inactive routes must be preserved');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ngừng hoạt động', $e->getMessage());
        }
        $this->assertSame(0, $ops->table('flights')->where('demo_source', MarketplaceFlightSeeder::SOURCE)->count());
        $this->assertFalse((bool) $ops->table('routes')->find($route->id)->active);
    }
}
