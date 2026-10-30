<?php

namespace App\Modules\Booking;

use Illuminate\Support\Facades\DB;

class CommerceRepository
{
    public function owned(string $id, int $user): object
    {
        return DB::table('bookings')->where('id', $id)->where('user_id', $user)->first() ?? abort(404);
    }

    public function lockInventory(int $flight, bool $allowFrozen = false): object
    {
        $stock = DB::table('inventory')->where('flight_id', $flight)->lockForUpdate()->first() ?? abort(409, 'Chuyến chưa mở bán.');
        abort_if(! $allowFrozen && ($stock->frozen_change_id ?? null), 409, 'Chuyến đang đổi tàu; vui lòng chờ hoàn tất.');

        return $stock;
    }

    public function locked(string $id): object
    {
        return DB::table('bookings')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
    }

    public function list(int $user)
    {
        return DB::table('bookings')->where('user_id', $user)->latest()->paginate(10);
    }
}
