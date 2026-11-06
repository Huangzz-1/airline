<?php

namespace App\Modules\Flights;

use App\Modules\Boarding\SeatMap;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FlightSearch
{
    public const PERIODS = ['night' => ['Buổi đêm', '00:00–06:00'], 'morning' => ['Buổi sáng', '06:00–12:00'], 'afternoon' => ['Buổi chiều', '12:00–18:00'], 'evening' => ['Buổi tối', '18:00–24:00']];

    public const SORTS = ['recommended' => 'Đề xuất', 'price_asc' => 'Giá thấp nhất', 'price_desc' => 'Giá cao nhất', 'duration' => 'Bay nhanh nhất', 'departure' => 'Khởi hành sớm nhất', 'arrival' => 'Đến sớm nhất'];

    public function search(array $filters): array
    {
        $filters += ['sort' => 'recommended', 'passenger_count' => 1];
        $filters['sort'] ??= 'recommended';
        $filters['passenger_count'] = (int) ($filters['passenger_count'] ?: 1);
        $query = DB::connection('ops')->table('flights as f')->join('aircraft as a', 'a.id', '=', 'f.aircraft_id')
            ->whereIn('f.status', ['SCHEDULED', 'BOARDING', 'DELAYED'])->where('f.departure', '>', now())
            ->select('f.*', 'a.type as aircraft_type');
        $legs = $this->hydrate($query->orderBy('f.departure')->get());
        $all = $this->journeys($legs, $filters);
        $base = $all->filter(fn ($f) => empty($filters['date']) || $f->departure_day === $filters['date']);
        $fareOptions = $base->flatMap(fn ($f) => $f->all_fares)->unique('code')->sortBy('price')->values();
        $facets = [
            'total' => $base->count(), 'fares' => $fareOptions,
            'aircraft' => $base->flatMap(fn ($f) => $f->aircraft_types)->countBy()->sortKeys(),
            'price_max' => max(100000, (int) $base->flatMap(fn ($f) => $f->all_fares)->max('price')),
            'duration_max' => max(60, (int) $base->max('duration_minutes')),
        ];
        $facets['ranges'] = $this->globalRanges($legs);
        $facets['stops'] = collect([0, 1, 2])->mapWithKeys(function ($stops) use ($base, $filters) {
            $withoutStops = $filters;
            unset($withoutStops['stops']);
            $rows = $base->where('stop_count', $stops)->map(fn ($f) => $this->filterFlight($f, $withoutStops))->filter()->filter(fn ($f) => $f->remaining >= $filters['passenger_count']);

            return [$stops => ['count' => $rows->count(), 'price' => $rows->min('price')]];
        })->all();
        $withoutCarriers = $filters;
        unset($withoutCarriers['carriers']);
        $carrierOptions = $base->map(fn ($f) => $this->filterFlight($f, $withoutCarriers))->filter()->filter(fn ($f) => $f->remaining >= $filters['passenger_count']);
        $facets['carriers'] = $base->flatMap(fn ($f) => $f->carrier_codes)->unique()->sort()->map(fn ($code) => [
            'code' => $code, 'name' => Carrier::name($code),
            'count' => $carrierOptions->filter(fn ($f) => in_array($code, $f->carrier_codes, true))->count(),
        ])->values();
        $eligible = $all->map(fn ($f) => $this->filterFlight($f, $filters))->filter()->values();
        $matching = $eligible->filter(fn ($f) => empty($filters['date']) || $f->departure_day === $filters['date']);
        $sorted = $matching->sort(function ($a, $b) use ($filters) {
            $key = fn ($f) => match ($filters['sort']) {
                'price_asc' => [$f->price, $f->departure, $f->id],
                'price_desc' => [-$f->price, $f->departure, $f->id],
                'duration' => [$f->duration_minutes, $f->price, $f->departure, $f->id],
                'arrival' => [$f->arrival, $f->price, $f->id],
                'departure' => [$f->departure, $f->price, $f->id],
                default => [$f->remaining >= $filters['passenger_count'] ? 0 : 1, $f->departure, $f->price, $f->id],
            };

            return $key($a) <=> $key($b);
        })->values();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $flights = new LengthAwarePaginator($sorted->forPage($page, 8)->values(), $sorted->count(), 8, $page, ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->except('page')]);
        $reference = Carbon::parse($filters['date'] ?? now('Asia/Ho_Chi_Minh')->toDateString(), 'Asia/Ho_Chi_Minh')->startOfDay();
        $start = $reference->copy()->subDays(2)->max(now('Asia/Ho_Chi_Minh')->startOfDay());
        $dates = [];
        for ($i = 0; $i < 5; $i++) {
            $day = $start->copy()->addDays($i);
            $options = $eligible->filter(fn ($f) => $f->departure_day === $day->toDateString() && $f->remaining >= $filters['passenger_count']);
            $dates[] = ['day' => $day->toDateString(), 'label' => ($day->dayOfWeek === 0 ? 'CN' : 'Thứ '.($day->dayOfWeek + 1)).', '.$day->format('d/m'), 'price' => $options->min('price')];
        }

        foreach ($facets['ranges'] as $rangeKey => $bounds) {
            if (isset($filters[$rangeKey.'_min']) && (int) $filters[$rangeKey.'_min'] === $bounds['min']) {
                unset($filters[$rangeKey.'_min']);
            }
            if (isset($filters[$rangeKey.'_max']) && (int) $filters[$rangeKey.'_max'] === $bounds['max']) {
                unset($filters[$rangeKey.'_max']);
            }
        }

        return compact('flights', 'filters', 'facets', 'dates');
    }

    private function globalRanges(Collection $legs): array
    {
        // Every visible future leg, all routes/dates/fares, and every valid path of
        // up to three legs. Do not materialize every itinerary just for six bounds.
        $signature = $legs->map(fn ($f) => [$f->id, $f->origin, $f->destination, $f->departure, $f->arrival,
            $f->all_fares->map(fn ($fare) => [$fare->code, $fare->price])->all(),
        ])->all();

        return Cache::remember('global-flight-ranges-v1:'.hash('sha256', json_encode($signature, JSON_THROW_ON_ERROR)), 300, function () use ($legs) {
            $ranges = array_fill_keys(['price', 'duration', 'layover'], ['min' => null, 'max' => null]);
            $byOrigin = $legs->groupBy('origin');
            $departures = $arrivals = $fares = $connections = [];
            foreach ($legs as $f) {
                $departures[$f->id] = strtotime($f->departure.' UTC');
                $arrivals[$f->id] = strtotime($f->arrival.' UTC');
                $fares[$f->id] = $f->all_fares->pluck('price', 'code')->all();
            }
            foreach ($legs as $f) {
                $connections[$f->id] = [];
                foreach ($byOrigin->get($f->destination, collect()) as $next) {
                    $gap = $departures[$next->id] - $arrivals[$f->id];
                    if ($gap < 3600) {
                        continue;
                    }
                    if ($gap > 21600) {
                        break; // Legs and each airport group are sorted by departure.
                    }
                    $connections[$f->id][] = $next;
                }
            }
            $visit = function ($last, $start, $airborne, $visited, $prices, $depth) use (&$visit, &$ranges, $arrivals, $connections, $fares) {
                $duration = (int) (($arrivals[$last->id] - $start) / 60);
                foreach (['price' => [min($prices), max($prices)], 'duration' => [$duration, $duration], 'layover' => [max(0, $duration - $airborne), max(0, $duration - $airborne)]] as $key => [$min, $max]) {
                    $ranges[$key]['min'] = $ranges[$key]['min'] === null ? $min : min($ranges[$key]['min'], $min);
                    $ranges[$key]['max'] = $ranges[$key]['max'] === null ? $max : max($ranges[$key]['max'], $max);
                }
                if ($depth === 3) {
                    return;
                }
                foreach ($connections[$last->id] as $next) {
                    if (isset($visited[$next->destination]) || $arrivals[$next->id] - $start > 86400) {
                        continue;
                    }
                    $common = array_intersect_key($prices, $fares[$next->id]);
                    if (! $common) {
                        continue;
                    }
                    foreach ($common as $code => &$price) {
                        $price += $fares[$next->id][$code];
                    }
                    unset($price);
                    $visit($next, $start, $airborne + $next->duration_minutes, $visited + [$next->destination => true], $common, $depth + 1);
                }
            };
            foreach ($legs as $first) {
                $visit($first, $departures[$first->id], $first->duration_minutes, [$first->origin => true, $first->destination => true], $fares[$first->id], 1);
            }

            return array_map(fn ($range) => ['min' => (int) $range['min'], 'max' => (int) $range['max']], $ranges);
        });
    }

    private function hydrate(Collection $flights): Collection
    {
        $ids = $flights->pluck('id');
        $stocks = DB::table('inventory')->whereIn('flight_id', $ids)->get()->keyBy('flight_id');
        // Include inactive economy records so their closure cannot become a fallback fare.
        $fares = DB::table('fare_classes')->whereIn('flight_id', $ids)->get()->groupBy('flight_id');
        $usage = DB::table('bookings')->whereIn('flight_id', $ids)->where(fn ($q) => $q
            ->whereIn('status', ['PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'EXCHANGE_PENDING'])
            ->orWhere(fn ($held) => $held->where('status', 'HELD')->where('expires_at', '>', now())))
            ->select('flight_id', 'fare_class')->selectRaw('COUNT(*) as occupied')->groupBy('flight_id', 'fare_class')->get()->groupBy('flight_id');

        return $flights->map(function ($f) use ($stocks, $fares, $usage) {
            $stock = $stocks->get($f->id);
            if (! $stock || $stock->currency !== 'VND') {
                return null;
            }
            $occupied = $usage->get($f->id, collect());
            $remaining = $stock->status === 'OPEN' && ! $stock->frozen_change_id ? max(0, $stock->capacity - $occupied->sum('occupied')) : 0;
            $offers = $fares->get($f->id, collect())->filter(fn ($fare) => $fare->active)->map(fn ($fare) => clone $fare)->values();
            if (! $fares->get($f->id, collect())->contains('code', 'ECONOMY')) {
                $offers->push((object) ['code' => 'ECONOMY', 'name' => 'Phổ thông', 'price' => $stock->price, 'quota' => $stock->capacity]);
            }
            foreach ($offers as $fare) {
                $fare->price = (int) $fare->price;
                $fare->remaining = max(0, min($remaining, $fare->quota - $occupied->where('fare_class', $fare->code)->sum('occupied')));
                if ($stock->seat_layout) {
                    $cabin = SeatMap::cabin($fare->code);
                    $used = $occupied->filter(fn ($b) => SeatMap::cabin($b->fare_class) === $cabin)->sum('occupied');
                    $fare->remaining = max(0, min($fare->remaining, count(SeatMap::seats($stock->capacity, $stock->seat_layout, $fare->code)) - $used));
                }
            }
            if ($offers->isEmpty()) {
                return null;
            }
            $departure = Carbon::parse($f->departure)->tz('Asia/Ho_Chi_Minh');
            $arrival = Carbon::parse($f->arrival)->tz('Asia/Ho_Chi_Minh');
            $f->departure_day = $departure->toDateString();
            $f->departure_time = $departure->format('H:i');
            $f->arrival_time = $arrival->format('H:i');
            $f->arrival_day_offset = (int) $departure->copy()->startOfDay()->diffInDays($arrival->copy()->startOfDay(), false);
            $f->departure_period = array_keys(self::PERIODS)[intdiv($departure->hour, 6)];
            $f->arrival_period = array_keys(self::PERIODS)[intdiv($arrival->hour, 6)];
            $f->duration_minutes = max(0, (int) $departure->diffInMinutes($arrival));
            $f->all_fares = $offers;

            return $f;
        })->filter()->values();
    }

    private function filterFlight(object $original, array $filters): ?object
    {
        if (! empty($filters['carriers']) && ! array_intersect($original->carrier_codes, $filters['carriers'])) {
            return null;
        }
        if (! empty($filters['stops']) && ! in_array((string) $original->stop_count, array_map('strval', $filters['stops']), true)) {
            return null;
        }
        if (! empty($filters['aircraft_types']) && ! array_intersect($original->aircraft_types, $filters['aircraft_types'])) {
            return null;
        }
        if (! empty($filters['not_before']) && Carbon::parse($original->departure)->lt(Carbon::parse($filters['not_before']))) {
            return null;
        }
        foreach (['departure_periods' => 'departure_period', 'arrival_periods' => 'arrival_period'] as $filter => $field) {
            if (! empty($filters[$filter]) && ! in_array($original->$field, $filters[$filter], true)) {
                return null;
            }
        }
        foreach (['duration' => 'duration_minutes', 'layover' => 'layover_minutes'] as $key => $field) {
            if ((isset($filters[$key.'_min']) && $filters[$key.'_min'] > $original->$field)
                || (isset($filters[$key.'_max']) && $filters[$key.'_max'] < $original->$field)) {
                return null;
            }
        }
        if (! empty($filters['flight_number']) && ! str_contains(strtoupper($original->number), strtoupper(trim($filters['flight_number'])))) {
            return null;
        }
        $offers = $original->all_fares->filter(fn ($fare) => (empty($filters['fare_classes']) || in_array($fare->code, $filters['fare_classes'], true))
            && (! isset($filters['price_min']) || $fare->price >= $filters['price_min'])
            && (! isset($filters['price_max']) || $fare->price <= $filters['price_max'])
            && (empty($filters['available_only']) || $fare->remaining >= $filters['passenger_count']))->sortBy('price')->values();
        if ($offers->isEmpty()) {
            return null;
        }
        $f = clone $original;
        $preferred = $offers->first(fn ($fare) => $fare->remaining >= $filters['passenger_count']) ?? $offers->first();
        $f->fares = $offers;
        $f->selected_fare = $preferred->code;
        $f->price = $preferred->price;
        $f->remaining = $preferred->remaining;
        unset($f->all_fares);

        return $f;
    }

    private function journeys(Collection $legs, array $filters): Collection
    {
        $origin = $filters['origin'] ?? null;
        $destination = $filters['destination'] ?? null;
        $paths = [];
        $byOrigin = $legs->groupBy('origin');
        $starts = $legs->filter(fn ($f) => (! $origin || $f->origin === $origin));
        $walk = function (array $path, array $visited) use (&$walk, &$paths, $byOrigin, $destination) {
            $last = $path[count($path) - 1];
            if (! $destination || $last->destination === $destination) {
                $paths[] = $path;

                return;
            }
            if (count($path) >= 3) {
                return;
            }
            $arrival = strtotime($last->arrival.' UTC');
            foreach ($byOrigin->get($last->destination, collect()) as $next) {
                $gap = (strtotime($next->departure.' UTC') - $arrival) / 60;
                if ($gap < 60 || $gap > 360 || in_array($next->destination, $visited, true)
                    || (strtotime($next->arrival.' UTC') - strtotime($path[0]->departure.' UTC')) > 86400) {
                    continue;
                }
                $walk([...$path, $next], [...$visited, $next->destination]);
            }
        };
        foreach ($starts as $first) {
            // Connections require both endpoints; an unfiltered landing page lists direct flights.
            if (! $origin || ! $destination) {
                if (! $destination || $first->destination === $destination) {
                    $paths[] = [$first];
                }
            } else {
                $walk([$first], [$first->origin, $first->destination]);
            }
        }

        return collect($paths)->map(function ($path) {
            $first = $path[0];
            $last = $path[count($path) - 1];
            $common = $first->all_fares->pluck('code')->all();
            foreach ($path as $leg) {
                $common = array_intersect($common, $leg->all_fares->pluck('code')->all());
            }
            if (! $common) {
                return null;
            }
            $f = clone $first;
            $f->legs = $path;
            $f->carrier_codes = array_values(array_unique(array_column($path, 'carrier_code')));
            $f->carrier_names = array_map(Carrier::name(...), $f->carrier_codes);
            $f->self_transfer = count($f->carrier_codes) > 1;
            $f->is_demo = count(array_filter(array_column($path, 'demo_source'))) > 0;
            $f->journey_key = implode('-', array_column($path, 'id'));
            // A connection must not look like a single bookable flight to API clients.
            $f->id = count($path) > 1 ? $f->journey_key : $first->id;
            $f->stop_count = count($path) - 1;
            $f->via = array_map(fn ($leg) => $leg->destination, array_slice($path, 0, -1));
            $f->destination = $last->destination;
            $f->arrival = $last->arrival;
            $f->arrival_time = $last->arrival_time;
            $f->arrival_period = $last->arrival_period;
            $f->arrival_day_offset = (int) Carbon::parse($first->departure)->tz('Asia/Ho_Chi_Minh')->startOfDay()->diffInDays(Carbon::parse($last->arrival)->tz('Asia/Ho_Chi_Minh')->startOfDay(), false);
            $f->duration_minutes = (int) Carbon::parse($first->departure)->diffInMinutes(Carbon::parse($last->arrival));
            $f->layover_minutes = max(0, $f->duration_minutes - array_sum(array_column($path, 'duration_minutes')));
            $f->aircraft_types = array_values(array_unique(array_column($path, 'aircraft_type')));
            $f->aircraft_type = implode(' / ', $f->aircraft_types);
            $f->number = implode(' / ', array_column($path, 'number'));
            $f->all_fares = collect(array_values($common))->map(function ($code) use ($path) {
                $offers = collect($path)->map(fn ($leg) => $leg->all_fares->firstWhere('code', $code));

                return (object) ['code' => $code, 'name' => $offers->first()->name, 'price' => (int) $offers->sum('price'), 'remaining' => (int) $offers->min('remaining')];
            });

            return $f;
        })->filter()->values();
    }
}
