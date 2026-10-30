<?php

namespace App\Modules\Finance;

use App\Modules\Booking\CommerceRepository;
use App\Modules\Booking\ExchangeService;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use App\Modules\Shared\WorkflowRelay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutboxRelay
{
    public function drain(): int
    {
        $failed = 0;
        foreach (['ops', 'mysql', 'finance'] as $source) {
            try {
                $ids = DB::connection($source)->table('outbox')->whereNull('delivered_at')->where('available_at', '<=', now())->limit(100)->pluck('id');
            } catch (\Throwable $e) {
                report($e);
                $failed++;

                continue;
            }
            foreach ($ids as $id) {
                try {
                    DB::connection($source)->transaction(function () use ($source, $id) {
                        $event = DB::connection($source)->table('outbox')->where('id', $id)->lockForUpdate()->first();
                        if (! $event || $event->delivered_at || now()->lt($event->available_at)) {
                            return;
                        }
                        $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);
                        if ($source === 'mysql' && $event->type === 'PaymentRequested') {
                            $this->capture($id, $payload);
                        } elseif ($source === 'finance' && $event->type === 'PaymentResult') {
                            $this->complete($id, $payload);
                        } else {
                            app(WorkflowRelay::class)->deliver($source, $id, $event->type, $payload);
                        }
                        DB::connection($source)->table('outbox')->where('id', $id)->update(['delivered_at' => now(), 'updated_at' => now()]);
                    }, 3);
                } catch (\Throwable $e) {
                    $failed++;
                    report($e);
                    DB::connection($source)->table('outbox')->where('id', $id)->update([
                        'attempts' => DB::raw('attempts+1'), 'available_at' => now()->addSeconds(min(60, 2 ** min(6, (int) (DB::connection($source)->table('outbox')->where('id', $id)->value('attempts') ?? 0) + 1))), 'updated_at' => now(),
                    ]);
                }
            }
        }

        return $failed;
    }

    private function capture(string $event, array $p): void
    {
        abort_unless(config('payments.simulator_enabled') && ! app()->environment('production'), 403, 'Payment simulator disabled');
        $db = DB::connection('finance');
        $db->transaction(function () use ($db, $event, $p) {
            // Booking ID is the sandbox charge idempotency key, independent of delivery ID.
            $db->table('payments')->insertOrIgnore([
                'booking_id' => $p['booking_id'], 'amount' => $p['amount'], 'currency' => 'VND',
                'status' => $p['scenario'] === 'approve' ? 'CAPTURED' : 'DECLINED', 'correlation_id' => $p['correlation_id'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $payment = $db->table('payments')->where('booking_id', $p['booking_id'])->lockForUpdate()->first();
            if (! $db->table('inbox')->insertOrIgnore(['id' => $event, 'received_at' => now()])) {
                return;
            }
            if ((int) $payment->amount !== (int) $p['amount']) {
                throw new \RuntimeException('Payment amount mismatch');
            }
            if ($payment->status === 'CAPTURED') {
                $db->table('ledger_entries')->insertOrIgnore(['booking_id' => $p['booking_id'], 'kind' => 'CAPTURE', 'amount' => $payment->amount, 'created_at' => now()]);
            }
            $db->table('outbox')->insert(['id' => (string) Str::uuid(), 'type' => 'PaymentResult', 'payload' => json_encode([
                'booking_id' => $p['booking_id'], 'amount' => $payment->amount, 'status' => $payment->status, 'correlation_id' => $p['correlation_id'],
            ]), 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('sandbox.'.$payment->status, $p['booking_id'], null, $payment->status, null, 'finance', $p['correlation_id']);
        }, 3);
    }

    private function complete(string $event, array $p): void
    {
        DB::transaction(function () use ($event, $p) {
            $repo = app(CommerceRepository::class);
            $b = DB::table('bookings')->where('id', $p['booking_id'])->first() ?? throw new \RuntimeException('Unknown booking');
            $flightIds = [$b->flight_id];
            $exchange = DB::table('exchange_requests')->where('target_booking_id', $b->id)->where('status', 'PROCESSING')->first();
            if ($exchange) {
                $source = DB::table('bookings')->find($exchange->source_booking_id);
                $flightIds[] = $source->flight_id;
            }sort($flightIds);
            foreach (array_unique($flightIds) as $flightId) {
                $repo->lockInventory($flightId);
            }
            $b = $repo->locked($b->id);
            if (! DB::table('inbox')->insertOrIgnore(['id' => $event, 'received_at' => now()])) {
                return;
            }
            if ($b->status !== 'PAYMENT_PENDING') {
                if (in_array($b->status, ['CONFIRMED', 'CHECKED_IN', 'BOARDED', 'CANCELLED'])) {
                    return;
                }
                throw new \RuntimeException('Invalid payment result state');
            }
            if ((int) $b->amount !== (int) $p['amount']) {
                throw new \RuntimeException('Booking amount mismatch');
            }
            $status = $p['status'] === 'CAPTURED' ? 'CONFIRMED' : 'CANCELLED';
            DB::table('bookings')->where('id', $b->id)->update(['status' => $status, 'updated_at' => now()]);
            if ($status === 'CONFIRMED') {
                DB::table('tickets')->insert(['booking_id' => $b->id, 'number' => 'AS'.strtoupper(Str::random(14)), 'created_at' => now(), 'updated_at' => now()]);
            }
            ExchangeService::paymentResult($b, $status);
            Events::notify($b->user_id, 'Kết quả thanh toán', $status === 'CONFIRMED' ? 'Vé đã được phát hành.' : 'Thanh toán bị từ chối. Vui lòng kiểm tra và thử lại.');
            Audit::record('payment.result', $b->id, 'PAYMENT_PENDING', $status, null, 'mysql', $p['correlation_id']);
        }, 3);
    }
}
