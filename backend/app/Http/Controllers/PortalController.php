<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Boarding\BoardingService;
use App\Modules\Boarding\SeatMap;
use App\Modules\Booking\BookingService;
use App\Modules\Booking\CommerceRepository;
use App\Modules\Booking\TripSelection;
use App\Modules\Finance\CheckoutService;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Flights\Carrier;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Flights\FlightSearch;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Support\StaffDashboard;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PortalController extends Controller
{
    public function flights(Request $r, FlightCatalog $catalog, FlightSearch $search)
    {
        $filters = $r->validate([
            'origin' => 'nullable|string|size:3', 'destination' => 'nullable|string|size:3', 'date' => 'nullable|date_format:Y-m-d',
            'passenger_count' => 'nullable|integer|in:1', 'sort' => 'nullable|in:'.implode(',', array_keys(FlightSearch::SORTS)),
            'trip_type' => 'nullable|in:ONE_WAY,ROUND_TRIP', 'return_date' => 'nullable|date_format:Y-m-d', 'outbound' => 'nullable|string|max:6000',
            'price_min' => 'nullable|integer|min:0|max:9999999999999',
            'price_max' => ['nullable', 'integer', 'min:0', 'max:9999999999999', Rule::when($r->filled('price_min'), 'gte:price_min')],
            'duration_min' => 'nullable|integer|between:0,2880',
            'duration_max' => ['nullable', 'integer', 'between:1,2880', Rule::when($r->filled('duration_min'), 'gte:duration_min')],
            'layover_min' => 'nullable|integer|between:0,1440',
            'layover_max' => ['nullable', 'integer', 'between:0,1440', Rule::when($r->filled('layover_min'), 'gte:layover_min')],
            'available_only' => 'nullable|boolean',
            'fare_classes' => 'nullable|array|max:20', 'fare_classes.*' => 'required|string|regex:/^[A-Z0-9_]{1,20}$/',
            'aircraft_types' => 'nullable|array|max:20', 'aircraft_types.*' => 'required|string|max:255',
            'departure_periods' => 'nullable|array|max:4', 'arrival_periods' => 'nullable|array|max:4',
            'departure_periods.*' => 'required|in:'.implode(',', array_keys(FlightSearch::PERIODS)), 'arrival_periods.*' => 'required|in:'.implode(',', array_keys(FlightSearch::PERIODS)),
            'carriers' => 'nullable|array|max:5', 'carriers.*' => 'required|in:'.implode(',', array_keys(Carrier::NAMES)), 'stops' => 'nullable|array|max:3', 'stops.*' => 'required|in:0,1,2',
            'flight_number' => 'nullable|string|max:20', 'page' => 'nullable|integer|min:1|max:100000',
        ]);
        $filters['trip_type'] ??= 'ONE_WAY';
        $outboundQuote = null;
        if ($filters['trip_type'] === 'ROUND_TRIP' && ! empty($filters['outbound'])) {
            $selection = app(TripSelection::class)->decode($filters['outbound']);
            $selection['passenger_count'] = (int) ($filters['passenger_count'] ?? 1);
            $outboundQuote = app(TripSelection::class)->quote($selection, false);
            $first = $outboundQuote['directions']['outbound'][0]->flight;
            $last = $outboundQuote['directions']['outbound'][count($selection['outbound']) - 1]->flight;
            if (($filters['origin'] ?? '') !== $last->destination || ($filters['destination'] ?? '') !== $first->origin) {
                unset($filters['outbound']);
                $outboundQuote = null;
            } else {
                $filters['outbound'] = app(TripSelection::class)->encode($selection);
                $filters['not_before'] = Carbon::parse($last->arrival)->addMinutes(60)->toDateTimeString();
            }
        } else {
            unset($filters['outbound']);
        }
        if ($filters['trip_type'] === 'ROUND_TRIP' && ! $outboundQuote) {
            $filters['date'] ??= now('Asia/Ho_Chi_Minh')->addDay()->toDateString();
            $filters['return_date'] ??= Carbon::parse($filters['date'])->addDays(3)->toDateString();
            abort_unless($filters['return_date'] >= $filters['date'], 422, 'Ngày về phải từ ngày khởi hành trở đi.');
        }
        $data = $search->search($filters);
        unset($data['filters']['not_before']);
        $data += ['airports' => $catalog->airports(), 'destinationNetwork' => $catalog->destinationNetwork(), 'outboundQuote' => $outboundQuote];
        if ($r->header('X-Flight-Results') === '1') {
            return response()->json(['html' => view('partials.flight-search-results', $data)->render(), 'filters' => $data['filters'], 'total' => $data['flights']->total()]);
        }

        return $r->expectsJson() ? response()->json($data['flights']) : view('flights', $data);
    }

    public function journeys(Request $r, CommerceRepository $repo, FlightCatalog $catalog)
    {
        $filters = $r->validate([
            'q' => 'nullable|string|max:80',
            'status' => 'nullable|in:HELD,PAYMENT_PENDING,CONFIRMED,CHECKED_IN,BOARDED,REFUND_PENDING,REFUNDED,CANCELLED,EXPIRED',
        ]);
        $query = trim((string) ($filters['q'] ?? '')) ?: null;
        $status = $filters['status'] ?? null;
        $bookings = $repo->list($r->user()->id, $status, $query);
        if ($r->expectsJson()) {
            return response()->json($bookings);
        }
        foreach ($bookings as $b) {
            $b->flight = $catalog->get($b->flight_id);
        }

        $reservations = DB::table('reservations')
            ->where('user_id', $r->user()->id)
            ->when($query, fn ($q) => $q->where('reference', 'like', '%'.addcslashes($query, '\\%_').'%'))
            ->when($status, fn ($q) => $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('bookings as filter_bookings')->whereColumn('filter_bookings.reservation_id', 'reservations.id')->where('filter_bookings.status', $status)))
            ->latest()
            ->paginate(10, ['*'], 'reservations_page')
            ->withQueryString();
        $reservationPassengers = DB::table('reservation_passengers')->whereIn('reservation_id', $reservations->pluck('id'))->get()->groupBy('reservation_id');
        $reservationSegments = DB::table('reservation_segments')->whereIn('reservation_id', $reservations->pluck('id'))->get()->groupBy('reservation_id');

        return view('journeys', compact('bookings', 'reservations', 'reservationPassengers', 'reservationSegments', 'filters'));
    }

    public function hold(Request $r, BookingService $service)
    {
        $d = $r->validate(['flight_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|min:8|max:80', 'fare_class' => 'nullable|string|regex:/^[A-Z0-9_]{1,20}$/']);
        $b = $service->hold($r->user()->id, (int) $d['flight_id'], $d['idempotency_key'], $d['fare_class'] ?? 'ECONOMY');

        return $r->expectsJson() ? response()->json($b, 201) : redirect('/journeys/'.$b->id);
    }

    public function show(Request $r, string $id, CommerceRepository $repo, FlightCatalog $catalog, BoardingService $boarding)
    {
        $booking = $repo->owned($id, $r->user()->id);
        $passenger = $booking->passenger_id ? DB::table('reservation_passengers')->find($booking->passenger_id) : $r->user();
        $flight = $catalog->get($booking->flight_id);
        if ($r->expectsJson()) {
            return response()->json($booking);
        }
        $ticket = DB::table('tickets')->where('booking_id', $id)->first();
        $pass = in_array($booking->status, ['CHECKED_IN', 'BOARDED']) ? $boarding->pass($id, $r->user()->id) : null;
        $inventory = DB::table('inventory')->where('flight_id', $flight->id)->first();
        $seatRows = SeatMap::rows($inventory->capacity, $inventory->seat_layout);
        $allowedSeats = SeatMap::seats($inventory->capacity, $inventory->seat_layout, $booking->fare_class);
        $occupied = DB::table('bookings')->where('flight_id', $flight->id)->whereNotNull('seat')->pluck('seat')->all();
        $qr = null;
        if ($pass) {
            $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd);
            $qr = (new Writer($renderer))->writeString($pass->token);
        }

        $exchange = DB::table('exchange_requests')->where('source_booking_id', $id)->orWhere('target_booking_id', $id)->latest()->first();
        $checkout = DB::table('checkout_sessions')->where('booking_id', $id)->first();
        $paymentMethods = app(CheckoutService::class)->methods();
        if ($exchange) {
            $exchange->source_amount = DB::table('bookings')->where('id', $exchange->source_booking_id)->value('amount');
            $exchange->target_amount = DB::table('bookings')->where('id', $exchange->target_booking_id)->value('amount');
        }
        $baggage = DB::table('baggage')->where('booking_id', $id)->get();
        $alternatives = DB::connection('ops')->table('flights')->where('origin', $flight->origin)->where('destination', $flight->destination)->where('id', '!=', $flight->id)->whereIn('status', ['SCHEDULED', 'DELAYED', 'BOARDING'])->where('departure', '>', now()->addMinutes(30))->orderBy('departure')->limit(30)->get();
        foreach ($alternatives as $candidate) {
            $fare = DB::table('fare_classes')->where('flight_id', $candidate->id)->where('code', $booking->fare_class)->where('active', true)->first();
            $candidate->price = $fare?->price ?? DB::table('inventory')->where('flight_id', $candidate->id)->value('price');
        }

        return view('booking', compact('booking', 'passenger', 'flight', 'ticket', 'pass', 'qr', 'inventory', 'occupied', 'seatRows', 'allowedSeats', 'baggage', 'alternatives', 'exchange', 'checkout', 'paymentMethods'));
    }

    public function pay(Request $r, string $id, BookingService $service, OutboxRelay $relay, CommerceRepository $repo)
    {
        if ($r->expectsJson() && $r->has('scenario')) {
            $d = $r->validate(['scenario' => 'required|in:approve,decline']);
            $service->pay($id, $r->user()->id, $d['scenario']);
        } else {
            $d = $r->validate(['method' => 'required|in:VNPAY,MOMO,BANK']);
            app(CheckoutService::class)->start($r->user(), $id, $d['method'], $r->ip());
        }
        // Best effort immediate delivery; durable worker handles a network outage.
        $relay->drain();
        $b = $repo->owned($id, $r->user()->id);

        return $r->expectsJson() ? response()->json($b, $b->status === 'PAYMENT_PENDING' ? 202 : 200) : back()->with('message', $b->status === 'CONFIRMED' ? 'Thanh toán thành công. Vé đã được xuất.' : 'Đã tiếp nhận thanh toán. Vui lòng theo dõi trạng thái đặt chỗ.');
    }

    public function cancel(Request $r, string $id, BookingService $service)
    {
        $b = $service->cancel($id, $r->user()->id);

        return $r->expectsJson() ? response()->json($b) : back()->with('message', 'Đã hủy giữ chỗ.');
    }

    public function checkin(Request $r, string $id, BoardingService $service)
    {
        $d = $r->validate(['seat' => 'required|string|max:4']);
        $pass = $service->checkin($id, $r->user()->id, $d['seat']);

        return $r->expectsJson() ? response()->json($pass) : back()->with('message', 'Check-in thành công.');
    }

    public function board(Request $r, BoardingService $service)
    {
        $d = $r->validate(['flight_id' => 'required|integer', 'token' => 'required|string|size:64']);
        $result = $service->board($r->user(), (int) $d['flight_id'], $d['token']);

        return $r->expectsJson() ? response()->json($result) : back()->withInput(['flight_id' => $d['flight_id']])->with('message', 'Đã xác nhận boarding · Hành khách '.$result->reference.' · ghế '.$result->seat);
    }

    public function staff(Request $r)
    {
        $data = app(StaffDashboard::class)->data($r->user());
        if ($r->user()->role === 'admin') {
            $d = $r->validate(['q' => 'nullable|string|max:100']);
            $data['users'] = User::query()
                ->when($d['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')))
                ->orderBy('id')->paginate(15)->withQueryString();
            $data['airports'] = DB::connection('ops')->table('airports')->get();
        }
        if (in_array($r->user()->role, ['finance', 'admin'], true)) {
            $payments = DB::connection('finance')->table('payments')->latest()->paginate(15);
            $bookings = DB::table('bookings')->whereIn('id', $payments->pluck('booking_id'))->get()->keyBy('id');
            $tickets = DB::table('tickets')->whereIn('booking_id', $payments->pluck('booking_id'))->pluck('booking_id')->all();
            foreach ($payments as $payment) {
                $booking = $bookings[$payment->booking_id] ?? null;
                $payment->reference = $booking?->reference ?? '—';
                $payment->matched = $payment->status === 'CAPTURED' && $booking && (int) $booking->amount === (int) $payment->amount && in_array($payment->booking_id, $tickets);
            }
            $data['payments'] = $payments;
            $data['financeKpis'] = [
                'Yêu cầu hoàn chờ duyệt' => DB::connection('finance')->table('refunds')->where('status', 'REQUESTED')->count(),
                'Yêu cầu đổi chờ duyệt' => DB::table('exchange_requests')->where('status', 'PENDING')->count(),
                'Kỳ chưa khóa' => DB::connection('finance')->table('reconciliation_runs')->where('status', 'OPEN')->count(),
            ];
        }

        return view('staff-dashboard', $data);
    }

    public function role(Request $r, int $id)
    {
        $d = $r->validate(['role' => ['required', Rule::in(Access::ROLES)], 'airport_code' => 'nullable|size:3']);
        abort_if($id === $r->user()->id, 409, 'Không tự thay đổi quyền của mình.');
        if (in_array($d['role'], Access::SCOPED)) {
            abort_unless(DB::connection('ops')->table('airports')->where('code', $d['airport_code'] ?? '')->exists(), 422, 'Cần sân bay hợp lệ.');
        }
        DB::transaction(function () use ($id, $d, $r) {
            $u = User::lockForUpdate()->findOrFail($id);
            $before = $u->role;
            $u->forceFill(['role' => $d['role'], 'airport_code' => in_array($d['role'], Access::SCOPED) ? $d['airport_code'] : null])->save();
            Audit::record('user.role_changed', (string) $id, $before, $d['role'], $r->user()->id);
        });

        return back()->with('message', 'Đã cập nhật quyền.');
    }

    public function health()
    {
        try {
            $nodes = [];
            foreach (['ops', 'mysql', 'finance'] as $node) {
                $nodes[$node] = DB::connection($node)->selectOne('SELECT @@server_uuid AS uuid')->uuid;
            }

            return response()->json(['status' => count(array_unique($nodes)) === 3 ? 'ok' : 'invalid_topology', 'nodes' => $nodes], count(array_unique($nodes)) === 3 ? 200 : 503);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'degraded'], 503);
        }
    }
}
