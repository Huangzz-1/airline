<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Booking\BookingService;
use App\Modules\Booking\ExchangeService;
use App\Modules\Finance\CheckoutService;
use App\Modules\Finance\FinanceService;
use App\Modules\Finance\GatewayPayments;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Operations\OperationsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.bank' => ['enabled' => true, 'name' => 'Bank Test', 'account_number' => '000000000000', 'account_name' => 'TEST AIRLINE'], 'payments.vnpay.enabled' => true, 'payments.vnpay.tmn_code' => 'TESTCODE', 'payments.vnpay.hash_secret' => 'vnpay-test-secret', 'payments.momo.enabled' => true, 'payments.momo.partner_code' => 'TESTPARTNER', 'payments.momo.access_key' => 'test-access', 'payments.momo.secret_key' => 'test-secret']);
        Http::preventStrayRequests();
    }

    private function user(string $role = 'passenger'): User
    {
        return User::factory()->create(['role' => $role, 'airport_code' => 'HAN']);
    }

    private function hold(User $u): object
    {
        return app(BookingService::class)->hold($u->id, 1, (string) Str::uuid());
    }

    private function drain(int $count = 2): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->assertSame(0, app(OutboxRelay::class)->drain());
        }
    }

    private function checkout(User $u, object $b, string $method = 'BANK'): object
    {
        $s = app(CheckoutService::class)->start($u, $b->id, $method, '127.0.0.1');
        $this->drain();

        return $s;
    }

    private function vnpayPayload(object $s, object $b, array $override = []): array
    {
        $p = array_replace(['vnp_TmnCode' => 'TESTCODE', 'vnp_Amount' => $b->amount * 100, 'vnp_TxnRef' => $s->intent_id, 'vnp_ResponseCode' => '00', 'vnp_TransactionStatus' => '00', 'vnp_TransactionNo' => 'VN12345678'], $override);
        ksort($p);
        $p['vnp_SecureHash'] = hash_hmac('sha512', http_build_query($p, '', '&', PHP_QUERY_RFC1738), 'vnpay-test-secret');

        return $p;
    }

    private function momoPayload(object $s, object $b, array $override = []): array
    {
        $p = array_replace(['amount' => $b->amount, 'extraData' => '', 'message' => 'Successful.', 'orderId' => $s->intent_id, 'orderInfo' => 'Thanh toan ve '.$b->reference, 'orderType' => 'momo_wallet', 'partnerCode' => 'TESTPARTNER', 'payType' => 'qr', 'requestId' => $s->intent_id, 'responseTime' => now()->timestamp * 1000, 'resultCode' => 0, 'transId' => 123456789], $override);
        $raw = 'accessKey=test-access';
        foreach (['amount', 'extraData', 'message', 'orderId', 'orderInfo', 'orderType', 'partnerCode', 'payType', 'requestId', 'responseTime', 'resultCode', 'transId'] as $key) {
            $raw .= '&'.$key.'='.$p[$key];
        }
        $p['signature'] = hash_hmac('sha256', $raw, 'test-secret');

        return $p;
    }

    public function test_live_methods_do_not_allow_simulator_or_unconfigured_checkout(): void
    {
        $u = $this->user();
        $b = $this->hold($u);
        config(['payments.simulator_enabled' => false, 'payments.vnpay.enabled' => false]);
        $this->actingAs($u)->postJson('/api/v1/bookings/'.$b->id.'/pay', ['scenario' => 'approve'])->assertForbidden();
        $this->post('/journeys/'.$b->id.'/pay', ['method' => 'VNPAY'])->assertConflict();
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'HELD']);
        $this->assertSame(0, DB::connection('finance')->table('payments')->count());
    }

    public function test_bank_report_needs_finance_confirmation_and_replay_cannot_issue_twice(): void
    {
        $u = $this->user();
        $b = $this->hold($u);
        $s = $this->checkout($u, $b);
        $this->actingAs($u)->post('/journeys/'.$b->id.'/transfer-report', ['reference' => 'CUSTOMER123'])->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'PAYMENT_PENDING']);
        $this->assertSame(0, DB::table('tickets')->count());
        $this->post('/staff/payments/confirm-transfer', ['intent_id' => $s->intent_id, 'approve' => true, 'reference' => 'BANKTX123'])->assertForbidden();
        $finance = $this->user('finance');
        $this->actingAs($finance)->post('/staff/payments/confirm-transfer', ['intent_id' => $s->intent_id, 'approve' => true, 'reference' => 'BANKTX123'])->assertRedirect();
        $this->post('/staff/payments/confirm-transfer', ['intent_id' => $s->intent_id, 'approve' => true, 'reference' => 'BANKTX123'])->assertRedirect();
        $this->drain();
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'CONFIRMED']);
        $this->assertSame(1, DB::table('tickets')->count());
        $this->assertSame(1, DB::connection('finance')->table('ledger_entries')->count());
        $second = $this->hold($u);
        $s2 = $this->checkout($u, $second);
        $this->post('/staff/payments/confirm-transfer', ['intent_id' => $s2->intent_id, 'approve' => true, 'reference' => 'BANKTX123'])->assertConflict();
        $this->assertDatabaseHas('bookings', ['id' => $second->id, 'status' => 'PAYMENT_PENDING']);
    }

    public function test_vnpay_signed_checkout_return_does_not_capture_and_ipn_validates_amount_and_replay(): void
    {
        $u = $this->user();
        $b = $this->hold($u);
        $s = $this->checkout($u, $b, 'VNPAY');
        $url = DB::table('checkout_sessions')->where('booking_id', $b->id)->value('pay_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame((string) ($b->amount * 100), $query['vnp_Amount']);
        $hash = $query['vnp_SecureHash'];
        unset($query['vnp_SecureHash']);
        ksort($query);
        $this->assertSame(hash_hmac('sha512', http_build_query($query, '', '&', PHP_QUERY_RFC1738), 'vnpay-test-secret'), $hash);
        $p = $this->vnpayPayload($s, $b);
        $this->actingAs($u)->get('/payments/vnpay/return?'.http_build_query($p))->assertRedirect('/journeys/'.$b->id);
        $this->assertSame(0, DB::connection('finance')->table('payments')->count());
        $bad = $p;
        $bad['vnp_Amount']++;
        $this->getJson('/payments/vnpay/ipn?'.http_build_query($bad))->assertJsonPath('RspCode', '97');
        $this->getJson('/payments/vnpay/ipn?'.http_build_query($this->vnpayPayload($s, $b, ['vnp_Amount' => 100])))->assertJsonPath('RspCode', '04');
        $this->getJson('/payments/vnpay/ipn?'.http_build_query($p))->assertJsonPath('RspCode', '00');
        $this->getJson('/payments/vnpay/ipn?'.http_build_query($p))->assertJsonPath('RspCode', '00');
        $this->drain();
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'CONFIRMED']);
        $this->assertSame(1, DB::table('tickets')->count());
        $this->assertSame(1, DB::connection('finance')->table('payments')->count());
    }

    public function test_momo_request_is_signed_and_ipn_rejects_forgery_wrong_amount_and_duplicate_effects(): void
    {
        Http::fake(['https://test-payment.momo.vn/v2/gateway/api/create' => Http::response(['resultCode' => 0, 'payUrl' => 'https://test-payment.momo.vn/pay/test'])]);
        $u = $this->user();
        $b = $this->hold($u);
        $s = $this->checkout($u, $b, 'MOMO');
        Http::assertSent(function ($r) use ($s, $b) {
            $p = $r->data();
            $raw = 'accessKey=test-access&amount='.$b->amount.'&extraData=&ipnUrl='.$p['ipnUrl'].'&orderId='.$s->intent_id.'&orderInfo='.$p['orderInfo'].'&partnerCode=TESTPARTNER&redirectUrl='.$p['redirectUrl'].'&requestId='.$s->intent_id.'&requestType=captureWallet';

            return $p['signature'] === hash_hmac('sha256', $raw, 'test-secret') && $p['autoCapture'] === true;
        });
        $p = $this->momoPayload($s, $b);
        $bad = $p;
        $bad['transId']++;
        $this->postJson('/payments/momo/ipn', $bad)->assertForbidden();
        $this->postJson('/payments/momo/ipn', $this->momoPayload($s, $b, ['amount' => 100]))->assertUnprocessable();
        $this->postJson('/payments/momo/ipn', $p)->assertNoContent();
        $this->postJson('/payments/momo/ipn', $p)->assertNoContent();
        $this->drain();
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'CONFIRMED']);
        $this->assertSame(1, DB::table('tickets')->count());
        $this->assertSame(1, DB::connection('finance')->table('ledger_entries')->count());
    }

    public function test_provider_timeout_keeps_pending_and_retries_the_same_order_without_issuing_a_ticket(): void
    {
        $this->freezeTime();
        $orders = [];
        Http::fake(function ($r) use (&$orders) {
            $orders[] = $r['orderId'];
            if (count($orders) === 1) {
                throw new ConnectionException('Test timeout');
            }

            return Http::response(['resultCode' => 0, 'payUrl' => 'https://test-payment.momo.vn/pay/retry']);
        });
        $u = $this->user();
        $b = $this->hold($u);
        $s = app(CheckoutService::class)->start($u, $b->id, 'MOMO', '127.0.0.1');
        $this->assertGreaterThan(0, app(OutboxRelay::class)->drain());
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'PAYMENT_PENDING']);
        $this->assertSame(0, DB::table('tickets')->count());
        $this->travel(3)->seconds();
        $this->drain();
        $this->assertSame([$s->intent_id, $s->intent_id], $orders);
        $this->assertSame(1, DB::connection('finance')->table('checkout_intents')->count());
        $this->assertSame(0, DB::connection('finance')->table('payments')->count());
        $this->assertDatabaseHas('checkout_sessions', ['booking_id' => $b->id, 'status' => 'READY']);
        $this->travelBack();
    }

    public function test_callback_can_settle_durable_intent_when_create_response_is_lost(): void
    {
        $this->freezeTime();
        $u = $this->user();
        $b = $this->hold($u);
        $calls = 0;
        Http::fake(function ($r) use ($b, &$calls) {
            $calls++;
            $s = (object) ['intent_id' => $r['orderId']];
            app(GatewayPayments::class)->momo($this->momoPayload($s, $b));
            throw new ConnectionException('Response lost after provider callback');
        });
        $s = app(CheckoutService::class)->start($u, $b->id, 'MOMO', '127.0.0.1');
        $this->assertGreaterThan(0, app(OutboxRelay::class)->drain());
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'CONFIRMED']);
        $this->travel(3)->seconds();
        $this->drain();
        $this->assertSame(1, $calls);
        $this->assertSame(1, DB::connection('finance')->table('payments')->count());
        $this->assertSame(1, DB::table('tickets')->count());
        $this->travelBack();
    }

    public function test_real_exchange_waits_for_customer_payment_and_source_refund_waits_for_payout(): void
    {
        $u = $this->user();
        $b = $this->hold($u);
        $s = $this->checkout($u, $b);
        $a = $this->user('finance');
        app(GatewayPayments::class)->decideBank($a, $s->intent_id, true, 'SOURCEBANK123');
        $this->drain();
        $request = app(ExchangeService::class)->request($u, ['request_id' => (string) Str::uuid(), 'booking_id' => $b->id, 'flight_id' => 3, 'reason' => 'Đổi sang chuyến sau cùng đường bay']);
        app(ExchangeService::class)->decide($a, ['exchange_id' => $request->id, 'approve' => true, 'scenario' => 'approve']);
        $this->drain();
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'EXCHANGE_PENDING']);
        $this->assertDatabaseHas('bookings', ['id' => $request->target_booking_id, 'status' => 'PAYMENT_PENDING', 'payment_method' => 'BANK']);
        $this->assertSame(1, DB::table('tickets')->count());
        $target = DB::table('checkout_sessions')->where('booking_id', $request->target_booking_id)->first();
        app(GatewayPayments::class)->decideBank($a, $target->intent_id, true, 'TARGETBANK123');
        $this->drain(3);
        $this->assertDatabaseHas('bookings', ['id' => $request->target_booking_id, 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'REFUND_PENDING']);
        $this->assertSame('CAPTURED', DB::connection('finance')->table('payments')->where('booking_id', $b->id)->value('status'));
        $this->assertSame(0, DB::connection('finance')->table('ledger_entries')->where('kind', 'REFUND')->count());
    }

    public function test_approved_cancellation_of_real_payment_never_claims_an_automatic_cash_refund(): void
    {
        $u = $this->user();
        $b = $this->hold($u);
        $s = $this->checkout($u, $b);
        app(GatewayPayments::class)->decideBank($this->user('finance'), $s->intent_id, true, 'CANCELBANK123');
        $this->drain();
        $ops = $this->user('operations');
        $manager = $this->user('ops_manager');
        $svc = app(OperationsService::class);
        $p = $svc->execute($ops, 'disruption', ['flight_id' => 1, 'type' => 'CANCEL', 'delay_minutes' => 0, 'reason' => 'Xử lý hủy theo quyết định khai thác']);
        $svc->execute($manager, 'approve_disruption', ['disruption_id' => $p->id]);
        $this->drain(3);
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'REFUND_PENDING']);
        $this->assertSame('APPROVED', DB::connection('finance')->table('refunds')->where('booking_id', $b->id)->value('status'));
        $this->assertSame('CAPTURED', DB::connection('finance')->table('payments')->where('booking_id', $b->id)->value('status'));
        $this->assertSame(0, DB::connection('finance')->table('ledger_entries')->where('kind', 'REFUND')->count());
    }

    public function test_real_refund_requires_encrypted_destination_approval_and_independent_payout(): void
    {
        $u = $this->user();
        $b = $this->hold($u);
        $s = $this->checkout($u, $b);
        $a = $this->user('finance');
        $z = $this->user('finance');
        app(GatewayPayments::class)->decideBank($a, $s->intent_id, true, 'CAPTURE999');
        $this->drain();
        app(FinanceService::class)->requestRefund($u, $b->id, 'Yêu cầu hoàn theo chính sách');
        $this->drain();
        $r = DB::connection('finance')->table('refunds')->where('booking_id', $b->id)->first();
        $this->actingAs($a)->postJson('/api/v1/actions/refund_decide', ['refund_id' => $r->id, 'approve' => true])->assertConflict();
        $destination = ['bank_name' => 'Bank Test', 'account_number' => '123456789123', 'account_name' => 'NGUYEN VAN TEST'];
        $this->actingAs($u)->post('/journeys/'.$b->id.'/refund-destination', $destination)->assertRedirect();
        $this->drain();
        $cipher = DB::connection('finance')->table('refunds')->where('id', $r->id)->value('payout_details');
        $this->assertStringNotContainsString($destination['account_number'], $cipher);
        $this->actingAs($a)->postJson('/api/v1/actions/refund_decide', ['refund_id' => $r->id, 'approve' => true])->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'REFUND_PENDING']);
        $this->post('/staff/payments/confirm-refund', ['refund_id' => $r->id, 'reference' => 'PAYOUT999'])->assertConflict();
        $this->actingAs($z)->post('/staff/payments/confirm-refund', ['refund_id' => $r->id, 'reference' => 'PAYOUT999'])->assertRedirect();
        $this->post('/staff/payments/confirm-refund', ['refund_id' => $r->id, 'reference' => 'PAYOUT999'])->assertRedirect();
        $this->drain();
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'REFUNDED']);
        $this->assertSame(1, DB::connection('finance')->table('ledger_entries')->where('kind', 'REFUND')->count());
    }
}
