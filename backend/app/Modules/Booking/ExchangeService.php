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

class ExchangeService
{
    public function request(User $u, array $data): object
    {
        Access::role($u, 'passenger');
        $d = Validator::make($data, ['request_id' => 'required|uuid', 'booking_id' => 'required|uuid', 'flight_id' => 'required|integer', 'reason' => 'required|string|between:5,255'])->validate();
        $repo = app(CommerceRepository::class);
        $b = $repo->owned($d['booking_id'], $u->id);
        $source = app(FlightCatalog::class)->get($b->flight_id);
        $target = app(FlightCatalog::class)->get((int) $d['flight_id']);

        return DB::transaction(function () use ($u, $d, $b, $repo, $source, $target) {
            $ids = [$b->flight_id, (int) $d['flight_id']];
            sort($ids);
            foreach (array_unique($ids) as $id) {
                $repo->lockInventory($id);
            }$b = $repo->locked($b->id);
            $old = DB::table('exchange_requests')->find($d['request_id']);
            if ($old) {
                $new = DB::table('bookings')->find($old->target_booking_id);
                abort_unless($old->source_booking_id === $b->id && $new->flight_id === $target->id, 409, 'Khóa đổi vé đã dùng.');

                return $old;
            }
            abort_unless(in_array($b->status, ['CONFIRMED', 'CHECKED_IN']) && now()->lt(Carbon::parse($source->departure)->subMinutes(30)), 409, 'Vé nguồn không còn đủ điều kiện đổi.');
            abort_unless($source->origin === $target->origin && $source->destination === $target->destination && $source->id !== $target->id, 422, 'Đổi sang chuyến khác cùng đường bay.');
            abort_if(DB::table('exchange_requests')->where('source_booking_id', $b->id)->whereIn('status', ['PENDING', 'PROCESSING', 'REFUNDING'])->exists(), 409, 'Đã có yêu cầu đổi đang xử lý.');
            $new = app(BookingService::class)->hold($u->id, $target->id, 'exchange-'.$d['request_id'], $b->fare_class);
            DB::table('exchange_requests')->insert(['id' => $d['request_id'], 'source_booking_id' => $b->id, 'target_booking_id' => $new->id, 'requested_by' => $u->id, 'reason' => $d['reason'], 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('exchange.requested', $b->id, null, $new->id, $u->id);

            return DB::table('exchange_requests')->find($d['request_id']);
        }, 3);
    }

    public function decide(User $u, array $data): object
    {
        Access::role($u, 'finance');
        $d = Validator::make($data, ['exchange_id' => 'required|uuid', 'approve' => 'required|boolean', 'scenario' => 'required|in:approve,decline'])->validate();
        $base = DB::table('exchange_requests')->find($d['exchange_id']) ?? abort(404);
        $source = DB::table('bookings')->find($base->source_booking_id);
        $target = DB::table('bookings')->find($base->target_booking_id);
        $f = app(FlightCatalog::class)->get($target->flight_id);
        $oldFlight = app(FlightCatalog::class)->get($source->flight_id);

        return DB::transaction(function () use ($u, $d, $base, $source, $target, $f, $oldFlight) {
            $repo = app(CommerceRepository::class);
            $ids = [$source->flight_id, $target->flight_id];
            sort($ids);
            foreach (array_unique($ids) as $id) {
                $repo->lockInventory($id);
            }
            $source = $repo->locked($source->id);
            $target = $repo->locked($target->id);
            $req = DB::table('exchange_requests')->where('id', $base->id)->lockForUpdate()->first();
            abort_unless($req->status === 'PENDING' && $req->requested_by !== $u->id, 409, 'Yêu cầu cần người duyệt độc lập và chỉ được duyệt một lần.');
            if (! $d['approve']) {
                if ($target->status === 'HELD') {
                    DB::table('bookings')->where('id', $target->id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
                }$status = 'REJECTED';
            } else {
                abort_unless(in_array($source->status, ['CONFIRMED', 'CHECKED_IN']) && $target->status === 'HELD' && now()->lt($target->expires_at) && now()->lt(Carbon::parse($oldFlight->departure)->subMinutes(30)) && in_array($f->status, ['SCHEDULED', 'DELAYED', 'BOARDING']) && now()->lt(Carbon::parse($f->departure)->subMinutes(30)), 409, 'Vé nguồn hoặc giữ chỗ mới đã thay đổi/hết hạn.');
                DB::table('bookings')->where('id', $source->id)->update(['status' => 'EXCHANGE_PENDING', 'seat' => null, 'updated_at' => now()]);
                DB::table('boarding_passes')->where('booking_id', $source->id)->update(['revoked_at' => now()]);
                if ($source->payment_method) {
                    app(CheckoutService::class)->enqueue($target, $source->payment_method, '127.0.0.1');
                    Events::notify($req->requested_by, 'Thanh toán vé mới', 'Mở hành trình mới để thanh toán: mã đặt chỗ '.$target->reference.'. Vé cũ được giữ đến khi có kết quả.');
                } else {
                    abort_unless(config('payments.simulator_enabled') && ! app()->environment('production'), 409, 'Vé cần được kiểm tra phương thức thanh toán trước khi đổi.');
                    DB::table('bookings')->where('id', $target->id)->update(['status' => 'PAYMENT_PENDING', 'payment_scenario' => $d['scenario'], 'updated_at' => now()]);
                    Events::emit('mysql', 'PaymentRequested', ['booking_id' => $target->id, 'amount' => $target->amount, 'user_id' => $req->requested_by, 'scenario' => $d['scenario'], 'correlation_id' => $req->id]);
                }
                $status = 'PROCESSING';
            }
            DB::table('exchange_requests')->where('id', $req->id)->update(['status' => $status, 'approved_by' => $u->id, 'updated_at' => now()]);
            Events::notify($req->requested_by, 'Xử lý đổi vé', $status === 'REJECTED' ? 'Yêu cầu bị từ chối; vé cũ giữ nguyên.' : 'Yêu cầu đổi vé đã được duyệt. Vé cũ sẽ được hoàn sau khi vé mới được xuất thành công.');
            Audit::record('exchange.'.$status, $req->id, 'PENDING', $status, $u->id);

            return DB::table('exchange_requests')->find($req->id);
        }, 3);
    }

    public static function paymentResult(object $target, string $status): void
    {
        $req = DB::table('exchange_requests')->where('target_booking_id', $target->id)->where('status', 'PROCESSING')->lockForUpdate()->first();
        if (! $req) {
            return;
        }
        // Target inventory is already locked. Source state is frozen as EXCHANGE_PENDING;
        // only this saga may advance it until payment resolves.
        $source = DB::table('bookings')->where('id', $req->source_booking_id)->lockForUpdate()->first();
        if ($source->status !== 'EXCHANGE_PENDING') {
            throw new \RuntimeException('Exchange source state mismatch');
        }
        if ($status === 'CONFIRMED') {
            DB::table('bookings')->where('id', $source->id)->update(['status' => 'REFUND_PENDING', 'updated_at' => now()]);
            Events::emit('mysql', 'RefundRequested', ['booking_id' => $source->id, 'amount' => $source->amount, 'reason' => 'Hoàn vé cũ theo phương án đổi vé đã được tài chính duyệt', 'requested_by' => $req->approved_by, 'automatic' => true]);
            $state = 'REFUNDING';
        } else {
            DB::table('bookings')->where('id', $source->id)->update(['status' => 'CONFIRMED', 'updated_at' => now()]);
            $state = 'FAILED';
            Events::notify($req->requested_by, 'Đổi vé không thành công', 'Thanh toán vé mới bị từ chối; vé cũ vẫn có hiệu lực. Vui lòng check-in lại nếu đã cấp thẻ.');
        }
        DB::table('exchange_requests')->where('id', $req->id)->update(['status' => $state, 'updated_at' => now()]);
        Audit::record('exchange.'.$state, $req->id, 'PROCESSING', $state);
    }
}
