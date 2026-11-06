<?php

namespace App\Modules\Flights;

use App\Modules\Boarding\SeatMap;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FlightCatalog
{
    public function get(int $id): object
    {
        return DB::connection('ops')->table('flights')->find($id) ?? abort(404);
    }

    public function airports()
    {
        return DB::connection('ops')->table('airports')->orderBy('code')->get();
    }

    public function destinationNetwork(): array
    {
        $direct = DB::connection('ops')->table('routes')->where('active', true)
            ->whereColumn('origin', '<>', 'destination')->orderBy('origin')->orderBy('destination')
            ->get(['origin', 'destination'])->groupBy('origin')
            ->map(fn ($routes) => $routes->pluck('destination')->unique()->values()->all())->all();
        $network = [];
        foreach (array_keys($direct) as $origin) {
            $frontier = [$origin];
            $seen = [$origin];
            for ($i = 0; $i < 3; $i++) {
                $next = [];
                foreach ($frontier as $airport) {
                    foreach ($direct[$airport] ?? [] as $destination) {
                        if (! in_array($destination, $seen, true)) {
                            $seen[] = $destination;
                            $next[] = $destination;
                        }
                    }
                }
                $frontier = $next;
            }
            $network[$origin] = array_values(array_diff($seen, [$origin]));
            sort($network[$origin]);
        }

        return $network;
    }

    public function search(array $filters)
    {
        return app(FlightSearch::class)->search($filters)['flights'];
    }

    public function options(array $filters): array
    {
        if (empty($filters['origin']) || empty($filters['destination']) || $filters['origin'] === $filters['destination']) {
            return [];
        }
        $day = Carbon::parse($filters['date'], 'Asia/Ho_Chi_Minh')->startOfDay()->utc();
        $flights = DB::connection('ops')->table('flights')->where('origin', $filters['origin'])->where('destination', $filters['destination'])
            ->whereIn('status', ['SCHEDULED', 'DELAYED', 'BOARDING'])->where('departure', '>', now())->where('departure', '>=', $day)->where('departure', '<', $day->copy()->addDay())->orderBy('departure')->limit(80)->get();
        $options = [];
        foreach ($flights as $f) {
            $stock = DB::table('inventory')->where('flight_id', $f->id)->first();
            if (! $stock || $stock->status !== 'OPEN' || $stock->frozen_change_id) {
                continue;
            }
            $active = DB::table('bookings')->where('flight_id', $f->id)->where(fn ($q) => $q->whereIn('status', ['PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'EXCHANGE_PENDING'])->orWhere(fn ($x) => $x->where('status', 'HELD')->where('expires_at', '>', now())))->get(['fare_class']);
            $allFares = DB::table('fare_classes')->where('flight_id', $f->id)->get();
            $fares = $allFares->where('active', true)->values();
            if (! $allFares->contains('code', 'ECONOMY')) {
                $fares->push((object) ['code' => 'ECONOMY', 'name' => 'Phổ thông', 'price' => $stock->price, 'quota' => $stock->capacity]);
            }
            foreach ($fares as $fare) {
                $remaining = min($stock->capacity - $active->count(), $fare->quota - $active->where('fare_class', $fare->code)->count());
                if ($stock->seat_layout) {
                    $cabin = SeatMap::cabin($fare->code);
                    $used = $active->filter(fn ($b) => SeatMap::cabin($b->fare_class) === $cabin)->count();
                    $remaining = min($remaining, count(SeatMap::seats($stock->capacity, $stock->seat_layout, $fare->code)) - $used);
                }
                $options[] = (object) ['flight' => $f, 'fare' => $fare, 'remaining' => max(0, $remaining)];
            }
        }

        return $options;
    }
}
