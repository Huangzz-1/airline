<?php

namespace App\Http\Controllers;

use App\Modules\Finance\CheckoutService;
use App\Modules\Finance\FinanceService;
use App\Modules\Finance\GatewayPayments;
use App\Modules\Finance\OutboxRelay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PaymentController extends Controller
{
    public function destination(Request $r, string $id, FinanceService $service, OutboxRelay $relay)
    {
        $service->destination($r->user(), $id, $r->all());
        $relay->drain();

        return back()->with('message', 'Đã gửi tài khoản nhận hoàn tiền để tài chính kiểm tra.');
    }

    public function settleRefund(Request $r, FinanceService $service, OutboxRelay $relay)
    {
        $d = $r->validate(['refund_id' => 'required|integer', 'reference' => 'required|string|between:6,120']);
        $service->settleRefund($r->user(), (int) $d['refund_id'], $d['reference']);
        $relay->drain();

        return back()->with('message', 'Đã xác nhận chi hoàn tiền theo mã giao dịch.');
    }

    public function report(Request $r, string $id, CheckoutService $service, OutboxRelay $relay)
    {
        $d = $r->validate(['reference' => 'required|string|between:6,120']);
        $service->report($r->user(), $id, $d['reference']);
        $relay->drain();

        return back()->with('message', 'Đã gửi thông tin chuyển khoản. Tài chính sẽ đối chiếu trước khi xuất vé.');
    }

    public function decide(Request $r, GatewayPayments $service, OutboxRelay $relay)
    {
        $d = $r->validate(['intent_id' => 'required|uuid', 'approve' => 'required|boolean', 'reference' => 'nullable|string|between:6,120']);
        $service->decideBank($r->user(), $d['intent_id'], (bool) $d['approve'], $d['reference'] ?? null);
        $relay->drain();

        return back()->with('message', 'Đã ghi nhận kết quả đối chiếu chuyển khoản.');
    }

    public function vnpay(Request $r, GatewayPayments $service, OutboxRelay $relay)
    {
        try {
            $payload = [];
            parse_str($r->server('QUERY_STRING', ''), $payload);
            $service->vnpay($payload);

            return response()->json(['RspCode' => '00', 'Message' => 'Confirm Success']);
        } catch (HttpExceptionInterface $e) {
            return response()->json(['RspCode' => match ($e->getStatusCode()) {
                403 => '97',404 => '01',422 => '04',409 => '02',default => '99'
            }, 'Message' => 'Unable to confirm']);
        }
    }

    public function momo(Request $r, GatewayPayments $service, OutboxRelay $relay)
    {
        $payload = json_decode($r->getContent(), true, 32);
        abort_unless(is_array($payload) && json_last_error() === JSON_ERROR_NONE, 422, 'Thông báo thanh toán không hợp lệ.');
        $service->momo($payload);

        return response()->noContent();
    }

    public function returned(Request $r, string $provider)
    {
        $r->validate(['vnp_TxnRef' => 'nullable|uuid', 'orderId' => 'nullable|uuid']);
        $intent = $provider === 'vnpay' ? $r->query('vnp_TxnRef') : $r->query('orderId');
        $s = DB::table('checkout_sessions as c')->join('bookings as b', 'b.id', '=', 'c.booking_id')->where('c.intent_id', $intent)->where('b.user_id', $r->user()->id)->select('b.id')->first() ?? abort(404);

        return redirect('/journeys/'.$s->id)->with('message', 'Đang kiểm tra kết quả thanh toán. Vé được xuất sau khi có xác nhận từ cổng thanh toán.');
    }
}
