<?php

namespace Database\Seeders;

use App\Modules\Boarding\SeatMap;
use App\Modules\Booking\FareRules;
use App\Modules\Flights\Carrier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class MarketplaceFlightSeeder
{
    public const SOURCE = 'marketplace-v1';

    public const AIRPORTS = [
        'HAN' => ['Sân bay quốc tế Nội Bài', 'Hà Nội'], 'SGN' => ['Sân bay quốc tế Tân Sơn Nhất', 'TP. Hồ Chí Minh'],
        'DAD' => ['Sân bay quốc tế Đà Nẵng', 'Đà Nẵng'], 'CXR' => ['Sân bay quốc tế Cam Ranh', 'Nha Trang'],
        'PQC' => ['Sân bay quốc tế Phú Quốc', 'Phú Quốc'], 'DLI' => ['Sân bay Liên Khương', 'Đà Lạt'],
    ];

    // Each aircraft returns to its starting airport; all times are Vietnam local time.
    public const ROTATIONS = [
        [
            ['HAN', 'SGN', '06:50', 135, 1850000, 1160], ['SGN', 'HAN', '11:30', 135, 1790000, 1160],
            ['HAN', 'SGN', '17:00', 135, 1990000, 1160], ['SGN', 'HAN', '20:20', 135, 1690000, 1160],
        ],
        [
            ['HAN', 'DAD', '07:30', 90, 600000, 630], ['DAD', 'CXR', '10:30', 65, 400000, 460],
            ['CXR', 'SGN', '13:10', 65, 350000, 310], ['SGN', 'CXR', '15:20', 65, 350000, 310],
            ['CXR', 'DAD', '17:50', 65, 400000, 460], ['DAD', 'HAN', '20:25', 90, 600000, 630],
        ],
        [
            ['SGN', 'DAD', '06:40', 90, 650000, 610], ['DAD', 'SGN', '10:35', 90, 650000, 610],
            ['SGN', 'DAD', '14:00', 90, 700000, 610], ['DAD', 'SGN', '17:10', 90, 600000, 610],
        ],
        [
            ['SGN', 'PQC', '08:10', 65, 750000, 300], ['PQC', 'SGN', '10:20', 65, 750000, 300],
            ['SGN', 'DLI', '13:00', 60, 550000, 215], ['DLI', 'SGN', '15:10', 60, 550000, 215],
        ],
    ];

    public function plan(Carbon $start, int $days): array
    {
        $start = $start->copy()->tz('Asia/Ho_Chi_Minh')->startOfDay();
        $routes = DB::connection('ops')->table('routes')->get()->keyBy(fn ($r) => $r->origin.'-'.$r->destination);
        $rows = [];
        foreach (['VN', 'VJ', 'QH', 'VU'] as $carrierIndex => $carrier) {
            for ($day = 0; $day < $days; $day++) {
                $date = $start->copy()->addDays($day);
                $sequence = 0;
                foreach (self::ROTATIONS as $rotation => $legs) {
                    $previousArrival = null;
                    foreach ($legs as [$origin, $destination, $time, $duration, $price, $distance]) {
                        $sequence++;
                        $route = $routes->get($origin.'-'.$destination);
                        if ($route && ! $route->active) {
                            throw new \RuntimeException('Đường bay '.$origin.' → '.$destination.' đang ngừng hoạt động; không tự bật lại dữ liệu cũ.');
                        }
                        $departure = Carbon::parse($date->toDateString().' '.$time, 'Asia/Ho_Chi_Minh')->addMinutes($carrierIndex * 15)->utc();
                        $arrival = $departure->copy()->addMinutes(max($duration, $route?->duration_minutes ?? 0));
                        if ($previousArrival && $departure->lt($previousArrival->copy()->addMinutes(60))) {
                            throw new \RuntimeException('Thời lượng đường bay hiện có không phù hợp lịch quay đầu mẫu.');
                        }
                        $previousArrival = $arrival;
                        $scale = [100, 80, 92, 86][$carrierIndex];
                        $rows[] = ['number' => $carrier.(8000 + $sequence), 'carrier_code' => $carrier, 'origin' => $origin, 'destination' => $destination,
                            'departure' => $departure, 'arrival' => $arrival, 'rotation' => $rotation, 'duration' => $duration, 'distance' => $distance,
                            'price' => (int) (round(($price * $scale / 100 + ($date->isWeekend() ? 100000 : 0)) / 10000) * 10000),
                        ];
                    }
                }
            }
        }

        return $rows;
    }

    public function generate(Carbon $start, int $days): array
    {
        $plan = $this->plan($start, $days);
        $ops = DB::connection('ops');
        foreach (self::AIRPORTS as $code => [$name, $city]) {
            $ops->table('airports')->insertOrIgnore(['code' => $code, 'name' => $name, 'city' => $city, 'timezone' => 'Asia/Ho_Chi_Minh']);
            $ops->table('airport_resources')->insertOrIgnore(['airport_code' => $code, 'code' => 'A1', 'type' => 'GATE']);
        }
        $created = 0;
        $preserved = 0;
        foreach ($plan as $row) {
            if ($row['departure']->lte(now()->addMinutes(90))) {
                continue;
            }
            $registration = 'DEMO-'.$row['carrier_code'].'-'.($row['rotation'] + 1);
            $hasBusiness = in_array($row['carrier_code'], ['VN', 'QH'], true);
            $layout = $hasBusiness ? SeatMap::preset('NARROW', 'A320', 180) : ['cabins' => [['code' => 'ECONOMY', 'name' => 'Phổ thông', 'rows' => 30, 'blocks' => ['ABC', 'DEF']]]];
            $flight = $ops->transaction(function () use ($ops, $row, $registration, $layout, &$created, &$preserved) {
                $route = $ops->table('routes')->where('origin', $row['origin'])->where('destination', $row['destination'])->first();
                if (! $route) {
                    $routeId = $ops->table('routes')->insertGetId(['origin' => $row['origin'], 'destination' => $row['destination'], 'duration_minutes' => $row['duration'], 'distance_km' => $row['distance'], 'active' => true]);
                } else {
                    $routeId = $route->id;
                }
                $plane = $ops->table('aircraft')->where('registration', $registration)->lockForUpdate()->first();
                $ops->table('aircraft')->insertOrIgnore(['registration' => $registration, 'type' => 'A320', 'capacity' => 180, 'seat_layout' => json_encode($layout)]);
                $plane ??= $ops->table('aircraft')->where('registration', $registration)->lockForUpdate()->first();
                $existing = $ops->table('flights')->where('number', $row['number'])->where('departure', $row['departure'])->first();
                if ($existing) {
                    if ($existing->demo_source !== self::SOURCE || $existing->carrier_code !== $row['carrier_code']) {
                        throw new \RuntimeException('Số hiệu và giờ bay đã được dùng ngoài bộ dữ liệu mẫu.');
                    }
                    $preserved++;

                    return $existing;
                }
                if ($plane->status !== 'AVAILABLE' || (int) $plane->capacity !== 180 || json_decode($plane->seat_layout, true) != $layout) {
                    throw new \RuntimeException('Tàu mẫu đã thay đổi; không tự sửa cấu hình đang sử dụng.');
                }
                $conflict = $ops->table('flights')->where('aircraft_id', $plane->id)->where('status', '!=', 'CANCELLED')
                    ->where('departure', '<', $row['arrival']->copy()->addMinutes(60))->where('arrival', '>', $row['departure']->copy()->subMinutes(60))->exists();
                if ($conflict) {
                    throw new \RuntimeException('Tàu mẫu đã có lịch giao nhau; không thay thế lịch hiện có.');
                }
                $id = $ops->table('flights')->insertGetId(['number' => $row['number'], 'carrier_code' => $row['carrier_code'], 'demo_source' => self::SOURCE,
                    'origin' => $row['origin'], 'destination' => $row['destination'], 'route_id' => $routeId, 'aircraft_id' => $plane->id,
                    'departure' => $row['departure'], 'arrival' => $row['arrival'], 'status' => 'SCHEDULED', 'gate' => 'A1', 'sale_price' => $row['price'], 'published_at' => now(),
                ]);
                $created++;

                return $ops->table('flights')->find($id);
            });
            DB::transaction(function () use ($flight, $row, $layout, $hasBusiness) {
                $stock = ['flight_id' => $flight->id, 'capacity' => 180, 'price' => $row['price'], 'seat_layout' => json_encode($layout)];
                DB::table('inventory')->insertOrIgnore($stock);
                $stock = DB::table('inventory')->where('flight_id', $flight->id)->first();
                if ($stock->status !== 'OPEN') {
                    return;
                }
                $economy = count(SeatMap::seats(180, $layout, 'ECONOMY'));
                foreach (['ECONOMY', 'FLEX', ...($hasBusiness ? ['BUSINESS'] : [])] as $code) {
                    $rules = array_replace(FareRules::DEFAULTS, [
                        'included_baggage_kg' => $code === 'BUSINESS' ? 32 : ($row['carrier_code'] === 'VJ' && $code === 'ECONOMY' ? 0 : 20),
                        'change_fee' => $code === 'FLEX' ? 0 : 200000, 'refund_allowed' => $hasBusiness,
                        'refund_fee' => $hasBusiness ? 150000 : 0,
                    ]);
                    DB::table('fare_classes')->insertOrIgnore(['flight_id' => $flight->id, 'code' => $code, 'name' => ['ECONOMY' => 'Phổ thông', 'FLEX' => 'Phổ thông linh hoạt', 'BUSINESS' => 'Thương gia'][$code],
                        'quota' => $code === 'BUSINESS' ? 12 : ($code === 'FLEX' ? 42 : $economy - 42),
                        'price' => $row['price'] + ['ECONOMY' => 0, 'FLEX' => 350000, 'BUSINESS' => 1500000][$code], 'rules' => json_encode($rules),
                    ]);
                }
            });
        }

        return ['created' => $created, 'preserved' => $preserved, 'start' => $start->toDateString(), 'end' => $start->copy()->addDays($days - 1)->toDateString(), 'carriers' => array_diff_key(Carrier::NAMES, ['AS' => true])];
    }
}
