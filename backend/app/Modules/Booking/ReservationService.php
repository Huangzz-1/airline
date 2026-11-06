<?php

namespace App\Modules\Booking;

use App\Models\User;
use App\Modules\Finance\CheckoutService;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ReservationService
{
    public function owned(User $u, string $id): object
    {
        Access::role($u, 'passenger');

        return DB::table('reservations')->where('id', $id)->where('user_id', $u->id)->first() ?? abort(404);
    }

    public function hold(User $u, array $input): object
    {
        Access::role($u, 'passenger');
        $d = Validator::make($input, [
            'idempotency_key' => 'required|string|between:8,80', 'trip_type' => 'required|in:ONE_WAY,ROUND_TRIP',
            'outbound_count' => 'nullable|integer|between:1,3', 'expected_total' => 'nullable|integer|min:0',
            'contact_details' => 'nullable|array:name,email,phone', 'contact_details.name' => 'required_with:contact_details|string|between:2,100',
            'contact_details.email' => 'required_with:contact_details|email|max:190', 'contact_details.phone' => 'required_with:contact_details|string|regex:/^\+?[0-9 ]{9,16}$/',
            'passengers' => 'required|array|size:1', 'passengers.*' => 'required|array:name,date_of_birth,details',
            'passengers.*.name' => 'required|string|between:2,100', 'passengers.*.date_of_birth' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'passengers.*.details' => 'nullable|array:surname,given_name,nationality,gender,document_number,document_expiry',
            'passengers.*.details.surname' => 'nullable|string|max:60', 'passengers.*.details.given_name' => 'nullable|string|max:100',
            'passengers.*.details.nationality' => 'nullable|in:'.implode(',', array_keys(TripDetails::COUNTRIES)), 'passengers.*.details.gender' => 'nullable|in:M,F,X',
            'passengers.*.details.document_number' => 'nullable|string|regex:/^[A-Za-z0-9]{5,20}$/', 'passengers.*.details.document_expiry' => 'nullable|date_format:Y-m-d|after_or_equal:today',
            'segments' => 'required|array|min:1|max:6', 'segments.*' => 'required|array:flight_id,fare_class',
            'segments.*.flight_id' => 'required|integer|distinct', 'segments.*.fare_class' => 'required|string|regex:/^[A-Z0-9_]{1,20}$/',
        ])->validate();
        $d['passengers'] = array_values(array_map(fn ($p) => ['name' => trim($p['name']), 'date_of_birth' => $p['date_of_birth'] ?? null] + (empty($p['details']) ? [] : ['details' => $p['details']]), $d['passengers']));
        Validator::make($d, ['passengers.*.name' => 'required|string|between:2,100'])->validate();
        $d['segments'] = array_values(array_map(fn ($s) => ['flight_id' => (int) $s['flight_id'], 'fare_class' => $s['fare_class']], $d['segments']));
        $outboundCount = (int) ($d['outbound_count'] ?? ($d['trip_type'] === 'ONE_WAY' ? count($d['segments']) : 1));
        $hashData = [$d['trip_type'], $d['passengers'], $d['segments']];
        if (isset($d['outbound_count']) || ! empty($d['contact_details'])) {
            $hashData[] = [$outboundCount, $d['contact_details'] ?? null];
        }
        $hash = hash('sha256', json_encode($hashData, JSON_THROW_ON_ERROR));
        $old = DB::table('reservations')->where('user_id', $u->id)->where('idempotency_key', $d['idempotency_key'])->first();
        if ($old) {
            abort_unless(hash_equals($old->request_hash, $hash), 409, 'Mã yêu cầu đã dùng cho hành trình khác.');

            return $old;
        }
        $flights = array_map(fn ($s) => app(FlightCatalog::class)->get($s['flight_id']), $d['segments']);
        TripItinerary::validate($d['trip_type'], $flights, $outboundCount);
        $lastArrival = Carbon::parse($flights[count($flights) - 1]->arrival);
        foreach ($d['passengers'] as $person) {
            if (! empty($person['details']['document_number'])) {
                abort_unless(! empty($person['details']['document_expiry']) && Carbon::parse($person['details']['document_expiry'], 'Asia/Ho_Chi_Minh')->endOfDay()->gte($lastArrival), 422, 'Hộ chiếu cần còn hiệu lực đến hết chuyến đi.');
            }
        }

        return DB::transaction(function () use ($u, $d, $hash, $outboundCount) {
            $ids = array_column($d['segments'], 'flight_id');
            sort($ids);
            foreach ($ids as $flight) {
                app(CommerceRepository::class)->lockInventory($flight);
            }
            // Inventory precedes the account lock, matching booking inserts and their user FK locks.
            DB::table('users')->where('id', $u->id)->lockForUpdate()->first();
            $old = DB::table('reservations')->where('user_id', $u->id)->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Mã yêu cầu đã dùng cho hành trình khác.');

                return $old;
            }
            $id = (string) Str::uuid();
            $reference = strtoupper(Str::random(8));
            $expires = now()->addMinutes(10);
            DB::table('reservations')->insert(['id' => $id, 'reference' => $reference, 'user_id' => $u->id, 'trip_type' => $d['trip_type'], 'outbound_count' => $outboundCount, 'contact_details' => TripDetails::encode($d['contact_details'] ?? null), 'idempotency_key' => $d['idempotency_key'], 'request_hash' => $hash, 'total_amount' => 0, 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
            $passengers = [];
            foreach ($d['passengers'] as $position => $passenger) {
                $passengerId = (string) Str::uuid();
                if (isset($passenger['details'])) {
                    $passenger['details'] = TripDetails::encode($passenger['details']);
                }
                DB::table('reservation_passengers')->insert($passenger + ['id' => $passengerId, 'reservation_id' => $id, 'position' => $position + 1]);
                $passengers[] = $passengerId;
            }
            $total = 0;
            $index = 0;
            foreach ($d['segments'] as $position => $segment) {
                $segmentId = DB::table('reservation_segments')->insertGetId($segment + ['reservation_id' => $id, 'position' => $position + 1]);
                foreach ($passengers as $passengerId) {
                    $b = app(BookingService::class)->hold($u->id, $segment['flight_id'], $id.'-'.$index, $segment['fare_class']);
                    DB::table('bookings')->where('id', $b->id)->update(['reference' => $reference.'-'.str_pad((string) (++$index), 2, '0', STR_PAD_LEFT), 'reservation_id' => $id, 'passenger_id' => $passengerId, 'reservation_segment_id' => $segmentId, 'reservation_initial' => true, 'expires_at' => $expires]);
                    $total += $b->amount;
                }
            }
            abort_unless(! isset($d['expected_total']) || $total === (int) $d['expected_total'], 409, 'Giá vé đã thay đổi. Vui lòng kiểm tra lại tổng tiền.');
            DB::table('reservations')->where('id', $id)->update(['total_amount' => $total]);
            Audit::record('reservation.held', $id, null, 'HELD', $u->id);

            return $this->owned($u, $id);
        }, 3);
    }

    private function lock(User $u, string $id)
    {
        $this->owned($u, $id);
        $ids = DB::table('bookings')->where('reservation_id', $id)->where('reservation_initial', true)->distinct()->orderBy('flight_id')->pluck('flight_id');
        foreach ($ids as $flight) {
            app(CommerceRepository::class)->lockInventory($flight);
        }
        DB::table('reservations')->where('id', $id)->lockForUpdate()->first();

        return DB::table('bookings')->where('reservation_id', $id)->where('reservation_initial', true)->orderBy('reference')->lockForUpdate()->get();
    }

    public function cancel(User $u, string $id): void
    {
        DB::transaction(function () use ($u, $id) {
            $bookings = $this->lock($u, $id);
            if ($bookings->every(fn ($b) => in_array($b->status, ['CANCELLED', 'EXPIRED']))) {
                return;
            }
            abort_unless($bookings->every(fn ($b) => in_array($b->status, ['HELD', 'CANCELLED', 'EXPIRED'])), 409, 'Chỉ hủy cả mã khi toàn bộ vé chưa thanh toán.');
            DB::table('bookings')->whereIn('id', $bookings->pluck('id'))->where('status', 'HELD')->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            Audit::record('reservation.cancelled', $id, 'HELD', 'CANCELLED', $u->id);
        }, 3);
    }

    public function pay(User $u, string $id, string $method, string $ip): object
    {
        $reservation = $this->owned($u, $id);
        abort_unless(isset(app(CheckoutService::class)->methods()[$method]), 409, 'Phương thức thanh toán chưa sẵn sàng.');

        return DB::transaction(function () use ($u, $id, $method, $ip, $reservation) {
            $bookings = $this->lock($u, $id);
            $old = DB::table('checkout_sessions')->where('booking_id', $bookings->first()->id)->first();
            if ($old) {
                abort_unless($old->method === $method, 409, 'Đã chọn phương thức thanh toán khác.');

                return $old;
            }
            abort_unless(now()->lt($reservation->expires_at) && $bookings->every(fn ($b) => $b->status === 'HELD'), 409, 'Một hoặc nhiều chặng đã hết hiệu lực. Vui lòng đặt lại hành trình.');
            foreach ($bookings->unique('flight_id') as $b) {
                $f = app(FlightCatalog::class)->get($b->flight_id);
                $stock = DB::table('inventory')->where('flight_id', $b->flight_id)->first();
                abort_unless($stock->status === 'OPEN' && in_array($f->status, ['SCHEDULED', 'DELAYED', 'BOARDING']) && now()->lt($f->departure), 409, 'Một chặng đã ngừng nhận thanh toán.');
            }
            $intent = (string) Str::uuid();
            $mode = $method === 'BANK' ? config('payments.bank.mode') : config('payments.mode');
            $items = [];
            foreach ($bookings as $b) {
                $items[] = ['booking_id' => $b->id, 'amount' => $b->amount];
                DB::table('checkout_sessions')->insert(['booking_id' => $b->id, 'intent_id' => $intent, 'method' => $method, 'mode' => $mode, 'expires_at' => $reservation->expires_at, 'created_at' => now(), 'updated_at' => now()]);
            }
            abort_unless(array_sum(array_column($items, 'amount')) === (int) $reservation->total_amount, 409, 'Tổng tiền hành trình không khớp.');
            DB::table('bookings')->whereIn('id', $bookings->pluck('id'))->update(['status' => 'PAYMENT_PENDING', 'payment_method' => $method, 'updated_at' => now()]);
            Events::emit('mysql', 'CheckoutRequested', ['intent_id' => $intent, 'booking_id' => $bookings->first()->id, 'reservation_id' => $id, 'items' => $items, 'amount' => $reservation->total_amount, 'reference' => $reservation->reference, 'method' => $method, 'mode' => $mode, 'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1', 'expires_at' => $reservation->expires_at]);
            Audit::record('reservation.payment_requested', $id, 'HELD', 'PAYMENT_PENDING', $u->id);

            return DB::table('checkout_sessions')->where('booking_id', $bookings->first()->id)->first();
        }, 3);
    }
}
