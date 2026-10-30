<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Boarding\BoardingService;
use App\Modules\Booking\BookingService;
use App\Modules\Booking\CommerceRepository;
use App\Modules\Finance\CheckoutService;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Support\StaffDashboard;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PortalController extends Controller
{
    public function flights(Request $r, FlightCatalog $catalog)
    {
        $filters = $r->validate(['origin' => 'nullable|string|size:3', 'destination' => 'nullable|string|size:3', 'date' => 'nullable|date_format:Y-m-d']);
        $flights = $catalog->search($filters);

        return $r->expectsJson() ? response()->json($flights) : view('flights', ['flights' => $flights, 'airports' => $catalog->airports()]);
    }

    public function journeys(Request $r, CommerceRepository $repo, FlightCatalog $catalog)
    {
        $bookings = $repo->list($r->user()->id);
        if ($r->expectsJson()) {
            return response()->json($bookings);
        }
        foreach ($bookings as $b) {
            $b->flight = $catalog->get($b->flight_id);
        }

        return view('journeys', compact('bookings'));
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
        $flight = $catalog->get($booking->flight_id);
        if ($r->expectsJson()) {
            return response()->json($booking);
        }
        $ticket = DB::table('tickets')->where('booking_id', $id)->first();
        $pass = in_array($booking->status, ['CHECKED_IN', 'BOARDED']) ? $boarding->pass($id, $r->user()->id) : null;
        $inventory = DB::table('inventory')->where('flight_id', $flight->id)->first();
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

        return view('booking', compact('booking', 'flight', 'ticket', 'pass', 'qr', 'inventory', 'occupied', 'baggage', 'alternatives', 'exchange', 'checkout', 'paymentMethods'));
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

        return $r->expectsJson() ? response()->json($result) : back()->with('message', 'Đã xác nhận boarding: '.$result->reference.' · '.$result->seat);
    }

    public function staff(Request $r)
    {
        $user = $r->user();
        if (! in_array($user->role, ['admin', 'finance', 'airport_agent'])) {
            return view('staff-dashboard', app(StaffDashboard::class)->data($user));
        }
        if ($user->role === 'admin') {
            $d = $r->validate(['q' => 'nullable|string|max:100']);
            $users = User::query()->when($d['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')))->orderBy('id')->paginate(15)->withQueryString();

            return view('admin', ['users' => $users, 'airports' => DB::connection('ops')->table('airports')->get(), 'logs' => DB::table('audit_logs')->latest('id')->limit(20)->get()]);
        }
        if ($user->role === 'finance') {
            $payments = DB::connection('finance')->table('payments')->latest()->paginate(15);
            $bookings = DB::table('bookings')->whereIn('id', $payments->pluck('booking_id'))->get()->keyBy('id');
            $tickets = DB::table('tickets')->whereIn('booking_id', $payments->pluck('booking_id'))->pluck('booking_id')->all();
            foreach ($payments as $payment) {
                $b = $bookings[$payment->booking_id] ?? null;
                $payment->reference = $b?->reference ?? '—';
                $payment->matched = $payment->status === 'CAPTURED' && $b && (int) $b->amount === (int) $payment->amount && in_array($payment->booking_id, $tickets);
            }

            return view('finance', ['payments' => $payments] + app(StaffDashboard::class)->data($user));
        }
        $flights = DB::connection('ops')->table('flights')->where('origin', $user->airport_code)->where('departure', '>', now())->orderBy('departure')->get();
        $ids = $flights->pluck('id');
        $manifest = DB::table('bookings as b')->join('users as u', 'u.id', '=', 'b.user_id')->whereIn('flight_id', $ids)->whereIn('b.status', ['CONFIRMED', 'CHECKED_IN', 'BOARDED'])->select('b.reference', 'b.flight_id', 'b.seat', 'b.status', 'u.name as passenger_name')->get();

        return view('staff', compact('flights', 'manifest'));
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
