<?php

namespace App\Modules\Boarding;

use App\Models\User;
use App\Modules\Booking\CommerceRepository;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class BoardingOperations
{
    public static function window(object $flight): array
    {
        $departure = Carbon::parse($flight->departure);

        return ['opens' => $flight->checkin_opens_at ? Carbon::parse($flight->checkin_opens_at) : $departure->copy()->subHours(24), 'closes' => ($flight->checkin_closes_at ? Carbon::parse($flight->checkin_closes_at) : $departure->copy()->subMinutes(30))->min($departure->copy()->subMinutes(10))];
    }

    public static function canCheckin(object $flight): bool
    {
        $w = self::window($flight);

        return $flight->checkin_mode !== 'CLOSED' && in_array($flight->status, ['SCHEDULED', 'DELAYED', 'BOARDING']) && now()->gte($w['opens']) && now()->lt($w['closes']);
    }

    public function control(User $u, int $id, array $input): object
    {
        Access::role($u, 'airport_agent', 'operations', 'ops_manager');
        $d = Validator::make($input, ['mode' => 'required|in:AUTO,OPEN,CLOSED', 'opens_at' => 'nullable|date', 'closes_at' => 'nullable|date', 'reason' => 'required|string|between:5,255'])->validate();
        $flight = app(FlightCatalog::class)->get($id);
        Access::airport($u, $flight->origin);

        return DB::transaction(function () use ($u, $id, $d) {
            app(CommerceRepository::class)->lockInventory($id);

            return DB::connection('ops')->transaction(function () use ($u, $id, $d) {
                $f = DB::connection('ops')->table('flights')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
                Access::airport($u, $f->origin);
                abort_unless(in_array($f->status, ['SCHEDULED', 'DELAYED', 'BOARDING']) && now()->lt($f->departure), 409, 'Chuyến không còn cho phép cấu hình check-in.');
                $opens = ! empty($d['opens_at']) ? Carbon::parse($d['opens_at'], 'Asia/Ho_Chi_Minh')->utc() : ($d['mode'] === 'OPEN' ? now() : null);
                $closes = ! empty($d['closes_at']) ? Carbon::parse($d['closes_at'], 'Asia/Ho_Chi_Minh')->utc() : null;
                $from = $opens ?? Carbon::parse($f->departure)->subHours(24);
                $to = $closes ?? Carbon::parse($f->departure)->subMinutes(30);
                abort_unless($from->lt($to) && $to->lte(Carbon::parse($f->departure)->subMinutes(10)), 422, 'Giờ mở phải trước giờ đóng; đóng chậm nhất 10 phút trước khởi hành.');
                abort_if($d['mode'] === 'OPEN' && now()->gte($to), 409, 'Khung giờ check-in đã kết thúc.');
                DB::connection('ops')->table('flights')->where('id', $id)->update(['checkin_mode' => $d['mode'], 'checkin_opens_at' => $opens, 'checkin_closes_at' => $closes]);
                Audit::record('checkin.configured', (string) $id, $f->checkin_mode, $d['mode'].': '.$d['reason'], $u->id, 'ops');

                return app(FlightCatalog::class)->get($id);
            });
        }, 3);
    }

    public function snapshot(int $flight): array
    {
        $f = app(FlightCatalog::class)->get($flight);
        $bookings = DB::table('bookings')->where('flight_id', $flight)->whereIn('status', ['CONFIRMED', 'CHECKED_IN', 'BOARDED', 'NO_SHOW'])->orderBy('reference')->get();
        $people = DB::table('reservation_passengers')->whereIn('id', $bookings->pluck('passenger_id')->filter())->get()->keyBy('id');
        $users = User::whereIn('id', $bookings->pluck('user_id')->unique())->pluck('name', 'id');
        $rows = $bookings->map(fn ($b) => ['booking_id' => $b->id, 'reference' => $b->reference, 'name' => $people->get($b->passenger_id)?->name ?? $users->get($b->user_id), 'seat' => $b->seat, 'fare_class' => $b->fare_class, 'status' => $b->status])->all();

        return ['flight' => ['id' => $f->id, 'number' => $f->number, 'origin' => $f->origin, 'destination' => $f->destination, 'departure' => $f->departure, 'gate' => $f->gate, 'aircraft_id' => $f->aircraft_id], 'rows' => $rows];
    }

    public function finalize(User $u, int $flight): object
    {
        Access::role($u, 'airport_agent', 'operations', 'ops_manager');
        Access::airport($u, app(FlightCatalog::class)->get($flight)->origin);

        return DB::transaction(function () use ($u, $flight) {
            app(CommerceRepository::class)->lockInventory($flight);
            $f = app(FlightCatalog::class)->get($flight);
            Access::airport($u, $f->origin);
            abort_unless(in_array($f->status, ['GATE_CLOSED', 'DEPARTED', 'ARRIVED']), 409, 'Đóng cửa lên tàu trước khi chốt danh sách.');
            abort_if(DB::table('bookings')->where('flight_id', $flight)->where('status', 'CHECKED_IN')->exists(), 409, 'Còn hành khách đã check-in nhưng chưa lên tàu; hãy xử lý trường hợp không đến trước khi chốt.');
            $snapshot = $this->snapshot($flight);
            $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $latest = DB::table('boarding_manifests')->where('flight_id', $flight)->orderByDesc('version')->first();
            if ($latest && hash_equals($latest->fingerprint, $fingerprint)) {
                return $latest;
            }
            $id = (string) Str::uuid();
            DB::table('boarding_manifests')->insert(['id' => $id, 'flight_id' => $flight, 'version' => ($latest?->version ?? 0) + 1, 'fingerprint' => $fingerprint, 'snapshot' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)), 'created_by' => $u->id, 'created_at' => now()]);
            Audit::record('manifest.finalized', $id, null, $fingerprint, $u->id);

            return DB::table('boarding_manifests')->find($id);
        }, 3);
    }

    public function read(User $u, string $id): object
    {
        Access::role($u, 'airport_agent', 'operations', 'ops_manager');
        $m = DB::table('boarding_manifests')->find($id) ?? abort(404);
        Access::airport($u, app(FlightCatalog::class)->get($m->flight_id)->origin);
        $m->data = json_decode(Crypt::decryptString($m->snapshot), true, 32, JSON_THROW_ON_ERROR);
        $m->current = hash_equals($m->fingerprint, hash('sha256', json_encode($this->snapshot($m->flight_id), JSON_THROW_ON_ERROR)));
        unset($m->snapshot);

        return $m;
    }
}
