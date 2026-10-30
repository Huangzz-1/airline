<?php

namespace App\Modules\Shared;

use App\Modules\Booking\AircraftInventoryChange;
use App\Modules\Booking\CommerceRepository;
use App\Modules\Finance\CheckoutService;
use App\Modules\Finance\FinanceService;
use App\Modules\Finance\GatewayPayments;
use App\Modules\Operations\FlightChangeService;
use App\Support\Ui;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowRelay
{
    public function deliver(string $source, string $id, string $type, array $p): void
    {
        match ([$source, $type]) {
            ['mysql', 'CheckoutRequested'] => app(GatewayPayments::class)->prepare($id, $p),
            ['finance', 'CheckoutReady'] => app(CheckoutService::class)->project($id, $p),
            ['mysql', 'BankTransferReported'] => app(GatewayPayments::class)->reported($id, $p),
            ['mysql', 'RefundDestinationReported'] => app(FinanceService::class)->receiveDestination($id, $p),
            ['ops', 'AircraftChangePrepare'] => app(AircraftInventoryChange::class)->prepare($id, $p),
            ['ops', 'AircraftChangeCommit'] => app(AircraftInventoryChange::class)->commit($id, $p),
            ['mysql', 'AircraftChangePrepared'] => app(FlightChangeService::class)->prepared($id, $p),
            ['mysql', 'AircraftChangeCommitted'] => app(FlightChangeService::class)->committed($id, $p),
            ['ops', 'FlightChanged'] => $this->flight($id, $p),
            ['mysql', 'RefundRequested'] => $this->refund($id, $p),
            ['finance', 'RefundResult'] => $this->refundResult($id, $p),
            default => throw new \RuntimeException('Unsupported workflow event'),
        };
    }

    private function flight(string $id, array $p): void
    {
        DB::transaction(function () use ($id, $p) {
            if (in_array($p['status'], ['DRAFT', 'PENDING'])) {
                DB::table('inbox')->insertOrIgnore(['id' => $id, 'received_at' => now()]);

                return;
            }
            DB::table('inventory')->insertOrIgnore(['flight_id' => $p['flight_id'], 'capacity' => $p['capacity'], 'price' => $p['price'], 'status' => 'OPEN', 'source_version' => 0]);
            $repo = app(CommerceRepository::class);
            $stock = $repo->lockInventory($p['flight_id'], true);
            if (DB::table('inbox')->where('id', $id)->exists()) {
                return;
            }
            if ((int) $stock->source_version >= (int) $p['version']) {
                DB::table('inbox')->insert(['id' => $id, 'received_at' => now()]);

                return;
            }
            if ($stock->frozen_change_id) {
                $receipt = DB::table('aircraft_change_receipts')->where('change_id', $stock->frozen_change_id)->first();
                if (! $receipt || $receipt->status !== 'COMMITTED' || $p['version'] <= $receipt->fence_version) {
                    throw new \RuntimeException('Awaiting aircraft change commit acknowledgement');
                }
            }
            $bookings = DB::table('bookings')->where('flight_id', $p['flight_id'])->orderBy('id')->lockForUpdate()->get();
            if ($p['status'] === 'CANCELLED') {
                abort_if($bookings->whereIn('status', ['PAYMENT_PENDING', 'EXCHANGE_PENDING'])->count() > 0, 409, 'Chờ thanh toán đang xử lý trước khi bù trừ hủy chuyến.');
            }
            DB::table('inventory')->where('flight_id', $p['flight_id'])->update(['status' => in_array($p['status'], ['SCHEDULED', 'DELAYED', 'BOARDING']) && ! ($stock->manual_closed ?? false) ? 'OPEN' : 'CLOSED', 'source_version' => $p['version'], 'frozen_change_id' => null]);
            foreach ($bookings as $b) {
                Events::notify($b->user_id, 'Cập nhật chuyến '.$p['number'], ($p['notice'] ?? ('Trạng thái '.Ui::label($p['status']))).' · Khởi hành '.Carbon::parse($p['departure'])->tz('Asia/Ho_Chi_Minh')->format('H:i d/m/Y'));
                if ($p['status'] === 'CANCELLED') {
                    if ($b->status === 'HELD') {
                        DB::table('bookings')->where('id', $b->id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
                    }
                    if (in_array($b->status, ['CONFIRMED', 'CHECKED_IN', 'BOARDED'])) {
                        DB::table('bookings')->where('id', $b->id)->update(['status' => 'REFUND_PENDING', 'seat' => null, 'updated_at' => now()]);
                        DB::table('boarding_passes')->where('booking_id', $b->id)->update(['revoked_at' => now()]);
                        foreach (DB::table('baggage')->where('booking_id', $b->id)->whereNotIn('status', ['DELIVERED', 'OFFLOADED'])->get() as $bag) {
                            DB::table('baggage')->where('id', $bag->id)->update(['status' => 'OFFLOADED', 'updated_at' => now()]);
                            DB::table('baggage_events')->insert(['id' => (string) Str::uuid(), 'baggage_id' => $bag->id, 'status' => 'OFFLOADED', 'location' => $p['origin'], 'custody' => $bag->custody, 'actor_id' => 0, 'created_at' => now()]);
                        }
                        Events::emit('mysql', 'RefundRequested', ['booking_id' => $b->id, 'amount' => $b->amount, 'reason' => 'Chuyến đã được phê duyệt hủy', 'requested_by' => 0, 'automatic' => true]);
                        Audit::record('disruption.compensating', $b->id, $b->status, 'REFUND_PENDING');
                    }
                }
            }
            DB::table('inbox')->insert(['id' => $id, 'received_at' => now()]);
        }, 3);
    }

    private function refund(string $id, array $p): void
    {
        $db = DB::connection('finance');
        $db->transaction(function () use ($db, $id, $p) {
            if ($db->table('inbox')->where('id', $id)->exists()) {
                return;
            }
            $payment = $db->table('payments')->where('booking_id', $p['booking_id'])->lockForUpdate()->first() ?? throw new \RuntimeException('Refund payment missing');
            if (! in_array($payment->status, ['CAPTURED', 'REFUNDED'])) {
                throw new \RuntimeException('Refund requires a captured payment');
            }
            if ((int) $payment->amount !== (int) $p['amount']) {
                throw new \RuntimeException('Refund amount mismatch');
            }
            $db->table('refunds')->insertOrIgnore(['booking_id' => $p['booking_id'], 'amount' => $p['amount'], 'reason' => $p['reason'], 'requested_by' => $p['requested_by'], 'created_at' => now(), 'updated_at' => now()]);
            $r = $db->table('refunds')->where('booking_id', $p['booking_id'])->lockForUpdate()->first();
            if ($r->status === 'REJECTED') {
                $db->table('refunds')->where('id', $r->id)->update(['status' => 'REQUESTED', 'reason' => $p['reason'], 'requested_by' => $p['requested_by'], 'approved_by' => null, 'updated_at' => now()]);
            }
            if ($p['automatic'] && $payment->method && $r->status !== 'REFUNDED') {
                $db->table('refunds')->where('id', $r->id)->update(['status' => 'APPROVED', 'approved_by' => $p['requested_by'], 'updated_at' => now()]);
            }
            if ($p['automatic'] && ! $payment->method && $r->status !== 'REFUNDED') {
                abort_unless(config('payments.simulator_enabled') && ! app()->environment('production'), 409, 'Legacy refund requires review');
                $db->table('refunds')->where('id', $r->id)->update(['status' => 'REFUNDED', 'approved_by' => 0, 'updated_at' => now()]);
                $db->table('payments')->where('id', $payment->id)->update(['status' => 'REFUNDED', 'updated_at' => now()]);
                $db->table('ledger_entries')->insertOrIgnore(['booking_id' => $p['booking_id'], 'kind' => 'REFUND', 'amount' => -$p['amount'], 'created_at' => now()]);
                Events::emit('finance', 'RefundResult', ['booking_id' => $p['booking_id'], 'status' => 'REFUNDED']);
            }
            $db->table('inbox')->insert(['id' => $id, 'received_at' => now()]);
            Audit::record('refund.received', $p['booking_id'], null, $p['automatic'] ? 'REFUNDED' : 'REQUESTED', null, 'finance');
        }, 3);
    }

    private function refundResult(string $id, array $p): void
    {
        DB::transaction(function () use ($id, $p) {
            $repo = app(CommerceRepository::class);
            $b = DB::table('bookings')->find($p['booking_id']) ?? throw new \RuntimeException('Unknown refund booking');
            $repo->lockInventory($b->flight_id);
            $b = $repo->locked($b->id);
            if (! DB::table('inbox')->insertOrIgnore(['id' => $id, 'received_at' => now()])) {
                return;
            }
            if ($b->status === 'REFUNDED') {
                return;
            }
            if ($b->status !== 'REFUND_PENDING') {
                throw new \RuntimeException('Refund booking state mismatch');
            }
            $status = $p['status'] === 'REFUNDED' ? 'REFUNDED' : 'CONFIRMED';
            DB::table('bookings')->where('id', $b->id)->update(['status' => $status, 'seat' => null, 'updated_at' => now()]);
            if ($status === 'REFUNDED') {
                DB::table('exchange_requests')->where('source_booking_id', $b->id)->where('status', 'REFUNDING')->update(['status' => 'COMPLETED', 'updated_at' => now()]);
            }
            Events::notify($b->user_id, 'Kết quả hoàn vé', $status === 'REFUNDED' ? 'Đã hoàn toàn bộ giá vé.' : 'Yêu cầu hoàn bị từ chối, vé có hiệu lực trở lại; cần check-in lại.');
            Audit::record('refund.result', $b->id, 'REFUND_PENDING', $status);
        }, 3);
    }
}
