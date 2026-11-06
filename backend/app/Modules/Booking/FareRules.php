<?php

namespace App\Modules\Booking;

use App\Models\User;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class FareRules
{
    public const DEFAULTS = ['included_baggage_kg' => 20, 'extra_baggage_price_per_kg' => 30000, 'change_allowed' => true, 'refund_allowed' => true, 'change_fee' => 0, 'refund_fee' => 0, 'change_cutoff_minutes' => 30, 'refund_cutoff_minutes' => 30];

    public static function decode(?string $json): array
    {
        return array_replace(self::DEFAULTS, $json ? json_decode($json, true, 16, JSON_THROW_ON_ERROR) : []);
    }

    public static function snapshot(?object $fare): string
    {
        return json_encode(self::decode($fare?->rules), JSON_THROW_ON_ERROR);
    }

    public static function version(?string $rules): string
    {
        return hash('sha256', json_encode(self::decode($rules), JSON_THROW_ON_ERROR));
    }

    public function update(User $u, array $data): object
    {
        Access::role($u, 'data_admin');
        $d = Validator::make($data, [
            'fare_id' => 'required|integer', 'version' => 'required|string|size:64',
            'included_baggage_kg' => 'required|integer|between:0,64', 'extra_baggage_price_per_kg' => 'required|integer|between:0,1000000',
            'change_allowed' => 'required|boolean', 'refund_allowed' => 'required|boolean',
            'change_fee' => 'required|integer|between:0,100000000', 'refund_fee' => 'required|integer|between:0,100000000',
            'change_cutoff_minutes' => 'required|integer|between:30,10080', 'refund_cutoff_minutes' => 'required|integer|between:30,10080',
        ])->validate();

        return DB::transaction(function () use ($u, $d) {
            $fare = DB::table('fare_classes')->where('id', $d['fare_id'])->lockForUpdate()->first() ?? abort(404);
            abort_unless(hash_equals(self::version($fare->rules), $d['version']), 409, 'Điều kiện vé đã thay đổi. Vui lòng tải lại.');
            $rules = array_intersect_key($d, self::DEFAULTS);
            foreach (self::DEFAULTS as $key => $default) {
                $rules[$key] = is_bool($default) ? (bool) $rules[$key] : (int) $rules[$key];
            }
            abort_if($rules['refund_allowed'] && $rules['refund_fee'] >= $fare->price, 422, 'Phí hoàn phải nhỏ hơn giá vé.');
            DB::table('fare_classes')->where('id', $fare->id)->update(['rules' => json_encode($rules, JSON_THROW_ON_ERROR)]);
            Audit::record('fare.rules_updated', (string) $fare->id, self::version($fare->rules), self::version(json_encode($rules)), $u->id);

            return DB::table('fare_classes')->find($fare->id);
        });
    }

    public static function refundQuote(object $booking, object $flight): array
    {
        $rules = self::decode($booking->fare_rules ?? null);

        return ['allowed' => $rules['refund_allowed'] && in_array($booking->status, ['CONFIRMED', 'CHECKED_IN']) && now()->lt(Carbon::parse($flight->departure)->subMinutes($rules['refund_cutoff_minutes'])) && $booking->amount > $rules['refund_fee'], 'fee' => $rules['refund_fee'], 'amount' => max(0, $booking->amount - $rules['refund_fee']), 'cutoff' => $rules['refund_cutoff_minutes']];
    }

    public static function changeAllowed(object $booking, object $flight): bool
    {
        $rules = self::decode($booking->fare_rules ?? null);

        return $rules['change_allowed'] && now()->lt(Carbon::parse($flight->departure)->subMinutes($rules['change_cutoff_minutes']));
    }
}
