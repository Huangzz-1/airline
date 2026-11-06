<?php

namespace Tests\Feature;

use App\Modules\Flights\FlightSearch;
use Carbon\Carbon;
use Database\Seeders\MarketplaceFlightSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FlightRangeFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2030-01-10 00:00:00', 'UTC'));
        DB::connection('ops')->table('routes')->where('origin', 'HAN')->where('destination', 'DAD')->update(['duration_minutes' => 90]);
        (new MarketplaceFlightSeeder)->generate(Carbon::parse('2030-01-11', 'Asia/Ho_Chi_Minh'), 1);
    }

    private function filters(array $extra = []): array
    {
        return $extra + ['origin' => 'HAN', 'destination' => 'SGN', 'date' => '2030-01-11', 'carriers' => ['VN']];
    }

    private function searchRange(array $extra = [])
    {
        return $this->getJson('/?'.http_build_query($this->filters($extra)));
    }

    public function test_price_and_journey_ranges_apply_both_inclusive_bounds_to_the_same_offer(): void
    {
        $this->searchRange()->assertOk()->assertJsonPath('total', 4);
        $this->searchRange(['price_min' => 1300000, 'price_max' => 1600000])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.stop_count', 2)->assertJsonPath('data.0.price', 1350000);
        $this->searchRange(['duration_min' => 200, 'duration_max' => 300])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.duration_minutes', 275);
        $this->searchRange(['duration_min' => 405, 'duration_max' => 405])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.stop_count', 2);
        $this->searchRange(['duration_max' => 135, 'price_max' => 1800000])->assertOk()->assertJsonPath('total', 0);
        $this->searchRange(['price_min' => 1300000, 'price_max' => 1600000, 'fare_classes' => ['BUSINESS']])->assertOk()->assertJsonPath('total', 0);
    }

    public function test_layover_is_total_waiting_time_and_direct_flights_have_zero_wait(): void
    {
        $this->searchRange(['layover_min' => 0, 'layover_max' => 0])->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.layover_minutes', 0);
        $this->searchRange(['layover_min' => 95, 'layover_max' => 95])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.stop_count', 1)->assertJsonPath('data.0.layover_minutes', 95);
        // 90 minutes in Da Nang + 95 in Cam Ranh, not just the longest stop.
        $this->searchRange(['layover_min' => 180, 'layover_max' => 190])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.stop_count', 2)->assertJsonPath('data.0.layover_minutes', 185);
        $this->searchRange(['layover_min' => 180, 'layover_max' => 190, 'duration_max' => 300])->assertOk()->assertJsonPath('total', 0);
    }

    public function test_range_domains_are_global_for_empty_results_and_other_routes(): void
    {
        $base = app(FlightSearch::class)->search($this->filters());
        $empty = app(FlightSearch::class)->search($this->filters(['price_max' => 1, 'duration_min' => 1000, 'layover_min' => 1000]));
        $this->assertSame(0, $empty['flights']->total());
        $this->assertSame($base['facets']['ranges'], $empty['facets']['ranges']);
        $other = app(FlightSearch::class)->search($this->filters(['origin' => 'PQC', 'destination' => 'SGN', 'fare_classes' => ['ECONOMY']]));
        $this->assertSame($base['facets']['ranges'], $other['facets']['ranges']);
        $this->get('/?'.http_build_query($this->filters(['origin' => 'PQC', 'destination' => 'SGN', 'fare_classes' => ['ECONOMY']])))->assertOk()
            ->assertSee('data-flight-range="layover"', false)->assertSee('data-flight-range="duration"', false)->assertSee('data-flight-range="price"', false)
            ->assertSee('Tổng thời gian chờ; bay thẳng là 0 phút.');
    }

    public function test_invalid_or_reversed_ranges_are_rejected_and_fragments_preserve_values(): void
    {
        foreach ([['price_min' => 100, 'price_max' => 99], ['duration_min' => 300, 'duration_max' => 100], ['layover_min' => 120, 'layover_max' => 60], ['duration_min' => -1], ['layover_max' => 1441], ['layover_min' => '1.5']] as $invalid) {
            $this->searchRange($invalid)->assertUnprocessable();
        }
        $this->get('/?'.http_build_query($this->filters(['layover_min' => 60, 'layover_max' => 120])), ['X-Flight-Results' => '1'])->assertOk()->assertJsonPath('total', 1);
        $this->get('/?'.http_build_query($this->filters(['price_max' => 1])))->assertOk()->assertSee('Không tìm thấy chuyến bay phù hợp');
    }
}
