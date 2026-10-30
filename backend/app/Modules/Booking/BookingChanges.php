<?php

namespace App\Modules\Booking;

use App\Models\User;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BookingChanges
{
    public function change(User $u, array $data): object
    {
        Access::role($u, 'passenger');
        $d = Validator::make($data, ['booking_id' => 'required|uuid', 'flight_id' => 'required|integer', 'request_id' => 'required|uuid'])->validate();
        $repo = app(CommerceRepository::class);
        $b = $repo->owned($d['booking_id'], $u->id);
        $old = app(FlightCatalog::class)->get($b->flight_id);
        $target = app(FlightCatalog::class)->get((int) $d['flight_id']);

        return DB::transaction(function () use ($u, $repo, $b, $d, $old, $target) {
            $ids = [$b->flight_id, (int) $d['flight_id']];
            sort($ids);
            foreach (array_unique($ids) as $id) {
                $repo->lockInventory($id);
            }$b = $repo->locked($b->id);
            $replay = DB::table('booking_changes')->where('request_id', $d['request_id'])->first();
            if ($replay) {
                abort_unless($replay->booking_id === $b->id && $replay->flight_id === (int) $d['flight_id'], 409, 'Yêu cầu đổi đã dùng.');

                return $b;
            }
            abort_if(DB::table('exchange_requests')->where('target_booking_id', $b->id)->where('status', 'PENDING')->exists(), 409, 'Không đổi lịch giữ chỗ đang thuộc yêu cầu đổi vé.');
            abort_unless($b->status === 'HELD' && now()->lt($b->expires_at), 409, 'Chỉ đổi lịch giữ chỗ chưa thanh toán còn hiệu lực.');
            abort_unless($old->origin === $target->origin && $old->destination === $target->destination && $target->id !== $b->flight_id, 422, 'Chọn chuyến khác cùng đường bay.');
            abort_unless(in_array($target->status, ['SCHEDULED', 'DELAYED', 'BOARDING']) && now()->lt(Carbon::parse($target->departure)->subMinutes(30)), 409, 'Chuyến mới đã đóng bán.');
            $stock = DB::table('inventory')->where('flight_id', $target->id)->first();
            abort_unless($stock->status === 'OPEN', 409, 'Tồn đang đóng.');
            $active = DB::table('bookings')->where('flight_id', $target->id)->where(function ($q) {
                $q->whereIn('status', ['PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'EXCHANGE_PENDING'])->orWhere(fn ($q) => $q->where('status', 'HELD')->where('expires_at', '>', now()));
            });
            abort_if((clone $active)->count() >= $stock->capacity, 409, 'Chuyến mới đã hết chỗ.');
            $fare = DB::table('fare_classes')->where('flight_id', $target->id)->where('code', $b->fare_class)->first();
            abort_unless($b->fare_class === 'ECONOMY' || $fare, 409, 'Hạng giá chưa mở trên chuyến mới.');
            if ($fare) {
                abort_unless($fare->active, 409, 'Hạng giá đóng.');
                abort_if((clone $active)->where('fare_class', $b->fare_class)->count() >= $fare->quota, 409, 'Hạng giá đã hết chỗ.');
            }
            DB::table('bookings')->where('id', $b->id)->update(['flight_id' => $target->id, 'amount' => $fare?->price ?? $stock->price, 'updated_at' => now()]);
            DB::table('booking_changes')->insert(['request_id' => $d['request_id'], 'booking_id' => $b->id, 'flight_id' => $target->id, 'created_at' => now()]);
            Audit::record('booking.changed', $b->id, (string) $b->flight_id, (string) $target->id, $u->id);
            Events::notify($u->id, 'Đã đổi chuyến giữ chỗ', 'Chuyến mới '.$target->number.'. Thời hạn giữ chỗ giữ nguyên; vui lòng kiểm tra giá mới.');

            return $repo->owned($b->id, $u->id);
        }, 3);
    }
}
