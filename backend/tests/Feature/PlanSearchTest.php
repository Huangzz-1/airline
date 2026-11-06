<?php

namespace Tests\Feature;

use App\Modules\Flights\FlightCatalog;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanSearchTest extends TestCase
{
    public function test_new_search_has_empty_airports_and_legacy_planner_redirects(): void
    {
        $this->get('/')->assertOk()->assertSee('Chọn điểm đi trước')->assertSee('Đặt chuyến đi')->assertDontSee('Nhiều chặng');
        $this->get('/plan')->assertRedirect('/?trip_type=ONE_WAY&passenger_count=1');
    }

    public function test_destinations_follow_active_directed_routes_and_include_connections(): void
    {
        $ops = DB::connection('ops');
        $ops->table('routes')->where('origin', 'HAN')->where('destination', 'DAD')->update(['active' => false]);
        $ops->table('routes')->insert(['origin' => 'SGN', 'destination' => 'DAD', 'active' => true, 'duration_minutes' => 90, 'distance_km' => 600]);
        $network = app(FlightCatalog::class)->destinationNetwork();
        $this->assertSame(['DAD', 'SGN'], $network['HAN']);
        $this->assertSame(['DAD'], $network['SGN']);
        $this->get('/')->assertOk()->assertViewHas('destinationNetwork', $network);
    }

    public function test_explicit_flight_link_opens_information_and_multicity_is_removed(): void
    {
        $flight = DB::connection('ops')->table('flights')->where('origin', 'HAN')->where('destination', 'SGN')->where('departure', '>', now())->first();
        $this->assertNotNull($flight);
        $legacy = $this->get('/plan?trip_type=ONE_WAY&flight_id='.$flight->id)->assertRedirect();
        $selected = $this->get($legacy->headers->get('Location'))->assertOk()->assertSee('Thông tin chuyến bay');
        $this->get($selected->viewData('continueUrl'))->assertOk()->assertSee('Thông tin hành khách')->assertDontSee('Chuyến về');
        $this->getJson('/plan?trip_type=MULTI_CITY')->assertUnprocessable();
    }
}
