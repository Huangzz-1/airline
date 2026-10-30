<?php

namespace App\Modules\Booking;

use App\Modules\Flights\FlightCatalog;
use App\Modules\Shared\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(private CommerceRepository $repo, private FlightCatalog $catalog) {}

    public function hold(int $user, int $flight, string $key, string $fareClass = 'ECONOMY'): object
    {
        $f = $this->catalog->get($flight);
        abort_unless(in_array($f->status, ['SCHEDULED', 'BOARDING', 'DELAYED']) && now()->lt($f->departure), 409, 'Chuyến không còn mở bán.');

        return DB::transaction(function () use ($user, $flight, $key, $fareClass) {
            $stock = $this->repo->lockInventory($flight);
            abort_unless($stock->status === 'OPEN', 409, 'Tồn chỗ đang đóng.');
            $old = DB::table('bookings')->where('user_id', $user)->where('idempotency_key', $key)->first();
            if ($old) {
                abort_unless($old->flight_id === $flight && $old->fare_class === $fareClass, 409, 'Khóa yêu cầu đã dùng cho chuyến khác.');

                return $old;
            }
            $this->expireFlight($flight);
            $used = DB::table('bookings')->where('flight_id', $flight)->whereIn('status', ['HELD', 'PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'EXCHANGE_PENDING'])->count();
            abort_if($used >= $stock->capacity, 409, 'Chuyến đã hết chỗ.');
            $fare = DB::table('fare_classes')->where('flight_id', $flight)->where('code', $fareClass)->first();
            abort_unless($fareClass === 'ECONOMY' || $fare, 422, 'Hạng giá không tồn tại.');
            if ($fare) {
                abort_unless($fare->active, 409, 'Hạng giá đã đóng.');
                abort_if(DB::table('bookings')->where('flight_id', $flight)->where('fare_class', $fareClass)->whereIn('status', ['HELD', 'PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'EXCHANGE_PENDING'])->count() >= $fare->quota, 409, 'Hạng giá đã hết chỗ.');
            }
            $id = (string) Str::uuid();
            DB::table('bookings')->insert([
                'id' => $id, 'reference' => strtoupper(Str::random(8)), 'user_id' => $user, 'flight_id' => $flight, 'idempotency_key' => $key,
                'fare_class' => $fareClass, 'status' => 'HELD', 'amount' => $fare?->price ?? $stock->price, 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('booking.held', $id, null, 'HELD', $user);

            return $this->repo->owned($id, $user);
        }, 3);
    }

    // Caller always owns the inventory lock; expiry and new holds serialize on that row.
    private function expireFlight(int $flight): void
    {
        $expired = DB::table('bookings')->where('flight_id', $flight)->where('status', 'HELD')->where('expires_at', '<=', now())->get();
        foreach ($expired as $b) {
            DB::table('bookings')->where('id', $b->id)->update(['status' => 'EXPIRED', 'updated_at' => now()]);
            DB::table('exchange_requests')->where('target_booking_id', $b->id)->where('status', 'PENDING')->update(['status' => 'EXPIRED', 'updated_at' => now()]);
            Audit::record('booking.expired', $b->id, 'HELD', 'EXPIRED');
        }
    }

    public function expire(): void
    {
        foreach (DB::table('inventory')->pluck('flight_id') as $flight) {
            DB::transaction(function () use ($flight) {
                $this->repo->lockInventory($flight, true);
                $this->expireFlight($flight);
            }, 3);
        }
    }

    public function cancel(string $id, int $user): object
    {
        $b = $this->repo->owned($id, $user);

        return DB::transaction(function () use ($b, $user) {
            $this->repo->lockInventory($b->flight_id);
            $b = $this->repo->locked($b->id);
            if ($b->status === 'CANCELLED') {
                return $b;
            }
            abort_unless($b->status === 'HELD', 409, 'Chỉ có thể hủy giữ chỗ chưa thanh toán trong phiên bản này.');
            DB::table('bookings')->where('id', $b->id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            DB::table('exchange_requests')->where('target_booking_id', $b->id)->where('status', 'PENDING')->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            Audit::record('booking.cancelled', $b->id, 'HELD', 'CANCELLED', $user);

            return $this->repo->owned($b->id, $user);
        }, 3);
    }

    public function pay(string $id, int $user, string $scenario): object
    {
        abort_unless(config('payments.simulator_enabled') && ! app()->environment('production'), 403, 'Thanh toán thử nghiệm đã bị tắt. Vui lòng chọn phương thức thanh toán.');
        $b = $this->repo->owned($id, $user);
        abort_if(DB::table('exchange_requests')->where('target_booking_id', $id)->whereIn('status', ['PENDING', 'PROCESSING'])->exists(), 409, 'Vé này thuộc yêu cầu đổi; cần phê duyệt theo quy trình đổi vé.');
        $f = $this->catalog->get($b->flight_id);
        abort_unless(in_array($f->status, ['SCHEDULED', 'BOARDING', 'DELAYED']) && now()->lt($f->departure), 409, 'Chuyến không thể thanh toán.');

        return DB::transaction(function () use ($b, $user, $scenario) {
            $this->repo->lockInventory($b->flight_id);
            $b = $this->repo->locked($b->id);
            if (in_array($b->status, ['PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED'])) {
                abort_unless($b->payment_scenario === $scenario, 409, 'Không được thay đổi kết quả sandbox khi gửi lại.');

                return $b;
            }
            abort_unless($b->status === 'HELD' && now()->lt($b->expires_at), 409, 'Giữ chỗ không còn hiệu lực.');
            DB::table('bookings')->where('id', $b->id)->update(['status' => 'PAYMENT_PENDING', 'payment_scenario' => $scenario, 'updated_at' => now()]);
            $event = (string) Str::uuid();
            DB::table('outbox')->insert(['id' => $event, 'type' => 'PaymentRequested', 'payload' => json_encode([
                'booking_id' => $b->id, 'amount' => $b->amount, 'user_id' => $user, 'scenario' => $scenario, 'correlation_id' => $event,
            ]), 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('payment.requested', $b->id, 'HELD', 'PAYMENT_PENDING', $user, 'mysql', $event);

            return $this->repo->owned($b->id, $user);
        }, 3);
    }
}
