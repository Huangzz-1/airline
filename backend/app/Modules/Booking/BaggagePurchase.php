<?php

namespace App\Modules\Booking;

use App\Models\User;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class BaggagePurchase
{
    public function update(User $u, array $input): object
    {
        Access::role($u, 'passenger');
        $d = Validator::make($input, ['booking_id' => 'required|uuid', 'kg' => 'required|integer|in:0,5,10,15,20,25,30,35,40', 'expected_amount' => 'required|integer|min:0'])->validate();
        $repo = app(CommerceRepository::class);
        $b = $repo->owned($d['booking_id'], $u->id);

        return DB::transaction(function () use ($u, $d, $repo, $b) {
            $repo->lockInventory($b->flight_id);
            $b = $repo->locked($b->id);
            abort_unless($b->status === 'HELD' && now()->lt($b->expires_at), 409, 'Chọn hành lý khi giữ chỗ còn hạn và trước khi thanh toán.');
            abort_unless($b->fare_rules, 409, 'Vé cũ chưa lưu định mức hành lý; vui lòng liên hệ hỗ trợ.');
            abort_if(DB::table('checkout_sessions')->where('booking_id', $b->id)->exists() || DB::table('exchange_requests')->where('target_booking_id', $b->id)->whereIn('status', ['PENDING', 'PROCESSING'])->exists(), 409, 'Yêu cầu thanh toán/đổi vé đã được chốt.');
            if ($b->reservation_id) {
                DB::table('reservations')->where('id', $b->reservation_id)->lockForUpdate()->first();
                abort_if(DB::table('checkout_sessions')->whereIn('booking_id', DB::table('bookings')->where('reservation_id', $b->reservation_id)->select('id'))->exists(), 409, 'Mã đặt chỗ đã có yêu cầu thanh toán.');
            }
            $rules = FareRules::decode($b->fare_rules);
            abort_if($rules['included_baggage_kg'] + $d['kg'] > 64, 422, 'Tổng hạn mức hành lý tối đa 64 kg mỗi khách trên một chuyến.');
            $extra = $d['kg'] * $rules['extra_baggage_price_per_kg'];
            $base = $b->base_amount ?? ($b->amount - $b->baggage_amount - $b->change_fee_amount);
            $amount = $base + $extra + $b->change_fee_amount;
            abort_unless($amount === (int) $d['expected_amount'], 409, 'Giá hành lý đã thay đổi. Vui lòng kiểm tra lại.');
            DB::table('bookings')->where('id', $b->id)->update(['base_amount' => $base, 'baggage_kg' => $d['kg'], 'baggage_amount' => $extra, 'amount' => $amount, 'updated_at' => now()]);
            if ($b->reservation_id) {
                DB::table('reservations')->where('id', $b->reservation_id)->update(['total_amount' => DB::table('bookings')->where('reservation_id', $b->reservation_id)->where('reservation_initial', true)->sum('amount'), 'updated_at' => now()]);
            }
            Audit::record('baggage.package_changed', $b->id, (string) $b->baggage_kg, (string) $d['kg'], $u->id);

            return $repo->owned($b->id, $u->id);
        }, 3);
    }
}
