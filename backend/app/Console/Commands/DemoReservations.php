<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Boarding\SeatMap;
use App\Modules\Booking\ReservationService;
use App\Modules\Finance\GatewayPayments;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Operations\OperationsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DemoReservations extends Command
{
    protected $signature = 'airline:demo-reservations';

    protected $description = 'Create local round-trip and multi-city examples with aircraft-specific cabins';

    public function handle(): int
    {
        if (! app()->environment('local') || config('payments.mode') !== 'mock') {
            $this->error('This command requires local mock payments.');

            return self::FAILURE;
        }
        $path = storage_path('app/private/demo/reservations.json');
        $state = File::exists($path) ? json_decode(File::get($path), true, 32, JSON_THROW_ON_ERROR) : ['anchor' => now()->startOfMinute()->toIso8601String(), 'flights' => [], 'reservations' => []];
        if ($state['complete'] ?? false) {
            $this->info('Examples already exist; test changes preserved. See '.$path);

            return self::SUCCESS;
        }
        $people = [];
        foreach (['fleet_manager', 'crew_scheduler', 'operations.han', 'operations.sgn', 'ops_manager.han', 'ops_manager.sgn', 'passenger'] as $key) {
            $u = User::where('email', 'demo.'.$key.'@airline.test')->first();
            if (! $u || $u->disabled_at || ! $u->hasVerifiedEmail()) {
                $this->error('Run airline:demo-data first; active verified demo accounts are required.');

                return self::FAILURE;
            }
            $people[$key] = $u;
        }
        File::ensureDirectoryExists(dirname($path));
        $save = function () use (&$state, $path) {
            File::replace($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        };
        $save();
        $svc = app(OperationsService::class);
        $db = DB::connection('ops');
        foreach (['A320' => 180, 'ATR72' => 68, 'B787' => 294] as $index => $capacity) {
            $registration = 'VN-GRP-'.$index;
            $plane = $db->table('aircraft')->where('registration', $registration)->first();
            if (! $plane) {
                $plane = $svc->execute($people['fleet_manager'], 'aircraft', ['registration' => $registration, 'type' => $index, 'capacity' => $capacity, 'layout_preset' => 'AUTO']);
            }
            $crewIds = [];
            foreach (['PILOT', 'PILOT', 'CABIN', 'CABIN', 'CABIN', 'CABIN'] as $i => $position) {
                $code = 'GRP-'.$index.'-'.($i + 1);
                $crew = $db->table('crew_members')->where('code', $code)->first();
                if (! $crew) {
                    $crew = $svc->execute($people['crew_scheduler'], 'crew', ['code' => $code, 'name' => 'Tổ bay thử nghiệm '.$index.' '.($i + 1), 'position' => $position, 'qualification' => $index, 'license_expires' => now()->addYears(2)->toDateString()]);
                }
                $crewIds[] = $crew->id;
            }
            $base = match ($index) {
                'A320' => 9700, 'ATR72' => 9702, default => 9704
            };
            $offset = match ($index) {
                'A320' => 180, 'ATR72' => 240, default => 4 * 1440 + 300
            };
            foreach ([['HAN', 'SGN', $offset], ['SGN', 'HAN', $offset + 3 * 1440]] as $leg => [$origin, $destination, $minutes]) {
                $number = 'AS'.($base + $leg);
                $departure = Carbon::parse($state['anchor'])->addMinutes($minutes);
                $f = $db->table('flights')->where('number', $number)->where('departure', $departure)->first();
                if (! $f) {
                    $route = $db->table('routes')->where('origin', $origin)->where('destination', $destination)->first() ?? throw new \RuntimeException('Missing HAN–SGN demo route.');
                    $f = $svc->execute($people['operations.'.strtolower($origin)], 'schedule', ['number' => $number, 'route_id' => $route->id, 'aircraft_id' => $plane->id, 'departure' => $departure->toIso8601String(), 'arrival' => $departure->copy()->addMinutes(135)->toIso8601String(), 'sale_price' => 1590000, 'gate' => 'A16']);
                }
                if (in_array($f->status, ['DRAFT', 'PENDING'])) {
                    foreach ($crewIds as $id) {
                        $svc->execute($people['crew_scheduler'], 'assign_crew', ['flight_id' => $f->id, 'crew_member_id' => $id]);
                    }
                    if ($f->status === 'DRAFT') {
                        $svc->execute($people['operations.'.strtolower($origin)], 'submit', ['flight_id' => $f->id]);
                    }
                    $svc->execute($people['ops_manager.'.strtolower($origin)], 'publish', ['flight_id' => $f->id]);
                }
                $this->relay();
                $layout = SeatMap::preset('AUTO', $index, $capacity);
                foreach (['ECONOMY' => ['Phổ thông', 1590000], 'FLEX' => ['Phổ thông linh hoạt', 2190000], 'BUSINESS' => ['Thương gia', 3790000]] as $code => [$name, $price]) {
                    $quota = count(SeatMap::seats($capacity, $layout, $code));
                    if (! $quota) {
                        continue;
                    }
                    DB::table('fare_classes')->insertOrIgnore(['flight_id' => $f->id, 'code' => $code, 'name' => $name, 'quota' => $code === 'FLEX' ? min(16, $quota) : $quota, 'price' => $price]);
                }
                $state['flights'][$number] = ['id' => $f->id, 'aircraft' => $registration, 'origin' => $origin, 'destination' => $destination, 'departure' => $departure->toIso8601String()];
                $save();
            }
        }
        foreach (['ROUND_TRIP' => ['AS9700', 'AS9701'], 'ONE_WAY' => ['AS9700']] as $type => $numbers) {
            $input = ['trip_type' => $type, 'idempotency_key' => 'demo-single-'.$type, 'passengers' => [['name' => 'Nguyễn Minh Anh']], 'segments' => array_map(fn ($number) => ['flight_id' => $state['flights'][$number]['id'], 'fare_class' => 'ECONOMY'], $numbers)];
            $r = app(ReservationService::class)->hold($people['passenger'], $input);
            $s = app(ReservationService::class)->pay($people['passenger'], $r->id, 'VNPAY', '127.0.0.1');
            $this->relay();
            $i = DB::connection('finance')->table('checkout_intents')->find($s->intent_id);
            if ($i->status === 'PENDING') {
                $p = ['vnp_TmnCode' => config('payments.vnpay.tmn_code'), 'vnp_TxnRef' => $i->id, 'vnp_Amount' => $i->amount * 100, 'vnp_CurrCode' => 'VND', 'vnp_ResponseCode' => '00', 'vnp_TransactionStatus' => '00', 'vnp_TransactionNo' => 'DEMO-GROUP-'.$r->reference];
                $p['vnp_SecureHash'] = GatewayPayments::vnpaySignature($p, config('payments.vnpay.hash_secret'));
                app(GatewayPayments::class)->vnpay($p);
                $this->relay();
            }
            $state['reservations'][$type] = ['id' => $r->id, 'reference' => $r->reference, 'url' => url('/reservations/'.$r->id), 'email' => $people['passenger']->email];
            $save();
            $this->info($type.': '.$r->reference.' — '.url('/reservations/'.$r->id));
        }
        $state['complete'] = true;
        $save();
        $this->info('Six flights, three cabin configurations and two paid single-passenger examples are ready.');

        return self::SUCCESS;
    }

    private function relay(): void
    {
        for ($i = 0; $i < 4; $i++) {
            if (app(OutboxRelay::class)->drain() !== 0) {
                throw new \RuntimeException('Inspect outbox delivery before retrying.');
            }
        }
    }
}
