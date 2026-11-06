<?php

namespace App\Http\Controllers;

use App\Modules\Booking\ReservationService;
use App\Modules\Finance\CheckoutService;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Flights\FlightCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    public function index(Request $r)
    {
        return response()->json(DB::table('reservations')->where('user_id', $r->user()->id)->latest()->paginate(10));
    }

    public function plan(Request $r, FlightCatalog $catalog)
    {
        $d = $r->validate(['trip_type' => 'nullable|in:ONE_WAY,ROUND_TRIP', 'passenger_count' => 'nullable|integer|in:1', 'flight_id' => 'nullable|integer', 'fare_class' => 'nullable|string|regex:/^[A-Z0-9_]{1,20}$/']);
        $query = ['trip_type' => $d['trip_type'] ?? 'ONE_WAY', 'passenger_count' => $d['passenger_count'] ?? 1];
        if (! empty($d['flight_id'])) {
            $flight = $catalog->get((int) $d['flight_id']);
            $query += ['flights' => $flight->id, 'fare_class' => $d['fare_class'] ?? 'ECONOMY', 'return_date' => Carbon::parse($flight->departure)->tz('Asia/Ho_Chi_Minh')->addDays(3)->toDateString()];

            return redirect('/trip/select?'.http_build_query($query));
        }

        return redirect('/?'.http_build_query($query));
    }

    public function store(Request $r, ReservationService $service)
    {
        $r->validate(['segments' => 'required|array|min:1|max:6']);
        $input = $r->all();
        foreach ($input['segments'] ?? [] as $i => $segment) {
            if (isset($segment['selection']) && is_string($segment['selection']) && preg_match('/^(\d+):([A-Z0-9_]{1,20})$/D', $segment['selection'], $m)) {
                $input['segments'][$i] = ['flight_id' => (int) $m[1], 'fare_class' => $m[2]];
            }
        }
        $reservation = $service->hold($r->user(), $input);

        return $r->expectsJson() ? response()->json($reservation, 201) : redirect('/reservations/'.$reservation->id)->with('message', 'Đã giữ chỗ cho chuyến đi trong 10 phút.');
    }

    public function show(Request $r, string $id, ReservationService $service)
    {
        $reservation = $service->owned($r->user(), $id);
        $passengers = DB::table('reservation_passengers')->where('reservation_id', $id)->orderBy('position')->get()->keyBy('id');
        $segments = DB::table('reservation_segments')->where('reservation_id', $id)->orderBy('position')->get();
        $bookings = DB::table('bookings')->where('reservation_id', $id)->orderBy('reference')->get();
        $flights = DB::connection('ops')->table('flights')->whereIn('id', $bookings->pluck('flight_id'))->get()->keyBy('id');
        $original = $bookings->where('reservation_initial', true);
        $canCancel = $original->contains('status', 'HELD') && $original->every(fn ($b) => in_array($b->status, ['HELD', 'CANCELLED', 'EXPIRED']));
        $status = match (true) {
            $original->contains('status', 'PAYMENT_PENDING') => 'PAYMENT_PENDING',
            $original->every(fn ($b) => $b->status === 'HELD') => now()->lt($reservation->expires_at) ? 'HELD' : 'EXPIRED',
            $original->every(fn ($b) => in_array($b->status, ['CONFIRMED', 'CHECKED_IN', 'BOARDED'])) => 'CONFIRMED',
            $original->every(fn ($b) => $b->status === 'REFUNDED') => 'REFUNDED',
            $original->every(fn ($b) => in_array($b->status, ['EXPIRED', 'CANCELLED'])) => $original->contains('status', 'EXPIRED') ? 'EXPIRED' : 'CANCELLED',
            default => 'PARTIAL',
        };
        $checkout = DB::table('checkout_sessions')->where('booking_id', $original->first()->id)->first();
        $paymentMethods = app(CheckoutService::class)->methods();
        if ($r->expectsJson()) {
            return response()->json(compact('reservation', 'status', 'passengers', 'segments', 'bookings', 'flights', 'checkout'));
        }

        return view('reservation', compact('reservation', 'status', 'passengers', 'segments', 'bookings', 'flights', 'checkout', 'paymentMethods', 'canCancel'));
    }

    public function pay(Request $r, string $id, ReservationService $service, OutboxRelay $relay)
    {
        $d = $r->validate(['method' => 'required|in:VNPAY,MOMO,BANK']);
        $checkout = $service->pay($r->user(), $id, $d['method'], $r->ip());
        $relay->drain();

        return $r->expectsJson() ? response()->json($checkout, 202) : back()->with('message', 'Đã tạo một yêu cầu thanh toán chung cho toàn bộ hành trình.');
    }

    public function cancel(Request $r, string $id, ReservationService $service)
    {
        $service->cancel($r->user(), $id);

        return $r->expectsJson() ? response()->json(['status' => 'CANCELLED']) : back()->with('message', 'Đã hủy giữ chỗ cho toàn bộ mã đặt chỗ.');
    }
}
