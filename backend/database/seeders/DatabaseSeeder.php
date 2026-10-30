<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('airline.sample_data') || app()->environment('production')) {
            return;
        }
        $ops = DB::connection('ops');
        foreach ([['HAN', 'Nội Bài', 'Hà Nội'], ['SGN', 'Tân Sơn Nhất', 'TP. Hồ Chí Minh'], ['DAD', 'Đà Nẵng', 'Đà Nẵng']] as [$code,$name,$city]) {
            $ops->table('airports')->insertOrIgnore(compact('code', 'name', 'city') + ['timezone' => 'Asia/Ho_Chi_Minh']);
        }
        $ops->table('aircraft')->insertOrIgnore(['id' => 1, 'registration' => 'VN-DEMO01', 'type' => 'Airbus A320 · mô phỏng', 'capacity' => 60, 'status' => 'AVAILABLE']);
        foreach (['SGN', 'DAD'] as $destination) {
            $ops->table('routes')->insertOrIgnore(['origin' => 'HAN', 'destination' => $destination, 'duration_minutes' => 100, 'distance_km' => $destination === 'SGN' ? 1160 : 600]);
        }
        foreach (['HAN', 'SGN', 'DAD'] as $airport) {
            foreach (['T1' => 'TERMINAL', 'A2' => 'GATE', 'P1' => 'STAND', 'C1' => 'COUNTER'] as $code => $type) {
                $ops->table('airport_resources')->insertOrIgnore(['airport_code' => $airport, 'code' => $code, 'type' => $type]);
            }
        }
        $ops->table('aircraft')->insertOrIgnore(['id' => 100, 'registration' => 'VN-DEMO-MRO', 'type' => 'A320', 'capacity' => 60, 'status' => 'MAINTENANCE']);
        $ops->table('work_orders')->insertOrIgnore(['id' => 100, 'aircraft_id' => 100, 'title' => 'Kiểm tra định kỳ mô phỏng', 'due_at' => now()->addDays(7), 'status' => 'OPEN', 'created_by' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1001 => 'PILOT', 1002 => 'CABIN'] as $id => $position) {
            $ops->table('crew_members')->insertOrIgnore(['id' => $id, 'code' => 'DEMO-'.$position, 'name' => $position === 'PILOT' ? 'Phi công mô phỏng' : 'Tiếp viên mô phỏng', 'position' => $position, 'qualification' => 'A320', 'license_expires' => now()->addYear()->toDateString(), 'available' => true]);
        }
        $ops->table('devices')->insertOrIgnore(['id' => 100, 'code' => 'SIM-HAN-TEMP', 'airport_code' => 'HAN', 'threshold' => 50]);
        for ($day = 0; $day < 7; $day++) {
            $id = $day + 1;
            $departure = $day === 0 ? now()->addMinutes(45) : now()->addDays($day)->startOfDay()->addHours(4);
            $ops->table('flights')->insertOrIgnore(['id' => $id, 'number' => 'AS'.(100 + $id), 'origin' => 'HAN', 'destination' => $day % 2 ? 'DAD' : 'SGN',
                'aircraft_id' => 1, 'departure' => $departure, 'arrival' => $departure->copy()->addMinutes(100), 'status' => $day === 0 ? 'BOARDING' : 'SCHEDULED', 'gate' => 'A'.($id + 1)]);
            DB::table('inventory')->insertOrIgnore(['flight_id' => $id, 'capacity' => 60, 'price' => 1290000 + $day * 80000, 'currency' => 'VND']);
        }
    }
}
