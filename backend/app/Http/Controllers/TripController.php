<?php

namespace App\Http\Controllers;

use App\Modules\Booking\ReservationService;
use App\Modules\Booking\TripDetails;
use App\Modules\Booking\TripSelection;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TripController extends Controller
{
    public function select(Request $r, TripSelection $selections)
    {
        $d = $r->validate(['flights' => 'required|string|regex:/^\d+(,\d+){0,2}$/', 'fare_class' => 'required|string|regex:/^[A-Z0-9_]{1,20}$/', 'trip_type' => 'required|in:ONE_WAY,ROUND_TRIP', 'passenger_count' => 'required|integer|in:1', 'outbound' => 'nullable|string|max:6000', 'return_date' => 'nullable|date_format:Y-m-d']);
        $legs = array_map(fn ($id) => ['flight_id' => (int) $id, 'fare_class' => $d['fare_class']], explode(',', $d['flights']));
        if (! empty($d['outbound'])) {
            $data = $selections->decode($d['outbound']);
            abort_unless($d['trip_type'] === 'ROUND_TRIP' && $data['trip_type'] === 'ROUND_TRIP' && ! $data['inbound'] && $data['passenger_count'] === (int) $d['passenger_count'], 422, 'Lượt đi không khớp lựa chọn hiện tại.');
            $data['inbound'] = $legs;
            $quote = $selections->quote($data);
        } else {
            $data = ['trip_type' => $d['trip_type'], 'passenger_count' => (int) $d['passenger_count'], 'outbound' => $legs, 'inbound' => [], 'issued_at' => now()->timestamp, 'quoted_total' => 0, 'return_date' => $d['return_date'] ?? null];
            $quote = $selections->quote($data, false);
            if ($d['trip_type'] === 'ROUND_TRIP') {
                $last = $quote['directions']['outbound'][count($legs) - 1]->flight;
                $first = $quote['directions']['outbound'][0]->flight;
                abort_unless(! empty($data['return_date']) && $data['return_date'] >= Carbon::parse($first->departure)->tz('Asia/Ho_Chi_Minh')->toDateString(), 422, 'Vui lòng chọn ngày về hợp lệ.');
                $data['quoted_total'] = $quote['total'];

                return $this->preview($r, $data, $quote, '/?'.http_build_query(['origin' => $last->destination, 'destination' => $first->origin, 'date' => $data['return_date'], 'trip_type' => 'ROUND_TRIP', 'passenger_count' => 1, 'outbound' => $selections->encode($data)]), true);
            }
        }
        $data['quoted_total'] = $quote['total'];
        $data['issued_at'] = now()->timestamp;

        return $this->preview($r, $data, $quote, '/trip/passengers?selection='.urlencode($selections->encode($data)));
    }

    private function preview(Request $r, array $data, array $quote, string $continueUrl, bool $chooseReturn = false)
    {
        $view = compact('data', 'quote', 'continueUrl', 'chooseReturn');
        $view['airports'] = DB::connection('ops')->table('airports')->get()->keyBy('code');
        $ids = collect($quote['directions'])->flatten(1)->pluck('flight.aircraft_id');
        $view['aircraft'] = DB::connection('ops')->table('aircraft')->whereIn('id', $ids)->get()->keyBy('id');
        if ($r->expectsJson()) {
            return response()->json(['html' => view('partials.trip-preview', $view + ['inModal' => true])->render()])->header('Cache-Control', 'no-store');
        }

        return response()->view('trip-preview', $view)->header('Cache-Control', 'no-store');
    }

    public function passengers(Request $r, TripSelection $selections)
    {
        $r->validate(['selection' => 'required|string|max:10000']);
        $data = $selections->decode($r->query('selection'));
        $quote = $selections->quote($data);
        $priceChanged = $data['quoted_total'] !== $quote['total'];
        $data['quoted_total'] = $quote['total'];
        $data['issued_at'] = now()->timestamp;
        $selection = $selections->encode($data);
        $draft = $r->session()->get('trip_draft');
        if ($draft && hash_equals($draft['signature'], hash('sha256', json_encode([$data['trip_type'], $data['outbound'], $data['inbound'], $data['passenger_count']])))) {
            if (! $r->session()->hasOldInput('passengers')) {
                $r->session()->flashInput(TripDetails::decode($draft['input']));
            }
            $r->session()->forget('trip_draft');
        }

        return view('trip-passengers', ['quote' => $quote, 'selection' => $selection, 'data' => $data, 'priceChanged' => $priceChanged, 'countries' => TripDetails::COUNTRIES, 'requestKey' => old('idempotency_key', (string) Str::uuid())]);
    }

    public function store(Request $r, TripSelection $selections, ReservationService $service)
    {
        $r->validate(['selection' => 'required|string|max:10000', 'idempotency_key' => 'required|uuid', 'consent' => 'accepted']);
        $data = $selections->decode($r->input('selection'));
        $existing = $r->user() && DB::table('reservations')->where('user_id', $r->user()->id)->where('idempotency_key', $r->input('idempotency_key'))->exists();
        $quote = $existing ? ['total' => $data['quoted_total']] : $selections->quote($data);
        $details = $r->validate([
            'passengers' => 'required|array|size:'.$data['passenger_count'],
            'passengers.*.surname' => 'required|string|between:1,60|regex:/^[\p{L}\p{M} \x{0027}-]+$/u',
            'passengers.*.given_name' => 'required|string|between:1,100|regex:/^[\p{L}\p{M} \x{0027}-]+$/u',
            'passengers.*.nationality' => 'required|in:'.implode(',', array_keys(TripDetails::COUNTRIES)),
            'passengers.*.gender' => 'required|in:M,F,X', 'passengers.*.date_of_birth' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'passengers.*.document_number' => 'nullable|string|regex:/^[A-Za-z0-9]{5,20}$/',
            'passengers.*.document_expiry' => 'nullable|date_format:Y-m-d|after_or_equal:today',
            'contact' => 'required|array:name,email,phone', 'contact.name' => 'required|string|between:2,100',
            'contact.email' => 'required|email|max:190', 'contact.phone' => 'required|string|regex:/^\+?[0-9 ]{9,16}$/',
        ]);
        foreach ($details['passengers'] as $i => $person) {
            if (! empty($person['document_number']) && empty($person['document_expiry'])) {
                return back()->withErrors(['passengers.'.$i.'.document_expiry' => 'Vui lòng nhập ngày hết hạn của giấy tờ.'])->withInput();
            }
        }
        $backUrl = '/trip/passengers?selection='.urlencode($r->input('selection'));
        if (! $r->user() || ! $r->user()->hasVerifiedEmail()) {
            $r->session()->put('trip_draft', ['signature' => hash('sha256', json_encode([$data['trip_type'], $data['outbound'], $data['inbound'], $data['passenger_count']])), 'input' => TripDetails::encode($r->except('_token', 'selection'))]);
            $r->session()->put('url.intended', $backUrl);

            return redirect($r->user() ? '/email/verify' : '/login');
        }
        abort_unless($r->user()->role === 'passenger', 403);
        if ($data['quoted_total'] !== $quote['total']) {
            return redirect($backUrl)->withErrors(['selection' => 'Giá vé đã thay đổi. Vui lòng kiểm tra tổng tiền mới trước khi tiếp tục.'])->withInput();
        }
        $passengers = array_map(fn ($p) => ['name' => trim($p['surname'].' '.$p['given_name']), 'date_of_birth' => $p['date_of_birth'] ?? null, 'details' => array_diff_key($p, ['date_of_birth' => true])], $details['passengers']);
        $reservation = $service->hold($r->user(), ['idempotency_key' => $r->input('idempotency_key'), 'trip_type' => $data['trip_type'], 'outbound_count' => count($data['outbound']), 'segments' => array_merge($data['outbound'], $data['inbound']), 'passengers' => $passengers, 'contact_details' => $details['contact'], 'expected_total' => $data['quoted_total']]);
        $r->session()->forget('trip_draft');

        return redirect('/reservations/'.$reservation->id)->with('message', 'Đã lưu thông tin và giữ chỗ cho chuyến đi. Vui lòng chọn phương thức thanh toán.');
    }
}
