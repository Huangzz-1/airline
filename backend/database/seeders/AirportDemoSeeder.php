<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Analytics\AnalyticsService;
use App\Modules\Baggage\BaggageService;
use App\Modules\Boarding\BoardingService;
use App\Modules\Booking\BookingService;
use App\Modules\Booking\ExchangeService;
use App\Modules\Customer\CustomerService;
use App\Modules\Finance\CheckoutService;
use App\Modules\Finance\FinanceService;
use App\Modules\Finance\GatewayPayments;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Operations\FlightChangeService;
use App\Modules\Operations\MasterDataService;
use App\Modules\Operations\OperationsService;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Explicit CLI fixture generator; never called by DatabaseSeeder or normal setup. */
class AirportDemoSeeder extends Seeder
{
    private array $state;

    private string $directory;

    private string $passwordHash;

    private array $users = [];

    private array $passengers = [];

    private array $rotations = [];

    private array $reserves = [];

    private const AIRPORTS = [
        ['HAN', 'Nội Bài', 'Hà Nội'], ['SGN', 'Tân Sơn Nhất', 'TP. Hồ Chí Minh'],
        ['DAD', 'Đà Nẵng', 'Đà Nẵng'], ['CXR', 'Cam Ranh', 'Khánh Hòa'],
        ['PQC', 'Phú Quốc', 'Phú Quốc'], ['HUI', 'Phú Bài', 'Huế'],
        ['DLI', 'Liên Khương', 'Đà Lạt'], ['HPH', 'Cát Bi', 'Hải Phòng'],
        ['VII', 'Vinh', 'Vinh'], ['VCA', 'Cần Thơ', 'Cần Thơ'],
        ['BMV', 'Buôn Ma Thuột', 'Buôn Ma Thuột'], ['UIH', 'Phù Cát', 'Quy Nhơn'],
        ['VDH', 'Đồng Hới', 'Đồng Hới'], ['VCL', 'Chu Lai', 'Quảng Ngãi'],
        ['THD', 'Thọ Xuân', 'Thanh Hóa'], ['VCS', 'Côn Đảo', 'Côn Đảo'],
        ['DIN', 'Điện Biên', 'Điện Biên Phủ'], ['PXU', 'Pleiku', 'Pleiku'],
        ['TBB', 'Tuy Hòa', 'Tuy Hòa'], ['VKG', 'Rạch Giá', 'Rạch Giá'],
        ['CAH', 'Cà Mau', 'Cà Mau'], ['VDO', 'Vân Đồn', 'Quảng Ninh'],
    ];

    // Durations, prices, aircraft and timetables below are test assumptions.
    private const PAIRS = [
        ['HAN', 'SGN', 135, 1160, 'A321', 220, 1590000],
        ['HAN', 'DAD', 80, 630, 'A320', 180, 1090000],
        ['HAN', 'CXR', 115, 1080, 'A321', 220, 1490000],
        ['HAN', 'PQC', 135, 1240, 'B787', 294, 1890000],
        ['HAN', 'HUI', 75, 570, 'A320', 180, 990000],
        ['HAN', 'DLI', 110, 1080, 'A321', 220, 1390000],
        ['SGN', 'DAD', 85, 605, 'A321', 220, 1190000],
        ['SGN', 'HPH', 125, 1110, 'A321', 220, 1490000],
        ['SGN', 'VII', 110, 880, 'A320', 180, 1290000],
        ['SGN', 'VCS', 60, 230, 'ATR72', 68, 1690000],
        ['SGN', 'BMV', 65, 260, 'A320', 180, 890000],
        ['SGN', 'UIH', 70, 435, 'A321', 220, 990000],
        ['HAN', 'VCA', 135, 1230, 'A321', 220, 1690000],
        ['HAN', 'VDH', 70, 400, 'A320', 180, 990000],
        ['HAN', 'VCL', 90, 720, 'A320', 180, 1190000],
        ['HAN', 'THD', 50, 135, 'ATR72', 68, 790000],
        ['HAN', 'DIN', 65, 290, 'ATR72', 68, 1090000],
        ['SGN', 'PXU', 75, 385, 'A320', 180, 990000],
        ['SGN', 'TBB', 70, 385, 'A320', 180, 990000],
        ['VCA', 'VKG', 40, 75, 'ATR72', 68, 690000],
        ['SGN', 'CAH', 65, 250, 'ATR72', 68, 1190000],
        ['SGN', 'VDO', 130, 1150, 'A321', 220, 1590000],
    ];

    public function generate(callable $progress): void
    {
        if (! app()->environment('local') || config('payments.mode') !== 'mock' || config('payments.bank.mode') !== 'mock') {
            throw new \RuntimeException('This fixture is restricted to local mock services.');
        }
        $this->directory = storage_path('app/private/demo');
        File::ensureDirectoryExists($this->directory);
        $path = $this->directory.'/state.json';
        $this->state = File::exists($path) ? json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR) : [
            'version' => 3, 'anchor' => now()->startOfMinute()->toIso8601String(), 'steps' => [], 'accounts' => [], 'scenarios' => [],
        ];
        if (($this->state['complete'] ?? false) && ($this->state['version'] ?? 1) >= 3) {
            $progress('Dataset already exists. Accounts, passwords and your test changes were preserved.');

            return;
        }
        $this->passwordHash = Hash::make(config('airline.demo_password'));
        $progress('Creating airport resources and accounts...');
        $this->infrastructure();
        $progress('Creating fleet, licensed crews and reserve teams...');
        $this->fleet();
        $progress('Creating 7 days of history and 14 future days of flights and manifests...');
        $this->timetable($progress);
        $progress('Creating live service, payment and approval scenarios...');
        $this->scenarios();
        $progress('Adding baggage history, ground services and checkout records...');
        $this->enrich();
        $this->relay();
        $this->verify();
        $this->report();
        $this->state['complete'] = true;
        $this->state['version'] = 3;
        $this->save();
        $progress('Dataset verified: '.json_encode($this->state['counts'], JSON_UNESCAPED_UNICODE));
    }

    private function anchor(): Carbon
    {
        return Carbon::parse($this->state['anchor'])->utc();
    }

    private function save(): void
    {
        File::replace($this->directory.'/state.json', json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function step(string $key, callable $action): mixed
    {
        if (array_key_exists($key, $this->state['steps'])) {
            return $this->state['steps'][$key];
        }
        $result = $action();
        $this->state['steps'][$key] = $result;
        $this->save();

        return $result;
    }

    private function account(string $key, string $name, string $role, ?string $airport = null, array $extra = []): User
    {
        $email = 'demo.'.$key.'@airline.test';
        $u = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => $this->passwordHash]);
        if ($u->wasRecentlyCreated) {
            $u->forceFill(array_replace(['role' => $role, 'airport_code' => $airport, 'email_verified_at' => now(), 'consented_at' => now(), 'must_change_password' => false], $extra))->save();
            Audit::record('demo.account_created', (string) $u->id, null, $role);
        } elseif ($u->role !== $role || $u->airport_code !== $airport) {
            throw new \RuntimeException("Existing account $email has a different role/scope; it was not changed.");
        }
        $this->state['accounts'][$key] = ['email' => $email, 'role' => $role, 'airport' => $airport, 'name' => $name];

        return $this->users[$key] = $u;
    }

    private function user(string $role, ?string $airport = null): User
    {
        return $this->users[$role.($airport ? '.'.strtolower($airport) : '')];
    }

    private function infrastructure(): void
    {
        $db = DB::connection('ops');
        foreach (self::AIRPORTS as [$code, $name, $city]) {
            $db->table('airports')->insertOrIgnore(['code' => $code, 'name' => $name, 'city' => $city, 'timezone' => 'Asia/Ho_Chi_Minh']);
            foreach (['T1' => 'TERMINAL', 'T2' => 'TERMINAL'] as $resource => $type) {
                $db->table('airport_resources')->insertOrIgnore(['airport_code' => $code, 'code' => $resource, 'type' => $type]);
            }
            for ($i = 1; $i <= (in_array($code, ['HAN', 'SGN', 'DAD']) ? 16 : 8); $i++) {
                foreach (['A' => 'GATE', 'P' => 'STAND', 'C' => 'COUNTER'] as $prefix => $type) {
                    $db->table('airport_resources')->insertOrIgnore(['airport_code' => $code, 'code' => $prefix.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'type' => $type]);
                }
            }
            foreach (Access::SCOPED as $role) {
                $this->account($role.'.'.strtolower($code), 'Nhân viên thử nghiệm '.$role.' '.$code, $role, $code);
            }
        }
        foreach (['admin', 'data_admin', 'fleet_manager', 'maintenance', 'crew_scheduler', 'finance', 'customer_service', 'analyst', 'auditor'] as $role) {
            $this->account($role, 'Tài khoản thử nghiệm '.$role, $role);
        }
        foreach (['data_admin', 'fleet_manager', 'maintenance', 'crew_scheduler', 'finance', 'customer_service'] as $role) {
            $this->account($role.'.2', 'Người duyệt độc lập '.$role, $role);
        }
        $this->account('maintenance.3', 'Người giải phóng kỹ thuật', 'maintenance');
        $this->account('staff.temporary', 'Nhân viên cần đổi mật khẩu tạm', 'airport_agent', 'HAN', ['must_change_password' => true]);
        $this->account('staff.disabled', 'Nhân viên đang tạm khóa', 'airport_agent', 'HAN', ['disabled_at' => now()]);
        $this->account('passenger.unverified', 'Khách chưa xác minh email', 'passenger', null, ['email_verified_at' => null]);
        foreach (['passenger' => 'Nguyễn Minh Anh', 'passenger.2' => 'Trần Hoàng Nam', 'passenger.3' => 'Lê Thu Hà'] as $key => $name) {
            $this->account($key, $name, 'passenger');
        }
        for ($i = 1; $i <= 720; $i++) {
            $this->passengers[] = $this->account('p'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), $this->name($i), 'passenger');
        }
        foreach (self::PAIRS as [$origin, $destination, $minutes, $distance]) {
            foreach ([[$origin, $destination], [$destination, $origin]] as [$from, $to]) {
                $db->table('routes')->insertOrIgnore(['origin' => $from, 'destination' => $to, 'duration_minutes' => $minutes, 'distance_km' => $distance, 'active' => true]);
            }
        }
        $this->save();
    }

    private function name(int $i): string
    {
        $families = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Huỳnh', 'Phan', 'Vũ', 'Võ', 'Đặng', 'Bùi', 'Đỗ'];
        $middles = ['Minh', 'Hoàng', 'Thanh', 'Ngọc', 'Quốc', 'Thu', 'Gia', 'Bảo', 'Anh', 'Hải'];
        $names = ['An', 'Bình', 'Châu', 'Dũng', 'Giang', 'Hà', 'Hương', 'Khánh', 'Linh', 'Nam', 'Phúc', 'Quân', 'Trang', 'Vy', 'Yến'];

        return $families[$i % count($families)].' '.$middles[intdiv($i, 12) % count($middles)].' '.$names[intdiv($i, 120) % count($names)];
    }

    private function plane(string $registration, string $type, int $capacity, string $status = 'AVAILABLE'): object
    {
        $db = DB::connection('ops');
        $db->table('aircraft')->insertOrIgnore(['registration' => $registration, 'type' => $type, 'capacity' => $capacity, 'status' => $status, 'flight_hours' => 1800 + $capacity * 5, 'cycles' => 640]);

        return $db->table('aircraft')->where('registration', $registration)->first();
    }

    private function team(string $prefix, string $type): array
    {
        $ids = [];
        $db = DB::connection('ops');
        foreach (['PILOT', 'PILOT', 'CABIN', 'CABIN', 'CABIN', 'CABIN'] as $i => $position) {
            $code = $prefix.'-'.($i + 1);
            $db->table('crew_members')->insertOrIgnore(['code' => $code, 'name' => $this->name(crc32($code) % 1800), 'position' => $position, 'qualification' => $type, 'license_expires' => $this->anchor()->addMonths(18)->toDateString(), 'available' => true]);
            $ids[] = $db->table('crew_members')->where('code', $code)->value('id');
        }

        return $ids;
    }

    private function fleet(): void
    {
        foreach (self::PAIRS as $i => $pair) {
            $this->rotations[] = ['pair' => $pair, 'plane' => $this->plane('VN-D'.(100 + $i), $pair[4], $pair[5]), 'outbound' => $this->team('DEMO-R'.$i.'-O', $pair[4]), 'inbound' => $this->team('DEMO-R'.$i.'-I', $pair[4])];
        }
        foreach (['A320' => 180, 'A321' => 220, 'B787' => 294, 'ATR72' => 68, 'A350' => 305] as $type => $capacity) {
            $this->reserves[$type] = ['plane' => $this->plane('VN-D'.(200 + count($this->reserves)), $type, $capacity), 'team' => $this->team('DEMO-RES-'.$type, $type)];
        }
        $this->team('DEMO-RES-A320-2', 'A320');
        foreach (range(1, 3) as $i) {
            $this->plane('VN-D30'.$i, 'A320', 180, 'MAINTENANCE');
        }
        $db = DB::connection('ops');
        $db->table('crew_members')->insertOrIgnore(['code' => 'DEMO-LICENSE-SOON', 'name' => 'Nguyễn Gia Bảo', 'position' => 'PILOT', 'qualification' => 'A320', 'license_expires' => now()->addDays(10)->toDateString(), 'available' => true]);
        $db->table('crew_members')->insertOrIgnore(['code' => 'DEMO-LICENSE-EXPIRED', 'name' => 'Trần Thu Hương', 'position' => 'CABIN', 'qualification' => 'A320', 'license_expires' => now()->subDays(3)->toDateString(), 'available' => false]);
    }

    private function timetable(callable $progress): void
    {
        $today = $this->anchor()->tz('Asia/Ho_Chi_Minh')->startOfDay();
        foreach (range(-7, 14) as $day) {
            foreach ($this->rotations as $i => $rotation) {
                [$from, $to, $minutes, $distance, $type, $capacity, $price] = $rotation['pair'];
                $out = $today->copy()->addDays($day)->addHours(6 + ($i % 6))->addMinutes(($i % 2) * 15)->utc();
                $back = $out->copy()->addMinutes($minutes + 90);
                foreach ([[$from, $to, $out, 'outbound'], [$to, $from, $back, 'inbound']] as $leg => [$origin, $destination, $departure, $team]) {
                    $key = 'timetable:'.$day.':'.$i.':'.$leg;
                    $this->step($key, function () use ($rotation, $i, $leg, $origin, $destination, $departure, $minutes, $price, $team, $day) {
                        $status = $departure->copy()->addMinutes($minutes)->lt(now()) ? 'ARRIVED' : ($departure->lt(now()) ? 'DEPARTED' : 'SCHEDULED');
                        $f = $this->fixtureFlight('AS'.(800 + $i * 2 + $leg), $origin, $destination, $departure, $minutes, $rotation['plane'], $rotation[$team], $price + max(0, $day % 4) * 100000, $status);
                        $this->manifest($f, 24 + (($i * 7 + $leg * 5 + abs($day)) % 25), ($day + 7) * 24 + $i * 2 + $leg);

                        return $f->id;
                    });
                }
            }
            $progress('  Flight manifests completed for '.$today->copy()->addDays($day)->format('d/m/Y'));
        }
    }

    private function fixtureFlight(string $number, string $origin, string $destination, Carbon $departure, int $minutes, object $plane, array $crew, int $price, string $status = 'SCHEDULED'): object
    {
        $db = DB::connection('ops');
        $old = $db->table('flights')->where('number', $number)->where('departure', $departure)->first();
        if ($old) {
            return $old;
        }
        $route = $db->table('routes')->where('origin', $origin)->where('destination', $destination)->first();
        $gate = null;
        foreach ($db->table('airport_resources')->where('airport_code', $origin)->where('type', 'GATE')->where('active', true)->orderBy('code')->get() as $candidate) {
            if (! $db->table('resource_allocations')->where('resource_id', $candidate->id)->where('starts_at', '<', $departure->copy()->addMinutes(15))->where('ends_at', '>', $departure->copy()->subMinutes(45))->exists()) {
                $gate = $candidate;
                break;
            }
        }
        if (! $gate) {
            throw new \RuntimeException('No available gate for '.$number);
        }
        $id = $db->transaction(function () use ($db, $number, $origin, $destination, $departure, $minutes, $plane, $crew, $price, $status, $route, $gate) {
            $arrival = $departure->copy()->addMinutes($minutes);
            $published = ! in_array($status, ['DRAFT', 'PENDING']);
            $id = $db->table('flights')->insertGetId(['number' => $number, 'origin' => $origin, 'destination' => $destination, 'route_id' => $route->id, 'aircraft_id' => $plane->id, 'departure' => $departure, 'arrival' => $arrival, 'status' => $status, 'gate' => $gate->code, 'sale_price' => $price, 'created_by' => $this->user('operations', $origin)->id, 'approved_by' => $published ? $this->user('ops_manager', $origin)->id : null, 'published_at' => $published ? $departure->copy()->subDays(20) : null, 'actual_departure' => in_array($status, ['DEPARTED', 'ARRIVED']) ? $departure : null, 'actual_arrival' => $status === 'ARRIVED' ? $arrival : null]);
            foreach ($crew as $member) {
                $db->table('crew_assignments')->insert(['flight_id' => $id, 'crew_member_id' => $member, 'assigned_by' => $this->user('crew_scheduler')->id, 'active' => true, 'created_at' => $departure->copy()->subDays(21)]);
            }
            if ($published) {
                $db->table('resource_allocations')->insert(['flight_id' => $id, 'resource_id' => $gate->id, 'starts_at' => $departure->copy()->subMinutes(45), 'ends_at' => $departure->copy()->addMinutes(15), 'actor_id' => $this->user('operations', $origin)->id, 'reason' => 'Phân bổ cửa cho lịch thử nghiệm', 'created_at' => now(), 'updated_at' => now()]);
            }
            Audit::record('demo.flight_snapshot', (string) $id, null, $status, $this->user('operations', $origin)->id, 'ops');

            return $id;
        });
        if (! in_array($status, ['DRAFT', 'PENDING'])) {
            DB::table('inventory')->insertOrIgnore(['flight_id' => $id, 'capacity' => $plane->capacity, 'price' => $price, 'status' => in_array($status, ['SCHEDULED', 'BOARDING', 'DELAYED']) ? 'OPEN' : 'CLOSED', 'source_version' => 1]);
            foreach (['ECONOMY' => ['Phổ thông tiết kiệm', $plane->capacity - 24, $price], 'FLEX' => ['Phổ thông linh hoạt', 16, $price + 650000], 'BUSINESS' => ['Thương gia', 8, $price + 2200000]] as $code => [$name, $quota, $fare]) {
                DB::table('fare_classes')->insertOrIgnore(['flight_id' => $id, 'code' => $code, 'name' => $name, 'quota' => $quota, 'price' => $fare, 'active' => true]);
            }
        }

        return $db->table('flights')->find($id);
    }

    private function manifest(object $f, int $count, int $offset): void
    {
        $bookings = $tickets = $payments = $ledger = $passes = [];
        $seats = array_map(fn ($i) => (intdiv($i, 6) + 1).chr(65 + $i % 6), range(0, (int) DB::table('inventory')->where('flight_id', $f->id)->value('capacity') - 1));
        // Keep snapshot imports bounded and coherent across the three databases.
        for ($i = 0; $i < $count; $i++) {
            $u = $this->passengers[($offset * 29 + $i) % count($this->passengers)];
            $key = 'demo-manifest-'.$f->id.'-'.$i;
            if (DB::table('bookings')->where('user_id', $u->id)->where('idempotency_key', $key)->exists()) {
                continue;
            }
            $fare = $i < 4 ? 'BUSINESS' : ($i < 12 ? 'FLEX' : 'ECONOMY');
            $amount = $f->sale_price + ($fare === 'BUSINESS' ? 2200000 : ($fare === 'FLEX' ? 650000 : 0));
            $past = in_array($f->status, ['ARRIVED', 'DEPARTED', 'GATE_CLOSED']);
            $checked = ! $past && Carbon::parse($f->departure)->between(now()->addMinutes(30), now()->addHours(24)) && $i < 12;
            $status = $past ? 'BOARDED' : ($checked ? 'CHECKED_IN' : 'CONFIRMED');
            $id = (string) Str::uuid();
            $intent = (string) Str::uuid();
            $created = Carbon::parse($f->departure)->subDays(3 + $i % 5);
            if ($created->gt(now())) {
                $created = $this->anchor()->subMinutes(5 + $i);
            }
            $bookings[] = ['id' => $id, 'reference' => 'D'.strtoupper(Str::random(7)), 'user_id' => $u->id, 'flight_id' => $f->id, 'idempotency_key' => $key, 'fare_class' => $fare, 'status' => $status, 'amount' => $amount, 'expires_at' => $created->copy()->addMinutes(10), 'seat' => ($past || $checked) ? $seats[$i] : null, 'payment_method' => ['VNPAY', 'MOMO', 'BANK'][$i % 3], 'created_at' => $created, 'updated_at' => $past ? $f->departure : $created];
            $tickets[] = ['booking_id' => $id, 'number' => 'ASD'.strtoupper(Str::random(13)), 'created_at' => $created, 'updated_at' => $created];
            $payments[] = ['booking_id' => $id, 'amount' => $amount, 'currency' => 'VND', 'status' => 'CAPTURED', 'method' => ['VNPAY', 'MOMO', 'BANK'][$i % 3], 'mode' => 'mock', 'correlation_id' => $intent, 'created_at' => $created, 'updated_at' => $created];
            $ledger[] = ['booking_id' => $id, 'kind' => 'CAPTURE', 'amount' => $amount, 'created_at' => $created];
            if ($past || $checked) {
                $token = bin2hex(random_bytes(32));
                $passes[] = ['booking_id' => $id, 'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token), 'expires_at' => Carbon::parse($f->departure)->subMinutes(10), 'used_at' => $past ? Carbon::parse($f->departure)->subMinutes(25) : null, 'revoked_at' => null, 'created_at' => Carbon::parse($f->departure)->subHours(2), 'updated_at' => $past ? $f->departure : now()];
            }
        }
        DB::transaction(function () use ($bookings, $tickets, $passes) {
            if ($bookings) {
                DB::table('bookings')->insert($bookings);
            }
            if ($tickets) {
                DB::table('tickets')->insert($tickets);
            }
            if ($passes) {
                DB::table('boarding_passes')->insert($passes);
            }
        });
        DB::connection('finance')->transaction(function () use ($payments, $ledger) {
            if ($payments) {
                DB::connection('finance')->table('payments')->insert($payments);
            }
            if ($ledger) {
                DB::connection('finance')->table('ledger_entries')->insert($ledger);
            }
        });
    }

    private function relay(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $failed = app(OutboxRelay::class)->drain();
            if ($failed) {
                throw new \RuntimeException('An outbox event failed. Inspect Laravel logs before retrying.');
            }
            $pending = 0;
            foreach (['ops', 'mysql', 'finance'] as $node) {
                $pending += DB::connection($node)->table('outbox')->whereNull('delivered_at')->where('available_at', '<=', now())->count();
            }
            if (! $pending) {
                return;
            }
        }
        throw new \RuntimeException('Outbox did not finish within twelve delivery rounds.');
    }

    private function scenario(string $key, string $description, callable $action): mixed
    {
        $result = $this->step('scenario:'.$key, $action);
        $this->state['scenarios'][$key] = ['description' => $description, 'data' => $result];
        $this->save();

        return $result;
    }

    private function serviceFlight(string $number, int $minutesFromNow, string $status = 'SCHEDULED', string $type = 'A320'): object
    {
        $reserve = $this->reserves[$type];

        return $this->fixtureFlight($number, 'HAN', 'SGN', $this->anchor()->addMinutes($minutesFromNow), 135, $reserve['plane'], $this->team('DEMO-'.$number, $type), 1590000, $status);
    }

    private function paid(User $u, object $f, string $key, string $method = 'VNPAY', ?object $existing = null): object
    {
        $b = $existing ?? app(BookingService::class)->hold($u->id, $f->id, 'demo-'.$key);
        if ($b->status === 'HELD') {
            app(CheckoutService::class)->start($u, $b->id, $method, '127.0.0.1');
        }
        $this->relay();
        if (DB::table('bookings')->where('id', $b->id)->value('status') === 'PAYMENT_PENDING') {
            $intent = DB::connection('finance')->table('checkout_intents')->where('booking_id', $b->id)->first();
            if ($method === 'BANK') {
                app(GatewayPayments::class)->decideBank($this->user('finance'), $intent->id, true, 'DEMO-PAY-'.$intent->reference);
            } else {
                $p = ['vnp_TmnCode' => config('payments.vnpay.tmn_code'), 'vnp_TxnRef' => $intent->id, 'vnp_Amount' => $intent->amount * 100, 'vnp_CurrCode' => 'VND', 'vnp_ResponseCode' => '00', 'vnp_TransactionStatus' => '00', 'vnp_TransactionNo' => 'DEMO-'.$intent->reference];
                $p['vnp_SecureHash'] = GatewayPayments::vnpaySignature($p, config('payments.vnpay.hash_secret'));
                app(GatewayPayments::class)->vnpay($p);
            }
            $this->relay();
        }

        return DB::table('bookings')->find($b->id);
    }

    private function scenarios(): void
    {
        $ops = app(OperationsService::class);
        $boardingId = $this->step('flight:boarding', fn () => $this->serviceFlight('AS9901', 55, 'BOARDING')->id);
        $checkinId = $this->step('flight:checkin', fn () => $this->serviceFlight('AS9902', 8 * 60)->id);
        $sourceId = $this->step('flight:source', fn () => $this->serviceFlight('AS9903', 2 * 1440 + 300)->id);
        $targetId = $this->step('flight:target', fn () => $this->serviceFlight('AS9904', 3 * 1440 + 300)->id);
        $boardingFlight = $ops->flight($boardingId);
        $checkinFlight = $ops->flight($checkinId);
        $source = $ops->flight($sourceId);
        $target = $ops->flight($targetId);
        $p = $this->user('passenger');
        $p2 = $this->user('passenger.2');
        $p3 = $this->user('passenger.3');
        $agent = $this->user('airport_agent', 'HAN');
        $this->scenario('checkin', 'Vé đã thanh toán, có thể check-in, chọn ghế và in vé.', function () use ($p, $checkinFlight) {
            $b = $this->paid($p, $checkinFlight, 'checkin');

            return ['reference' => $b->reference, 'booking_id' => $b->id, 'flight' => $checkinFlight->number, 'email' => $p->email];
        });
        $this->scenario('boarding', 'Đã check-in ghế 2A, QR còn hiệu lực để quét lên tàu; hành lý đã soi chiếu.', function () use ($p, $boardingFlight, $agent) {
            $b = $this->paid($p, $boardingFlight, 'boarding');
            app(BoardingService::class)->checkin($b->id, $p->id, '2A');
            $bag = app(BaggageService::class)->tag($agent, ['booking_id' => $b->id, 'weight' => 18.5, 'request_id' => 'demo-bag-boarding']);
            foreach (['ACCEPTED', 'SCREENED'] as $state) {
                app(BaggageService::class)->scan($agent, ['event_id' => (string) Str::uuid(), 'tag' => $bag->tag, 'flight_id' => $boardingFlight->id, 'status' => $state, 'location' => 'HAN-T1', 'custody' => 'HAN-BHS']);
            }

            return ['reference' => $b->reference, 'booking_id' => $b->id, 'flight' => $boardingFlight->number, 'baggage_tag' => $bag->tag, 'email' => $p->email];
        });
        $this->scenario('boarded', 'Khách đã lên tàu, QR quét lại sẽ bị từ chối.', function () use ($p2, $boardingFlight, $agent) {
            $b = $this->paid($p2, $boardingFlight, 'boarded');
            $pass = app(BoardingService::class)->checkin($b->id, $p2->id, '2B');
            app(BoardingService::class)->board($agent, $boardingFlight->id, $pass->token);

            return ['reference' => $b->reference, 'booking_id' => $b->id, 'email' => $p2->email];
        });
        $this->scenario('mishandled', 'Hành lý thất lạc cần xử lý và bàn giao lại.', function () use ($p3, $boardingFlight, $agent) {
            $b = $this->paid($p3, $boardingFlight, 'mishandled');
            app(BoardingService::class)->checkin($b->id, $p3->id, '3A');
            $bag = app(BaggageService::class)->tag($agent, ['booking_id' => $b->id, 'weight' => 23, 'request_id' => 'demo-bag-mishandled']);
            app(BaggageService::class)->scan($agent, ['event_id' => (string) Str::uuid(), 'tag' => $bag->tag, 'flight_id' => $boardingFlight->id, 'status' => 'MISHANDLED', 'location' => 'HAN-BHS', 'custody' => 'HAN-LOST-FOUND']);

            return ['reference' => $b->reference, 'booking_id' => $b->id, 'baggage_tag' => $bag->tag, 'email' => $p3->email];
        });
        $this->scenario('bank', 'Khách đã báo chuyển khoản, tài chính chờ đối chiếu sao kê.', function () use ($p, $source) {
            $b = app(BookingService::class)->hold($p->id, $source->id, 'demo-bank');
            app(CheckoutService::class)->start($p, $b->id, 'BANK', '127.0.0.1');
            $this->relay();
            app(CheckoutService::class)->report($p, $b->id, 'DEMO-TRANSFER-001');
            $this->relay();

            return ['reference' => $b->reference, 'booking_id' => $b->id, 'email' => $p->email];
        });
        foreach (['requested', 'approved', 'refunded', 'rejected'] as $stage) {
            $this->scenario('refund-'.$stage, 'Hoàn tiền: '.$stage.'; tài khoản nhận hoàn là dữ liệu giả.', function () use ($p, $source, $stage) {
                $b = $this->paid($p, $source, 'refund-'.$stage);
                $svc = app(FinanceService::class);
                $svc->requestRefund($p, $b->id, 'Thay đổi kế hoạch công tác - tình huống thử nghiệm');
                $this->relay();
                $svc->destination($p, $b->id, ['bank_name' => 'DEMO BANK', 'account_name' => 'NGUYEN MINH ANH DEMO', 'account_number' => 'DEMO000000001']);
                $this->relay();
                $id = DB::connection('finance')->table('refunds')->where('booking_id', $b->id)->value('id');
                if ($stage !== 'requested') {
                    $svc->decide($this->user('finance'), $id, $stage !== 'rejected');
                }
                if ($stage === 'refunded') {
                    $svc->settleRefund($this->users['finance.2'], $id, 'DEMO-REFUND-'.$b->reference);
                }
                $this->relay();

                return ['reference' => $b->reference, 'booking_id' => $b->id, 'refund_id' => $id, 'email' => $p->email];
            });
        }
        foreach (['pending', 'processing', 'completed', 'rejected'] as $stage) {
            $this->scenario('exchange-'.$stage, 'Đổi vé cùng HAN–SGN: '.$stage.'.', function () use ($p2, $source, $target, $stage) {
                $b = $this->paid($p2, $source, 'exchange-'.$stage);
                $svc = app(ExchangeService::class);
                $req = DB::table('exchange_requests')->where('source_booking_id', $b->id)->first()
                    ?? $svc->request($p2, ['request_id' => (string) Str::uuid(), 'booking_id' => $b->id, 'flight_id' => $target->id, 'reason' => 'Đổi sang chuyến ngày kế tiếp để thử quy trình']);
                if ($stage !== 'pending' && $req->status === 'PENDING') {
                    $svc->decide($this->user('finance'), ['exchange_id' => $req->id, 'approve' => $stage !== 'rejected', 'scenario' => 'approve']);
                    $this->relay();
                }
                if ($stage === 'completed') {
                    $this->paid($p2, $target, 'exchange-'.$req->id, existing: DB::table('bookings')->find($req->target_booking_id));
                    $this->relay();
                    $fin = app(FinanceService::class);
                    $fin->destination($p2, $b->id, ['bank_name' => 'DEMO BANK', 'account_name' => 'TRAN HOANG NAM DEMO', 'account_number' => 'DEMO000000002']);
                    $this->relay();
                    $refund = DB::connection('finance')->table('refunds')->where('booking_id', $b->id)->first();
                    $fin->decide($this->users['finance.2'], $refund->id, true);
                    $fin->settleRefund($this->user('finance'), $refund->id, 'DEMO-EXCHANGE-'.$b->reference);
                    $this->relay();
                }

                return ['reference' => $b->reference, 'booking_id' => $b->id, 'exchange_id' => $req->id, 'target_booking_id' => $req->target_booking_id, 'email' => $p2->email];
            });
        }
        $this->moreScenarios($source, $checkinFlight, $boardingFlight);
    }

    private function enrich(): void
    {
        $backfillCheckouts = function () {
            DB::table('bookings')->where('idempotency_key', 'like', 'demo-manifest-%')->chunkById(200, function ($bookings) {
                $payments = DB::connection('finance')->table('payments')->whereIn('booking_id', $bookings->pluck('id'))->get()->keyBy('booking_id');
                $sessions = $intents = [];
                foreach ($bookings as $b) {
                    $payment = $payments->get($b->id) ?? throw new \RuntimeException('Snapshot payment missing');
                    $bank = $b->payment_method === 'BANK' ? json_encode(['name' => 'DEMO BANK', 'account_number' => '000000000000', 'account_name' => 'AIRLINE LOCAL', 'mode' => 'mock', 'content' => 'AS '.$b->reference]) : null;
                    $sessions[] = ['booking_id' => $b->id, 'intent_id' => $payment->correlation_id, 'method' => $b->payment_method, 'mode' => 'mock', 'status' => 'READY', 'expires_at' => $b->expires_at, 'bank_details' => $bank, 'created_at' => $b->created_at, 'updated_at' => $b->updated_at];
                    $intents[] = ['id' => $payment->correlation_id, 'booking_id' => $b->id, 'reference' => $b->reference, 'method' => $b->payment_method, 'mode' => 'mock', 'amount' => $b->amount, 'status' => 'CAPTURED', 'ip_address' => '127.0.0.1', 'provider_transaction' => 'DEMO-HISTORY-'.$b->reference, 'approved_by' => $b->payment_method === 'BANK' ? $this->user('finance')->id : null, 'expires_at' => $b->expires_at, 'bank_details' => $bank, 'created_at' => $b->created_at, 'updated_at' => $b->updated_at];
                }
                DB::table('checkout_sessions')->insertOrIgnore($sessions);
                DB::connection('finance')->table('checkout_intents')->insertOrIgnore($intents);
            });

            return true;
        };
        $this->step('enrich:baggage-v3', function () {
            foreach (DB::connection('ops')->table('flights')->where('number', 'like', 'AS8%')->where('status', 'ARRIVED')->get() as $f) {
                foreach (DB::table('bookings')->where('flight_id', $f->id)->where('status', 'BOARDED')->orderBy('id')->limit(6)->get() as $i => $b) {
                    $request = 'demo-history-'.$b->id;
                    if (DB::table('baggage')->where('request_id', $request)->exists()) {
                        continue;
                    }
                    DB::transaction(function () use ($f, $b, $i, $request) {
                        $end = ($i === 5 && $f->id % 5 === 0) ? 'MISHANDLED' : (($i === 4 && $f->id % 4 === 0) ? 'TRANSFERRED' : 'DELIVERED');
                        $created = Carbon::parse($f->departure)->subMinutes(100);
                        $received = Carbon::parse($f->arrival)->addMinutes(25);
                        $id = DB::table('baggage')->insertGetId(['tag' => 'BGD'.strtoupper(Str::random(11)), 'request_id' => $request, 'booking_id' => $b->id, 'weight' => 12 + $i * 2.25, 'status' => $end, 'custody' => $f->destination.($end === 'MISHANDLED' ? '-LOST-FOUND' : '-ARRIVALS'), 'created_at' => $created, 'updated_at' => $received]);
                        foreach (['TAGGED', 'ACCEPTED', 'SCREENED', 'LOADED', $end] as $index => $status) {
                            $arrival = $index === 4;
                            $airport = $arrival && $end !== 'MISHANDLED' ? $f->destination : $f->origin;
                            DB::table('baggage_events')->insert(['id' => (string) Str::uuid(), 'baggage_id' => $id, 'status' => $status, 'location' => $airport.($arrival ? '-BHS' : '-T1'), 'custody' => $arrival ? $f->destination.($end === 'MISHANDLED' ? '-LOST-FOUND' : '-ARRIVALS') : $f->origin.'-BHS', 'actor_id' => $this->user('airport_agent', $airport)->id, 'created_at' => $arrival ? $received : $created->copy()->addMinutes($index * 15)]);
                        }
                    });
                }
            }

            return true;
        });
        $this->step('enrich:ground-v3', function () {
            $db = DB::connection('ops');
            foreach ($db->table('flights')->where('number', 'like', 'AS8%')->get() as $f) {
                foreach (['Kiểm tra và tiếp nhiên liệu', 'Vệ sinh, suất ăn và nước sạch', 'Đối chiếu tải trọng, chằng buộc hành lý'] as $i => $title) {
                    if ($db->table('ground_tasks')->where('flight_id', $f->id)->where('title', $title)->exists()) {
                        continue;
                    }
                    $past = in_array($f->status, ['ARRIVED', 'DEPARTED']);
                    $db->table('ground_tasks')->insert(['flight_id' => $f->id, 'title' => $title, 'assigned_to' => $this->user('ground_agent', $f->origin)->id, 'due_at' => Carbon::parse($f->departure)->subMinutes(30 - $i * 5), 'mandatory' => true, 'status' => $past ? 'COMPLETED' : 'PENDING', 'evidence' => $past ? 'Phiếu phục vụ DEMO: hoàn tất kiểm tra và bàn giao trước giờ khởi hành.' : null, 'completed_at' => $past ? Carbon::parse($f->departure)->subMinutes(45 - $i * 5) : null, 'created_at' => Carbon::parse($f->departure)->subDays(2), 'updated_at' => $past ? $f->departure : now()]);
                }
                foreach (['STAND' => 'P', 'COUNTER' => 'C'] as $type => $prefix) {
                    $resources = $db->table('airport_resources')->where('airport_code', $f->origin)->where('type', $type)->pluck('id');
                    if ($db->table('resource_allocations')->where('flight_id', $f->id)->whereIn('resource_id', $resources)->exists()) {
                        continue;
                    }
                    $from = Carbon::parse($f->departure)->subMinutes(100);
                    $to = Carbon::parse($f->departure)->addMinutes(15);
                    foreach ($resources as $id) {
                        if ($db->table('resource_allocations')->where('resource_id', $id)->where('starts_at', '<', $to)->where('ends_at', '>', $from)->exists()) {
                            continue;
                        }
                        $db->table('resource_allocations')->insert(['flight_id' => $f->id, 'resource_id' => $id, 'starts_at' => $from, 'ends_at' => $to, 'actor_id' => $this->user('operations', $f->origin)->id, 'reason' => 'Phân bổ '.($type === 'STAND' ? 'vị trí đỗ' : 'quầy thủ tục').' thử nghiệm', 'created_at' => now(), 'updated_at' => now()]);
                        break;
                    }
                }
            }

            return true;
        });
        $this->scenario('flight-milestones', 'Các chuyến đã đóng cửa, đang bay và đã đến để thử hoàn tất khai thác.', function () {
            $flights = [];
            foreach (['GATE_CLOSED' => 5, 'DEPARTED' => -60, 'ARRIVED' => -300] as $status => $offset) {
                $type = ['GATE_CLOSED' => 'B787', 'DEPARTED' => 'A350', 'ARRIVED' => 'ATR72'][$status];
                $f = $this->serviceFlight('AS'.(9930 + count($flights)), $offset, $status, $type);
                $this->manifest($f, 12, 900 + count($flights));
                $flights[$status] = $f->number;
            }

            return $flights;
        });
        $this->scenario('flight-cancelled', 'Chuyến đã hủy, vé được chuyển sang quy trình hoàn tiền tự động.', function () {
            $f = $this->serviceFlight('AS9940', 10 * 1440 + 300);
            $b = $this->paid($this->user('passenger.3'), $f, 'flight-cancelled');
            $ops = app(OperationsService::class);
            $plan = $ops->execute($this->user('operations', 'HAN'), 'disruption', ['flight_id' => $f->id, 'type' => 'CANCEL', 'delay_minutes' => 0, 'reason' => 'Hủy chuyến mô phỏng để kiểm tra thông báo và hoàn tiền']);
            $ops->execute($this->user('ops_manager', 'HAN'), 'approve_disruption', ['disruption_id' => $plan->id]);
            $this->relay();

            return ['flight' => $f->number, 'reference' => $b->reference, 'booking_id' => $b->id, 'email' => $this->user('passenger.3')->email];
        });
        $this->scenario('flight-delayed', 'Chuyến đã chậm 60 phút, cần phân bổ cửa theo giờ mới.', function () {
            $f = $this->serviceFlight('AS9941', 12 * 1440 + 300);
            $ops = app(OperationsService::class);
            foreach (DB::connection('ops')->table('resource_allocations')->where('flight_id', $f->id)->pluck('id') as $id) {
                $ops->execute($this->user('operations', 'HAN'), 'unallocate', ['allocation_id' => $id, 'reason' => 'Gỡ cửa trước khi áp dụng phương án trễ']);
            }
            $plan = $ops->execute($this->user('operations', 'HAN'), 'disruption', ['flight_id' => $f->id, 'type' => 'DELAY', 'delay_minutes' => 60, 'reason' => 'Chậm chuyến mô phỏng do thời tiết tại sân bay đến']);
            $ops->execute($this->user('ops_manager', 'HAN'), 'approve_disruption', ['disruption_id' => $plan->id]);
            $this->relay();

            return ['flight' => $f->number, 'flight_id' => $f->id];
        });
        foreach (['held', 'expired', 'cancelled', 'declined', 'momo-ready'] as $stage) {
            $this->scenario('booking-'.$stage, 'Đặt chỗ thử nghiệm: '.$stage.'.', function () use ($stage) {
                $f = DB::connection('ops')->table('flights')->where('number', 'AS9903')->first();
                $u = $this->user('passenger');
                $b = app(BookingService::class)->hold($u->id, $f->id, 'demo-state-'.$stage);
                if ($stage === 'cancelled') {
                    app(BookingService::class)->cancel($b->id, $u->id);
                }
                if ($stage === 'expired') {
                    DB::table('bookings')->where('id', $b->id)->update(['expires_at' => now()->subMinute()]);
                    app(BookingService::class)->expire();
                }
                if (in_array($stage, ['declined', 'momo-ready'])) {
                    app(CheckoutService::class)->start($u, $b->id, $stage === 'declined' ? 'VNPAY' : 'MOMO', '127.0.0.1');
                    $this->relay();
                }
                if ($stage === 'declined') {
                    $intent = DB::connection('finance')->table('checkout_intents')->where('booking_id', $b->id)->first();
                    $data = ['vnp_TmnCode' => config('payments.vnpay.tmn_code'), 'vnp_TxnRef' => $intent->id, 'vnp_Amount' => $intent->amount * 100, 'vnp_CurrCode' => 'VND', 'vnp_ResponseCode' => '24', 'vnp_TransactionStatus' => '02', 'vnp_TransactionNo' => 'DEMO-DECLINED-'.$b->reference];
                    $data['vnp_SecureHash'] = GatewayPayments::vnpaySignature($data, config('payments.vnpay.hash_secret'));
                    app(GatewayPayments::class)->vnpay($data);
                    $this->relay();
                }

                return ['reference' => $b->reference, 'booking_id' => $b->id, 'email' => $u->email];
            });
        }
        $this->step('enrich:checkouts-final-v3', $backfillCheckouts);
    }

    private function moreScenarios(object $source, object $checkinFlight, object $boardingFlight): void
    {
        $ops = app(OperationsService::class);
        foreach (['DRAFT', 'PENDING'] as $i => $state) {
            $this->scenario('schedule-'.strtolower($state), 'Lịch '.$state.' có tổ bay hợp lệ, chờ trình/duyệt.', function () use ($state, $i) {
                $f = $this->serviceFlight('AS'.(9910 + $i), (5 + $i) * 1440 + 300, $state);

                return ['flight' => $f->number, 'flight_id' => $f->id];
            });
        }
        foreach (['AIRCRAFT', 'CREW'] as $kind) {
            $this->scenario('change-'.strtolower($kind), 'Phương án thay '.($kind === 'AIRCRAFT' ? 'tàu' : 'tổ bay').' chờ quản lý duyệt độc lập.', function () use ($source, $checkinFlight, $kind) {
                $f = $kind === 'AIRCRAFT' ? $source : $checkinFlight;
                $ids = DB::connection('ops')->table('crew_members')->where('code', 'like', 'DEMO-RES-A320-2-%')->pluck('id')->all();
                $plan = app(FlightChangeService::class)->propose($kind === 'AIRCRAFT' ? $this->user('operations', 'HAN') : $this->user('crew_scheduler'), ['flight_id' => $f->id, 'kind' => $kind, 'target_aircraft_id' => $this->rotations[1]['plane']->id, 'crew_ids' => implode(',', $ids), 'reason' => 'Phương án dự phòng thử nghiệm, giữ nguyên năng định và thời gian nghỉ']);

                return ['flight' => $f->number, 'flight_id' => $f->id, 'change_id' => $plan->id];
            });
        }
        foreach (['DELAY', 'CANCEL'] as $i => $type) {
            $this->scenario('disruption-'.strtolower($type), 'Phương án '.($type === 'DELAY' ? 'chậm 45 phút' : 'hủy chuyến').' chờ duyệt.', function () use ($type, $i, $ops) {
                $f = $this->serviceFlight('AS'.(9920 + $i), (8 + $i) * 1440 + 300);
                if ($type === 'DELAY') {
                    $allocations = DB::connection('ops')->table('resource_allocations')->where('flight_id', $f->id)->pluck('id');
                    foreach ($allocations as $id) {
                        $ops->execute($this->user('operations', 'HAN'), 'unallocate', ['allocation_id' => $id, 'reason' => 'Gỡ cửa cũ để thử điều phối giờ chậm chuyến']);
                    }
                }
                $plan = $ops->execute($this->user('operations', 'HAN'), 'disruption', ['flight_id' => $f->id, 'type' => $type, 'delay_minutes' => $type === 'DELAY' ? 45 : 0, 'reason' => 'Thời tiết bất lợi giả lập để kiểm thử phê duyệt gián đoạn']);

                return ['flight' => $f->number, 'flight_id' => $f->id, 'disruption_id' => $plan->id];
            });
        }
        foreach (['OPEN', 'COMPLETED', 'RELEASED'] as $i => $state) {
            $this->scenario('maintenance-'.strtolower($state), 'Lệnh bảo dưỡng '.$state.', ba kỹ thuật viên độc lập.', function () use ($ops, $i, $state) {
                $plane = DB::connection('ops')->table('aircraft')->where('registration', 'VN-D30'.($i + 1))->first();
                $work = $ops->execute($this->user('maintenance'), 'work_order', ['aircraft_id' => $plane->id, 'title' => 'Kiểm tra định kỳ hệ thống phanh và càng - DEMO', 'due_at' => now()->addDays(2)->toIso8601String()]);
                if ($state !== 'OPEN') {
                    $ops->execute($this->users['maintenance.2'], 'complete_work', ['work_order_id' => $work->id, 'evidence' => 'Biên bản thử nghiệm: kiểm tra đạt, thiết bị đo giả lập, không sử dụng khai thác thật.']);
                }
                if ($state === 'RELEASED') {
                    $ops->execute($this->users['maintenance.3'], 'release_work', ['work_order_id' => $work->id]);
                }

                return ['aircraft' => $plane->registration, 'work_order_id' => $work->id];
            });
        }
        foreach (['airports', 'routes', 'airport_resources', 'aircraft', 'crew_members'] as $entity) {
            $this->scenario('master-'.$entity, 'Phiên bản danh mục '.$entity.' chờ người thứ hai duyệt.', function () use ($entity) {
                $db = DB::connection('ops');
                [$id, $changes, $role] = match ($entity) {
                    'airports' => ['VDO', ['name' => 'Vân Đồn', 'city' => 'Quảng Ninh', 'timezone' => 'Asia/Ho_Chi_Minh'], 'data_admin'],
                    'routes' => [$db->table('routes')->where('origin', 'VCA')->where('destination', 'VKG')->value('id'), ['active' => true, 'duration_minutes' => 45, 'distance_km' => 75], 'data_admin'],
                    'airport_resources' => [$db->table('airport_resources')->where('airport_code', 'HAN')->where('code', 'C16')->value('id'), ['active' => false], 'data_admin'],
                    'aircraft' => [$this->reserves['A350']['plane']->id, ['type' => 'A350', 'capacity' => 300], 'fleet_manager'],
                    'crew_members' => [$db->table('crew_members')->where('code', 'DEMO-LICENSE-SOON')->value('id'), ['qualification' => 'A320', 'license_expires' => now()->addYear()->toDateString(), 'available' => true], 'crew_scheduler'],
                };
                $change = app(MasterDataService::class)->propose($this->user($role), ['entity' => $entity, 'record_id' => (string) $id, 'changes' => $changes, 'reason' => 'Cập nhật danh mục thử nghiệm, cần người phê duyệt độc lập']);

                return ['change_id' => $change->id, 'record_id' => $id];
            });
        }
        $this->scenario('ground-tasks', 'Nhiệm vụ mặt đất ở ba trạng thái; có việc quá hạn để kiểm tra cảnh báo.', function () use ($checkinFlight, $boardingFlight, $ops) {
            $ids = [];
            foreach (['Kiểm tra an ninh khoang khách', 'Tiếp nhiên liệu', 'Vệ sinh khoang hành khách', 'Cấp suất ăn', 'Đối chiếu tải trọng và hành lý', 'Kiểm tra xe thang và GPU'] as $i => $title) {
                $f = $i < 3 ? $boardingFlight : $checkinFlight;
                $task = $ops->execute($this->user('operations', 'HAN'), 'ground_task', ['flight_id' => $f->id, 'title' => $title, 'assigned_to' => $this->user('ground_agent', 'HAN')->id, 'due_at' => ($i === 0 ? now()->subMinutes(10) : Carbon::parse($f->departure)->subMinutes(20))->toIso8601String(), 'mandatory' => $i > 0]);
                if ($i % 3 > 0) {
                    $ops->execute($this->user('ground_agent', 'HAN'), 'ground_state', ['task_id' => $task->id, 'status' => 'IN_PROGRESS']);
                }
                if ($i % 3 === 2) {
                    $ops->execute($this->user('ground_agent', 'HAN'), 'ground_state', ['task_id' => $task->id, 'status' => 'COMPLETED', 'evidence' => 'Đã kiểm tra và bàn giao đầy đủ theo phiếu phục vụ thử nghiệm.']);
                }
                $ids[] = $task->id;
            }

            return ['task_ids' => $ids];
        });
        foreach (['OPEN', 'IN_PROGRESS', 'RESOLVED'] as $i => $state) {
            $this->scenario('support-'.strtolower($state), 'Yêu cầu hỗ trợ '.$state.' có lịch sử hội thoại.', function () use ($i, $state) {
                $p = $this->user('passenger'.($i ? '.'.($i + 1) : ''));
                $svc = app(CustomerService::class);
                $case = $svc->create($p, ['type' => ['ASSISTANCE', 'COMPLAINT', 'BAGGAGE'][$i], 'subject' => ['Yêu cầu hỗ trợ xe lăn tại cửa khởi hành', 'Cần giải thích thay đổi giờ bay', 'Tra cứu hành lý đã bàn giao'][$i], 'message' => 'Đây là yêu cầu thử nghiệm. Vui lòng kiểm tra thông tin phục vụ và phản hồi trong hệ thống.']);
                if ($state !== 'OPEN') {
                    $svc->respond($this->user('customer_service'), $case->id, ['status' => $state, 'response' => 'Đã tiếp nhận và kiểm tra với bộ phận phục vụ; nội dung này dùng cho kiểm thử hội thoại.']);
                }
                if ($state === 'IN_PROGRESS') {
                    $svc->followup($p, $case->id, 'Tôi đã nhận phản hồi, xin bổ sung thông tin tại quầy phục vụ.');
                }

                return ['case_id' => $case->id, 'email' => $p->email];
            });
        }
        $this->scenario('iot', 'Thiết bị mô phỏng có dữ liệu bình thường, vượt ngưỡng và đến trễ.', function () {
            $ids = [];
            foreach (['HAN', 'SGN', 'DAD'] as $airport) {
                foreach (['BHS-TEMP' => 50, 'GPU-LOAD' => 80] as $code => $threshold) {
                    $svc = app(AnalyticsService::class);
                    $device = $svc->device($this->user('integration_admin', $airport), ['code' => 'DEMO-'.$airport.'-'.$code, 'airport_code' => $airport, 'threshold' => $threshold]);
                    foreach ([1 => -10, 2 => 8, 3 => -5] as $seq => $delta) {
                        $svc->telemetry($this->user('integration_admin', $airport), ['event_id' => (string) Str::uuid(), 'device_id' => $device->id, 'sequence' => $seq, 'value' => $threshold + $delta, 'event_time' => now()->subMinutes($seq === 3 ? 10 : 0)->toIso8601String()]);
                    }
                    $ids[] = $device->id;
                }
            }

            return ['device_ids' => $ids];
        });
        $this->scenario('reconciliation-closed', 'Kỳ lịch sử khớp số liệu và đã khóa bởi người thứ hai.', function () {
            $fin = app(FinanceService::class);
            $day = $this->anchor()->subDays(7)->toDateString();
            $run = $fin->reconcile($this->user('finance'), $day, $day);
            $fin->close($this->users['finance.2'], $run->id);

            return ['run_id' => $run->id, 'period' => $day];
        });
        $this->scenario('reconciliation-open', 'Kỳ hiện tại có giao dịch đang xử lý để thử phân loại sai lệch và khóa kỳ.', function () {
            $run = app(FinanceService::class)->reconcile($this->user('finance'), $this->anchor()->toDateString(), $this->anchor()->toDateString());

            return ['run_id' => $run->id];
        });
    }

    private function verify(): void
    {
        $db = DB::connection('ops');
        $flights = $db->table('flights')->where('number', 'like', 'AS8%')->orWhere('number', 'like', 'AS99%')->get()->keyBy('id');
        DB::table('bookings')->where(fn ($q) => $q->where('idempotency_key', 'like', 'demo-%')->orWhere('idempotency_key', 'like', 'exchange-%'))
            ->select('id', 'reference', 'flight_id', 'status', 'amount')->chunkById(500, function ($bookings) use ($flights) {
                $payments = DB::connection('finance')->table('payments')->whereIn('booking_id', $bookings->pluck('id'))->get(['booking_id', 'amount', 'mode'])->keyBy('booking_id');
                $tickets = DB::table('tickets')->whereIn('booking_id', $bookings->pluck('id'))->pluck('booking_id')->flip();
                foreach ($bookings as $b) {
                    if (! $flights->has($b->flight_id)) {
                        throw new \RuntimeException('Missing flight for booking '.$b->reference);
                    }
                    if (in_array($b->status, ['CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'REFUNDED', 'EXCHANGE_PENDING'])) {
                        $payment = $payments->get($b->id);
                        if (! $payment || $payment->amount !== $b->amount || ! $tickets->has($b->id) || $payment->mode !== 'mock') {
                            throw new \RuntimeException('Inconsistent ticket/payment for '.$b->reference);
                        }
                    }
                }
            });
        $usedByFlight = DB::table('bookings')->whereIn('status', ['HELD', 'PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'EXCHANGE_PENDING'])
            ->selectRaw('flight_id, COUNT(*) AS occupied')->groupBy('flight_id')->pluck('occupied', 'flight_id');
        foreach (DB::table('inventory')->whereIn('flight_id', $flights->keys())->get() as $stock) {
            if (($usedByFlight[$stock->flight_id] ?? 0) > $stock->capacity) {
                throw new \RuntimeException('Overbooked fixture flight '.$stock->flight_id);
            }
        }
        foreach (['ops' => ['airports', 'routes', 'airport_resources', 'aircraft', 'crew_members', 'crew_assignments', 'flights', 'resource_allocations', 'ground_tasks', 'work_orders', 'disruptions', 'flight_changes', 'master_changes', 'devices', 'telemetry'], 'mysql' => ['users', 'inventory', 'fare_classes', 'bookings', 'tickets', 'boarding_passes', 'baggage', 'baggage_events', 'support_cases', 'support_messages', 'notifications', 'exchange_requests', 'checkout_sessions'], 'finance' => ['payments', 'refunds', 'ledger_entries', 'reconciliation_runs', 'reconciliation_entries', 'checkout_intents']] as $node => $tables) {
            foreach ($tables as $table) {
                $this->state['counts'][$table] = DB::connection($node)->table($table)->count();
            }
        }
    }

    private function report(): void
    {
        $report = "# Bộ dữ liệu thử nghiệm sân bay\n\nTạo lúc ".$this->anchor()->tz('Asia/Ho_Chi_Minh')->format('H:i d/m/Y')." (giờ Việt Nam).\n\n";
        $report .= "Tất cả hành khách, nhân viên, chuyến bay, số đăng ký tàu, giao dịch và tài khoản ngân hàng là mô phỏng. Tên sân bay được dùng làm danh mục tham khảo; thời gian bay, khoảng cách, sức chứa và giá là giả định kiểm thử.\n\n";
        $report .= "Ứng dụng: http://localhost:8000 — Mailpit: http://localhost:8025\n\nMật khẩu chung: xem `LOCAL_DEMO_PASSWORD` trong `backend/.env`. Tài khoản quản trị cũ giữ nguyên.\n\n";
        $report .= "## Tài khoản chính\n\n| Email | Vai trò | Sân bay |\n|---|---|---|\n";
        foreach ($this->state['accounts'] as $key => $account) {
            if (preg_match('/^p\d+$/', $key) || ($account['airport'] && ! in_array($account['airport'], ['HAN', 'SGN', 'DAD']))) {
                continue;
            }
            $report .= '| '.$account['email'].' | '.$account['role'].' | '.($account['airport'] ?? 'Toàn hệ thống').' |'."\n";
        }
        $report .= "\nDanh sách đầy đủ: `accounts.csv` cùng thư mục; các tài khoản ở sân bay khác dùng mẫu `demo.VAI_TRO.ma_san_bay@airline.test`.\n\n## Tình huống sẵn sàng\n\n";
        foreach ($this->state['scenarios'] as $key => $scenario) {
            $report .= '### '.$key."\n\n".$scenario['description']."\n\n";
            foreach ($scenario['data'] as $field => $value) {
                $report .= '- '.$field.': `'.(is_array($value) ? implode(', ', $value) : $value)."`\n";
            }
            if (isset($scenario['data']['booking_id'])) {
                $report .= '- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/'.$scenario['data']['booking_id']."\n";
            }
            $report .= "\n";
        }
        $report .= "## Số lượng bản ghi\n\n| Bảng | Số lượng |\n|---|---:|\n";
        foreach ($this->state['counts'] as $table => $count) {
            $report .= "| $table | $count |\n";
        }
        $report .= "\n## Lưu ý thời gian\n\nAS9901 khởi hành sau thời điểm tạo 55 phút: quét QR trong khoảng -60 đến -10 phút; check-in đóng trước 30 phút. AS9902 còn trong cửa sổ check-in 24 giờ. Giữ chỗ/đổi vé chờ duyệt hết hạn sau 10 phút theo nghiệp vụ thật; nếu đã hết hạn, tạo yêu cầu mới từ vé đã xác nhận. Các chuyến AS800–AS843 mở bán trong 14 ngày tới để tiếp tục thử. Chạy lại lệnh giữ nguyên kết quả thử nghiệm, không đặt lại trạng thái.\n";
        File::put($this->directory.'/report.md', $report);
        File::put($this->directory.'/scenarios.json', json_encode($this->state['scenarios'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $csv = fopen($this->directory.'/accounts.csv', 'w');
        fwrite($csv, "\xEF\xBB\xBF");
        fputcsv($csv, ['email', 'role', 'airport', 'name'], ',', '"', '');
        foreach ($this->state['accounts'] as $a) {
            fputcsv($csv, [$a['email'], $a['role'], $a['airport'], $a['name']], ',', '"', '');
        }
        fclose($csv);
    }
}
