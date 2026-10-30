<?php

namespace App\Modules\Boarding;

use App\Models\User;
use App\Modules\Booking\CommerceRepository;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PassengerChanges
{
    public function seat(User $u, array $data): object
    {
        Access::role($u, 'passenger');
        $d = Validator::make($data, ['booking_id' => 'required|uuid', 'seat' => 'required|string|max:4'])->validate();
        $repo = app(CommerceRepository::class);
        $b = $repo->owned($d['booking_id'], $u->id);
        $f = app(FlightCatalog::class)->get($b->flight_id);
        abort_unless(in_array($f->status, ['SCHEDULED', 'BOARDING', 'DELAYED']) && now()->lt(Carbon::parse($f->departure)->subMinutes(30)), 409, 'Đã đóng đổi ghế/cấp lại QR.');

        return DB::transaction(function () use ($repo, $u, $b, $d, $f) {
            $stock = $repo->lockInventory($b->flight_id);
            $b = $repo->locked($b->id);
            abort_unless($b->status === 'CHECKED_IN', 409, 'Chỉ đổi ghế khi đã check-in và chưa boarding.');
            abort_unless(SeatMap::contains($d['seat'], $stock->capacity), 422, 'Ghế không hợp lệ.');
            abort_if(DB::table('bookings')->where('flight_id', $b->flight_id)->where('id', '!=', $b->id)->where('seat', $d['seat'])->exists(), 409, 'Ghế đã có người.');
            $token = bin2hex(random_bytes(32));
            DB::table('bookings')->where('id', $b->id)->update(['seat' => $d['seat'], 'updated_at' => now()]);
            DB::table('boarding_passes')->where('booking_id', $b->id)->update(['token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token), 'expires_at' => Carbon::parse($f->departure)->subMinutes(10), 'revoked_at' => null, 'used_at' => null, 'updated_at' => now()]);
            Audit::record('boarding.reissued', $b->id, $b->seat, $d['seat'], $u->id);
            Events::notify($u->id, 'Đã cấp lại thẻ lên tàu', 'Ghế mới '.$d['seat'].'. QR cũ không còn hiệu lực.');

            return (object) ['seat' => $d['seat'], 'token' => $token];
        }, 3);
    }

    public function offload(User $u, array $data): object
    {
        Access::role($u, 'airport_agent');
        $d = Validator::make($data, ['booking_id' => 'required|uuid', 'reason' => 'required|string|between:10,255'])->validate();
        $b = DB::table('bookings')->find($d['booking_id']) ?? abort(404);
        $f = app(FlightCatalog::class)->get($b->flight_id);
        Access::airport($u, $f->origin);
        abort_unless(in_array($f->status, ['BOARDING', 'GATE_CLOSED']), 409, 'Chỉ xử lý no-show/offload trước khởi hành.');

        return DB::transaction(function () use ($u, $b, $d, $f) {
            $repo = app(CommerceRepository::class);
            $repo->lockInventory($b->flight_id);
            $b = $repo->locked($b->id);
            abort_unless(in_array($b->status, ['CONFIRMED', 'CHECKED_IN']), 409, 'Không thể xử lý khách đã boarding hoặc vé không hiệu lực.');
            DB::table('bookings')->where('id', $b->id)->update(['status' => 'NO_SHOW', 'seat' => null, 'updated_at' => now()]);
            DB::table('boarding_passes')->where('booking_id', $b->id)->update(['revoked_at' => now()]);
            foreach (DB::table('baggage')->where('booking_id', $b->id)->whereNotIn('status', ['OFFLOADED', 'DELIVERED'])->lockForUpdate()->get() as $bag) {
                DB::table('baggage')->where('id', $bag->id)->update(['status' => 'OFFLOADED', 'updated_at' => now()]);
                DB::table('baggage_events')->insert(['id' => (string) Str::uuid(), 'baggage_id' => $bag->id, 'status' => 'OFFLOADED', 'location' => $f->origin, 'custody' => $bag->custody, 'actor_id' => $u->id, 'created_at' => now()]);
            }
            Audit::record('boarding.no_show', $b->id, $b->status, $d['reason'], $u->id);
            Events::notify($b->user_id, 'Trạng thái phục vụ chuyến', 'Nhân viên ghi nhận no-show/offload: '.$d['reason']);

            return (object) ['id' => $b->id, 'status' => 'NO_SHOW'];
        }, 3);
    }
}
