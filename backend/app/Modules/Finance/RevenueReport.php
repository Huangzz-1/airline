<?php

namespace App\Modules\Finance;

use App\Models\User;
use App\Modules\Shared\Access;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class RevenueReport
{
    public function build(User $u, array $input): array
    {
        Access::role($u, 'finance', 'analyst', 'admin');
        $d = Validator::make($input, ['start' => 'required|date_format:Y-m-d', 'end' => 'required|date_format:Y-m-d|after_or_equal:start', 'group' => 'required|in:day,month,route', 'origin' => 'nullable|regex:/^[A-Z]{3}$/', 'destination' => 'nullable|regex:/^[A-Z]{3}$/'])->validate();
        $from = Carbon::parse($d['start'], 'Asia/Ho_Chi_Minh')->startOfDay()->utc();
        $to = Carbon::parse($d['end'], 'Asia/Ho_Chi_Minh')->endOfDay()->utc();
        abort_if($from->diffInDays($to) > 366, 422, 'Báo cáo tối đa 366 ngày.');
        $entries = DB::connection('finance')->table('ledger_entries')->whereBetween('created_at', [$from, $to])->orderBy('id')->get();
        $bookings = DB::table('bookings')->whereIn('id', $entries->pluck('booking_id')->unique())->get()->keyBy('id');
        $flights = DB::connection('ops')->table('flights')->whereIn('id', $bookings->pluck('flight_id')->unique())->get()->keyBy('id');
        $groups = [];
        $totals = ['captured' => 0, 'refunded' => 0, 'adjustments' => 0, 'net' => 0, 'transactions' => 0];
        foreach ($entries as $e) {
            $b = $bookings->get($e->booking_id);
            $f = $b ? $flights->get($b->flight_id) : null;
            if ((! empty($d['origin']) && $f?->origin !== $d['origin']) || (! empty($d['destination']) && $f?->destination !== $d['destination'])) {
                continue;
            }
            $key = $d['group'] === 'route' ? ($f ? $f->origin.' → '.$f->destination : 'Chưa xác định đường bay') : Carbon::parse($e->created_at)->tz('Asia/Ho_Chi_Minh')->format($d['group'] === 'month' ? 'Y-m' : 'Y-m-d');
            $groups[$key] ??= ['label' => $key] + array_fill_keys(array_keys($totals), 0);
            $kind = $e->kind === 'CAPTURE' ? 'captured' : ($e->kind === 'REFUND' ? 'refunded' : 'adjustments');
            $value = $kind === 'refunded' ? -$e->amount : $e->amount;
            $groups[$key][$kind] += $value;
            $groups[$key]['net'] += $e->amount;
            $groups[$key]['transactions']++;
            $totals[$kind] += $value;
            $totals['net'] += $e->amount;
            $totals['transactions']++;
        }
        ksort($groups);

        return ['filters' => $d, 'rows' => array_values($groups), 'totals' => $totals, 'generated_at' => now()->toIso8601String()];
    }
}
