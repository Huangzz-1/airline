<?php

namespace App\Modules\Finance;

use App\Models\User;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GatewayPayments
{
    private function db()
    {
        return DB::connection('finance');
    }

    public static function vnpaySignature(array $data, string $secret): string
    {
        unset($data['vnp_SecureHash'],$data['vnp_SecureHashType']);
        $data = array_filter($data, fn ($v, $k) => str_starts_with($k, 'vnp_') && is_scalar($v) && $v !== '', ARRAY_FILTER_USE_BOTH);
        ksort($data);

        return hash_hmac('sha512', http_build_query($data, '', '&', PHP_QUERY_RFC1738), $secret);
    }

    public function prepare(string $event, array $p): void
    {
        // Persist the identity before contacting a provider: its callback may arrive
        // even when the create response is lost. Never roll this row back on timeout.
        $intent = $this->db()->transaction(function () use ($event, $p) {
            $db = $this->db();
            $db->table('checkout_intents')->insertOrIgnore(['id' => $p['intent_id'], 'booking_id' => $p['booking_id'], 'reference' => $p['reference'], 'method' => $p['method'], 'mode' => $p['mode'] ?? 'gateway', 'amount' => $p['amount'], 'ip_address' => $p['ip_address'], 'expires_at' => $p['expires_at'], 'created_at' => now(), 'updated_at' => now()]);
            $i = $db->table('checkout_intents')->where('id', $p['intent_id'])->lockForUpdate()->first() ?? throw new \RuntimeException('Checkout identity conflict');
            if ($i->booking_id !== $p['booking_id'] || $i->amount !== (int) $p['amount'] || $i->method !== $p['method']) {
                throw new \RuntimeException('Checkout replay mismatch');
            }

            return $db->table('inbox')->where('id', $event)->exists() ? null : $i;
        }, 3);
        if (! $intent) {
            return;
        }
        $url = null;
        $bank = null;
        if ($intent->status === 'CREATED') {
            if ($intent->mode === 'mock' && $intent->method !== 'BANK') {
                if (! app(CheckoutService::class)->mock()) {
                    throw new \RuntimeException('Mock checkout disabled in this environment');
                }
                $url = url('/payments/mock/'.$intent->id);
            } elseif ($intent->method === 'VNPAY') {
                $url = $this->vnpayUrl($intent);
            } elseif ($intent->method === 'MOMO') {
                $url = $this->momoUrl($intent);
            } elseif ($intent->method === 'BANK') {
                $bank = config('payments.bank');
                unset($bank['enabled']);
                $bank['content'] = 'AS '.$intent->reference;
            } else {
                throw new \RuntimeException('Unknown checkout method');
            }
        }
        $this->db()->transaction(function () use ($event, $intent, $url, $bank) {
            $db = $this->db();
            $current = $db->table('checkout_intents')->where('id', $intent->id)->lockForUpdate()->first();
            if (! $db->table('inbox')->insertOrIgnore(['id' => $event, 'received_at' => now()])) {
                return;
            }
            if ($current->status === 'CREATED') {
                $db->table('checkout_intents')->where('id', $current->id)->update(['status' => 'PENDING', 'pay_url' => $url, 'bank_details' => $bank ? json_encode($bank) : null, 'updated_at' => now()]);
                Events::emit('finance', 'CheckoutReady', ['intent_id' => $current->id, 'pay_url' => $url, 'bank_details' => $bank]);
            }
        }, 3);
    }

    private function vnpayUrl(object $i): string
    {
        $c = config('payments.vnpay');
        if (! in_array($c['url'], ['https://sandbox.vnpayment.vn/paymentv2/vpcpay.html', 'https://pay.vnpay.vn/vpcpay.html'], true)) {
            throw new \RuntimeException('VNPay endpoint is not allowed');
        }
        $p = ['vnp_Version' => '2.1.0', 'vnp_Command' => 'pay', 'vnp_TmnCode' => $c['tmn_code'], 'vnp_Amount' => $i->amount * 100, 'vnp_CurrCode' => 'VND', 'vnp_TxnRef' => $i->id, 'vnp_OrderInfo' => 'Thanh toan ve '.$i->reference, 'vnp_OrderType' => 'other', 'vnp_Locale' => 'vn', 'vnp_ReturnUrl' => url('/payments/vnpay/return'), 'vnp_IpAddr' => $i->ip_address, 'vnp_CreateDate' => Carbon::parse($i->created_at)->tz('Asia/Ho_Chi_Minh')->format('YmdHis'), 'vnp_ExpireDate' => Carbon::parse($i->expires_at)->tz('Asia/Ho_Chi_Minh')->format('YmdHis')];
        $p['vnp_SecureHash'] = self::vnpaySignature($p, $c['hash_secret']);

        return $c['url'].'?'.http_build_query($p, '', '&', PHP_QUERY_RFC1738);
    }

    private function momoUrl(object $i): string
    {
        $c = config('payments.momo');
        if (! in_array($c['url'], ['https://test-payment.momo.vn', 'https://payment.momo.vn'], true)) {
            throw new \RuntimeException('MoMo endpoint is not allowed');
        }
        $p = ['partnerCode' => $c['partner_code'], 'requestId' => $i->id, 'amount' => (string) $i->amount, 'orderId' => $i->id, 'orderInfo' => 'Thanh toan ve '.$i->reference, 'redirectUrl' => url('/payments/momo/return'), 'ipnUrl' => url('/payments/momo/ipn'), 'lang' => 'vi', 'requestType' => 'captureWallet', 'extraData' => '', 'autoCapture' => true];
        $raw = 'accessKey='.$c['access_key'].'&amount='.$p['amount'].'&extraData=&ipnUrl='.$p['ipnUrl'].'&orderId='.$p['orderId'].'&orderInfo='.$p['orderInfo'].'&partnerCode='.$p['partnerCode'].'&redirectUrl='.$p['redirectUrl'].'&requestId='.$p['requestId'].'&requestType='.$p['requestType'];
        $p['signature'] = hash_hmac('sha256', $raw, $c['secret_key']);
        $response = Http::withOptions(['verify' => config('payments.ca_bundle') ?: true])->acceptJson()->timeout(12)->connectTimeout(5)->post($c['url'].'/v2/gateway/api/create', $p)->throw()->json();
        if (($response['resultCode'] ?? -1) !== 0 || empty($response['payUrl'])) {
            throw new \RuntimeException('MoMo has not returned a payment URL; retry with the same order identity');
        }
        $host = parse_url($response['payUrl'], PHP_URL_HOST);
        if (parse_url($response['payUrl'], PHP_URL_SCHEME) !== 'https' || ! ($host === 'momo.vn' || str_ends_with($host ?? '', '.momo.vn'))) {
            throw new \RuntimeException('Invalid MoMo payment URL');
        }

        return $response['payUrl'];
    }

    public function vnpay(array $p): void
    {
        $c = config('payments.vnpay');
        abort_unless(! empty($c['hash_secret']) && is_string($p['vnp_SecureHash'] ?? null) && hash_equals(self::vnpaySignature($p, $c['hash_secret']), strtolower($p['vnp_SecureHash'])), 403, 'Chữ ký thanh toán không hợp lệ.');
        abort_unless(($p['vnp_TmnCode'] ?? null) === $c['tmn_code'] && ($p['vnp_CurrCode'] ?? 'VND') === 'VND', 422, 'Thông tin đơn vị thanh toán không hợp lệ.');
        $i = $this->db()->table('checkout_intents')->find($p['vnp_TxnRef'] ?? '') ?? abort(404);
        abort_unless($i->mode !== 'mock' || app(CheckoutService::class)->mock(), 404);
        abort_unless($i->method === 'VNPAY' && preg_match('/^\d+$/', (string) ($p['vnp_Amount'] ?? '')) && (int) $p['vnp_Amount'] === $i->amount * 100, 422, 'Số tiền thanh toán không khớp.');
        if (($p['vnp_ResponseCode'] ?? '') === '00' && ($p['vnp_TransactionStatus'] ?? '') === '00') {
            $this->settle($i->id, 'CAPTURED', (string) ($p['vnp_TransactionNo'] ?? ''));
        } elseif (($p['vnp_ResponseCode'] ?? '00') !== '00' || ($p['vnp_TransactionStatus'] ?? '') === '02') {
            $this->settle($i->id, 'DECLINED', (string) ($p['vnp_TransactionNo'] ?? 'failed-'.$i->id));
        }
    }

    public static function momoSignature(array $p, string $access, string $secret): string
    {
        $keys = ['amount', 'extraData', 'message', 'orderId', 'orderInfo', 'orderType', 'partnerCode', 'payType', 'requestId', 'responseTime', 'resultCode', 'transId'];
        $raw = 'accessKey='.$access;
        foreach ($keys as $key) {
            abort_unless(isset($p[$key]) && is_scalar($p[$key]), 422, 'Thông báo thanh toán thiếu thông tin.');
            $raw .= '&'.$key.'='.$p[$key];
        }

        return hash_hmac('sha256', $raw, $secret);
    }

    public function momo(array $p): void
    {
        $c = config('payments.momo');
        abort_unless(! empty($c['secret_key']) && is_string($p['signature'] ?? null) && hash_equals(self::momoSignature($p, $c['access_key'], $c['secret_key']), strtolower($p['signature'])), 403, 'Chữ ký thanh toán không hợp lệ.');
        $i = $this->db()->table('checkout_intents')->find($p['orderId']) ?? abort(404);
        abort_unless($i->mode !== 'mock' || app(CheckoutService::class)->mock(), 404);
        abort_unless($i->method === 'MOMO' && $p['partnerCode'] === $c['partner_code'] && $p['requestId'] === $i->id && preg_match('/^\d+$/', (string) $p['amount']) && (int) $p['amount'] === $i->amount, 422, 'Thông tin thanh toán không khớp.');
        if ((int) $p['resultCode'] === 0) {
            $this->settle($i->id, 'CAPTURED', (string) $p['transId']);
        } elseif (! in_array((int) $p['resultCode'], [1000, 7000, 9000], true)) {
            $this->settle($i->id, 'DECLINED', (string) $p['transId']);
        }
    }

    private function settle(string $id, string $status, string $transaction, ?int $actor = null): void
    {
        abort_unless(strlen($transaction) >= 1 && strlen($transaction) <= 120, 422, 'Mã giao dịch không hợp lệ.');
        $this->db()->transaction(function () use ($id, $status, $transaction, $actor) {
            $db = $this->db();
            $i = $db->table('checkout_intents')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            if (in_array($i->status, ['CAPTURED', 'DECLINED'])) {
                abort_unless($i->status === $status && $i->provider_transaction === $transaction, 409, 'Giao dịch đã được xác nhận với kết quả khác.');

                return;
            }
            abort_if($db->table('checkout_intents')->where('method', $i->method)->where('provider_transaction', $transaction)->where('id', '!=', $i->id)->exists(), 409, 'Mã giao dịch đã được sử dụng.');
            $db->table('checkout_intents')->where('id', $id)->update(['status' => $status, 'provider_transaction' => $transaction, 'approved_by' => $actor, 'updated_at' => now()]);
            $db->table('payments')->insert(['booking_id' => $i->booking_id, 'amount' => $i->amount, 'currency' => 'VND', 'status' => $status, 'method' => $i->method, 'mode' => $i->mode, 'correlation_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            if ($status === 'CAPTURED') {
                $db->table('ledger_entries')->insert(['booking_id' => $i->booking_id, 'kind' => 'CAPTURE', 'amount' => $i->amount, 'created_at' => now()]);
            }
            Events::emit('finance', 'PaymentResult', ['booking_id' => $i->booking_id, 'amount' => $i->amount, 'status' => $status, 'correlation_id' => $id]);
            Audit::record('checkout.'.$status, $i->booking_id, null, $i->method, $actor, 'finance', $id);
        }, 3);
    }

    public function reported(string $event, array $p): void
    {
        $this->db()->transaction(function () use ($event, $p) {
            $db = $this->db();
            $i = $db->table('checkout_intents')->where('id', $p['intent_id'])->lockForUpdate()->first() ?? throw new \RuntimeException('Checkout not prepared');
            if (! $db->table('inbox')->insertOrIgnore(['id' => $event, 'received_at' => now()])) {
                return;
            }
            if ($i->method !== 'BANK') {
                throw new \RuntimeException('Expected bank transfer');
            }
            if ($i->status === 'PENDING') {
                $db->table('checkout_intents')->where('id', $i->id)->update(['status' => 'REPORTED', 'customer_reference' => $p['reference'], 'reported_at' => now(), 'updated_at' => now()]);
            }
        }, 3);
    }

    public function decideBank(User $u, string $id, bool $approve, ?string $reference): void
    {
        Access::role($u, 'finance');
        $this->db()->transaction(function () use ($u, $id, $approve, $reference) {
            $i = $this->db()->table('checkout_intents')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($i->method === 'BANK' && in_array($i->status, ['PENDING', 'REPORTED', 'CAPTURED', 'DECLINED']), 409, 'Chỉ xác nhận khoản chuyển đang chờ đối chiếu.');
            abort_if(DB::table('bookings')->where('id', $i->booking_id)->value('user_id') === $u->id, 409, 'Cần người xác nhận độc lập.');
            abort_unless(! $approve || ($reference && strlen($reference) >= 6), 422, 'Nhập mã giao dịch đã kiểm tra trên sao kê ngân hàng.');
            $this->settle($id, $approve ? 'CAPTURED' : 'DECLINED', $approve ? $reference : 'rejected-'.$id, $u->id);
        }, 3);
    }
}
