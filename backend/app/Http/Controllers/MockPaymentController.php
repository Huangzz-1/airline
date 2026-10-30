<?php

namespace App\Http\Controllers;

use App\Modules\Finance\CheckoutService;
use App\Modules\Finance\GatewayPayments;
use App\Modules\Finance\OutboxRelay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MockPaymentController extends Controller
{
    private function intent(Request $r, string $id): object
    {
        abort_unless(app(CheckoutService::class)->mock(), 404);
        $session = DB::table('checkout_sessions as c')->join('bookings as b', 'b.id', '=', 'c.booking_id')->where('c.intent_id', $id)->where('b.user_id', $r->user()->id)->select('c.*', 'b.reference')->first() ?? abort(404);
        $i = DB::connection('finance')->table('checkout_intents')->find($session->intent_id) ?? abort(404);
        abort_unless($i->mode === 'mock' && in_array($i->method, ['VNPAY', 'MOMO']), 404);

        return $i;
    }

    public function show(Request $r, string $id)
    {
        $intent = $this->intent($r, $id);

        return view('mock-payment', compact('intent'));
    }

    public function pay(Request $r, string $id, GatewayPayments $gateway, OutboxRelay $relay)
    {
        $i = $this->intent($r, $id);
        $d = $r->validate(['result' => 'required|in:approve,decline']);
        $success = $d['result'] === 'approve';
        $transaction = 'LOCAL-'.str_replace('-', '', $i->id);
        if ($i->method === 'VNPAY') {
            $p = ['vnp_TmnCode' => config('payments.vnpay.tmn_code'), 'vnp_Amount' => $i->amount * 100, 'vnp_TxnRef' => $i->id, 'vnp_ResponseCode' => $success ? '00' : '24', 'vnp_TransactionStatus' => $success ? '00' : '02', 'vnp_TransactionNo' => $transaction];
            $p['vnp_SecureHash'] = GatewayPayments::vnpaySignature($p, config('payments.vnpay.hash_secret'));
            $gateway->vnpay($p);
        } else {
            $p = ['amount' => $i->amount, 'extraData' => '', 'message' => $success ? 'Success' : 'Declined', 'orderId' => $i->id, 'orderInfo' => 'Thanh toan ve '.$i->reference, 'orderType' => 'momo_wallet', 'partnerCode' => config('payments.momo.partner_code'), 'payType' => 'qr', 'requestId' => $i->id, 'responseTime' => now()->getTimestampMs(), 'resultCode' => $success ? 0 : 1006, 'transId' => $transaction];
            $p['signature'] = GatewayPayments::momoSignature($p, config('payments.momo.access_key'), config('payments.momo.secret_key'));
            $gateway->momo($p);
        }
        $relay->drain();

        return redirect('/journeys/'.$i->booking_id)->with('message', $success ? 'Thanh toán thử nghiệm thành công.' : 'Thanh toán thử nghiệm bị từ chối.');
    }
}
