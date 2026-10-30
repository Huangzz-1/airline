<?php

namespace App\Modules\Boarding;

use App\Models\User;
use App\Modules\Booking\CommerceRepository;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Shared\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class BoardingService
{
    public function __construct(private CommerceRepository $repo, private FlightCatalog $catalog) {}

    public function checkin(string $id, int $user, string $seat, ?int $actor = null): object
    {
        $b = $this->repo->owned($id, $user);
        $f = $this->catalog->get($b->flight_id);
        $departure = Carbon::parse($f->departure);
        abort_unless(in_array($f->status, ['SCHEDULED', 'BOARDING', 'DELAYED']) && now()->gte($departure->copy()->subHours(24)) && now()->lt($departure->copy()->subMinutes(30)), 409, 'Ngoài thời gian check-in hoặc chuyến không hợp lệ.');

        return DB::transaction(function () use ($b, $user, $seat, $departure, $actor) {
            $stock = $this->repo->lockInventory($b->flight_id);
            $b = $this->repo->locked($b->id);
            if ($b->status === 'CHECKED_IN') {
                abort_unless($b->seat === $seat, 409, 'Đổi ghế chưa được hỗ trợ.');

                return $this->pass($b->id, $user);
            }
            abort_unless($b->status === 'CONFIRMED', 409, 'Cần vé đã thanh toán để check-in.');
            abort_unless(SeatMap::contains($seat, $stock->capacity), 422, 'Ghế không tồn tại.');
            abort_if(DB::table('bookings')->where('flight_id', $b->flight_id)->where('seat', $seat)->exists(), 409, 'Ghế đã được chọn.');
            DB::table('bookings')->where('id', $b->id)->update(['status' => 'CHECKED_IN', 'seat' => $seat, 'updated_at' => now()]);
            $token = bin2hex(random_bytes(32));
            DB::table('boarding_passes')->updateOrInsert(['booking_id' => $b->id], ['booking_id' => $b->id, 'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token),
                'expires_at' => $departure->copy()->subMinutes(10), 'revoked_at' => null, 'used_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('boarding.checkin', $b->id, 'CONFIRMED', 'CHECKED_IN', $actor ?? $user);

            return $this->pass($b->id, $user);
        }, 3);
    }

    public function pass(string $id, int $user): object
    {
        $this->repo->owned($id, $user);
        $pass = DB::table('boarding_passes')->where('booking_id', $id)->first() ?? abort(404);
        $pass->token = Crypt::decryptString($pass->token_encrypted);
        unset($pass->token_encrypted,$pass->token_hash);

        return $pass;
    }

    public function board(User $agent, int $flight, string $token): object
    {
        abort_unless($agent->role === 'airport_agent', 403);
        $f = $this->catalog->get($flight);
        $departure = Carbon::parse($f->departure);
        abort_unless($agent->airport_code === $f->origin, 403, 'Chuyến ngoài sân bay được phân công.');
        abort_unless($f->status === 'BOARDING' && now()->gte($departure->copy()->subMinutes(60)) && now()->lt($departure->copy()->subMinutes(10)), 409, 'Cửa chưa mở hoặc đã đóng.');

        return DB::transaction(function () use ($agent, $flight, $token) {
            $this->repo->lockInventory($flight);
            $pass = DB::table('boarding_passes')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first() ?? abort(422, 'QR không hợp lệ.');
            $b = $this->repo->locked($pass->booking_id);
            abort_unless($b->flight_id === $flight, 409, 'QR sai chuyến.');
            abort_if($pass->revoked_at || now()->gte($pass->expires_at), 409, 'QR hết hạn hoặc đã thu hồi.');
            abort_if($pass->used_at || $b->status === 'BOARDED', 409, 'QR đã được sử dụng.');
            abort_unless($b->status === 'CHECKED_IN', 409, 'Hành khách chưa đủ điều kiện.');
            DB::table('boarding_passes')->where('id', $pass->id)->update(['used_at' => now(), 'updated_at' => now()]);
            DB::table('bookings')->where('id', $b->id)->update(['status' => 'BOARDED', 'updated_at' => now()]);
            Audit::record('boarding.accepted', $b->id, 'CHECKED_IN', 'BOARDED', $agent->id);

            return (object) ['reference' => $b->reference, 'seat' => $b->seat, 'status' => 'BOARDED'];
        }, 3);
    }
}
