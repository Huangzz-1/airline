<?php

namespace App\Modules\Finance;

use App\Models\User;
use App\Modules\Booking\CommerceRepository;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutService
{
    public function mock(): bool
    {
        return config('payments.mode') === 'mock' && ! app()->environment('production');
    }

    public function methods(): array
    {
        $methods = [];
        foreach (['VNPAY' => 'VNPay', 'MOMO' => 'Ví MoMo', 'BANK' => 'Chuyển khoản ngân hàng'] as $method => $label) {
            $p = config('payments.'.strtolower($method));
            $keys = match ($method) {
                'VNPAY' => ['tmn_code', 'hash_secret'],'MOMO' => ['partner_code', 'access_key', 'secret_key'],default => ['name', 'account_number', 'account_name']
            };
            if (app()->environment('production') && (($method !== 'BANK' && config('payments.mode') === 'mock') || ($method === 'BANK' && ($p['mode'] ?? 'live') === 'mock'))) {
                continue;
            }
            if ($p['enabled'] && ! collect($keys)->contains(fn ($k) => empty($p[$k]))) {
                if (($method === 'BANK' && ($p['mode'] ?? 'live') === 'mock') || ($method !== 'BANK' && ($this->mock() || str_contains($p['url'], 'sandbox.') || str_contains($p['url'], 'test-payment.')))) {
                    $label .= ' (thử nghiệm)';
                }
                $methods[$method] = $label;
            }
        }

        return $methods;
    }

    public function start(User $u, string $id, string $method, string $ip): object
    {
        abort_unless($u->role === 'passenger', 403);
        abort_unless(isset($this->methods()[$method]), 409, 'Phương thức thanh toán chưa sẵn sàng. Vui lòng chọn phương thức khác hoặc liên hệ hỗ trợ.');
        $repo = app(CommerceRepository::class);
        $b = $repo->owned($id, $u->id);
        $f = app(FlightCatalog::class)->get($b->flight_id);
        abort_unless(in_array($f->status, ['SCHEDULED', 'DELAYED', 'BOARDING']) && now()->lt($f->departure), 409, 'Chuyến không còn nhận thanh toán.');

        return DB::transaction(function () use ($u, $b, $method, $ip, $repo) {
            $repo->lockInventory($b->flight_id);
            $b = $repo->locked($b->id);
            $old = DB::table('checkout_sessions')->where('booking_id', $b->id)->first();
            if ($old) {
                abort_unless($old->method === $method, 409, 'Đã có yêu cầu thanh toán với phương thức khác.');

                return $old;
            }
            abort_if(DB::table('exchange_requests')->where('target_booking_id', $b->id)->whereIn('status', ['PENDING', 'PROCESSING'])->exists(), 409, 'Yêu cầu đổi vé cần được phê duyệt trước khi thanh toán.');
            abort_unless($b->status === 'HELD' && now()->lt($b->expires_at), 409, 'Giữ chỗ đã hết hiệu lực.');
            $this->enqueue($b, $method, $ip);
            Audit::record('checkout.requested', $b->id, 'HELD', $method, $u->id);

            return DB::table('checkout_sessions')->where('booking_id', $b->id)->first();
        }, 3);
    }

    // Caller already holds inventory and booking locks, including both flights for exchanges.
    public function enqueue(object $b, string $method, string $ip): void
    {
        abort_unless(isset($this->methods()[$method]), 409, 'Phương thức thanh toán chưa sẵn sàng.');
        $id = (string) Str::uuid();
        $mode = $method === 'BANK' ? (config('payments.bank.mode') ?? 'live') : config('payments.mode');
        DB::table('bookings')->where('id', $b->id)->update(['status' => 'PAYMENT_PENDING', 'payment_method' => $method, 'updated_at' => now()]);
        DB::table('checkout_sessions')->insert(['booking_id' => $b->id, 'intent_id' => $id, 'method' => $method, 'mode' => $mode, 'expires_at' => $b->expires_at, 'created_at' => now(), 'updated_at' => now()]);
        Events::emit('mysql', 'CheckoutRequested', ['intent_id' => $id, 'booking_id' => $b->id, 'amount' => $b->amount, 'reference' => $b->reference, 'method' => $method, 'mode' => $mode, 'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1', 'expires_at' => $b->expires_at]);
    }

    public function report(User $u, string $id, string $reference): void
    {
        abort_unless($u->role === 'passenger', 403);
        $repo = app(CommerceRepository::class);
        $b = $repo->owned($id, $u->id);
        DB::transaction(function () use ($u, $b, $reference, $repo) {
            $repo->lockInventory($b->flight_id);
            $b = $repo->locked($b->id);
            $s = DB::table('checkout_sessions')->where('booking_id', $b->id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($s->method === 'BANK' && $b->status === 'PAYMENT_PENDING', 409, 'Đặt chỗ không chờ chuyển khoản.');
            if ($s->reported_at) {
                abort_unless($s->customer_reference === $reference, 409, 'Đã gửi thông tin chuyển khoản. Vui lòng liên hệ hỗ trợ để điều chỉnh.');

                return;
            }
            DB::table('checkout_sessions')->where('booking_id', $b->id)->update(['customer_reference' => $reference, 'reported_at' => now(), 'updated_at' => now()]);
            Events::emit('mysql', 'BankTransferReported', ['intent_id' => $s->intent_id, 'reference' => $reference]);
            Audit::record('checkout.transfer_reported', $b->id, null, 'REPORTED', $u->id);
        }, 3);
    }

    public function project(string $event, array $p): void
    {
        DB::transaction(function () use ($event, $p) {
            $s = DB::table('checkout_sessions')->where('intent_id', $p['intent_id'])->lockForUpdate()->first() ?? throw new \RuntimeException('Checkout session missing');
            if (! DB::table('inbox')->insertOrIgnore(['id' => $event, 'received_at' => now()])) {
                return;
            }
            if ($s->status === 'CREATED') {
                DB::table('checkout_sessions')->where('intent_id', $s->intent_id)->update(['status' => 'READY', 'pay_url' => $p['pay_url'], 'bank_details' => isset($p['bank_details']) ? json_encode($p['bank_details']) : null, 'updated_at' => now()]);
            }
        }, 3);
    }
}
