<?php

namespace App\Modules\Finance;

use App\Models\User;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class FinanceOperations
{
    private function db()
    {
        return DB::connection('finance');
    }

    public function import(User $u, string $source, string $path, string $filename): object
    {
        Access::role($u, 'finance');
        Validator::make(['source' => $source], ['source' => 'required|string|regex:/^[A-Za-z0-9_.-]{2,60}$/'])->validate();
        abort_if(filesize($path) > 1048576, 422, 'Tệp tối đa 1 MB.');
        $hash = hash_file('sha256', $path);
        $file = fopen($path, 'r');
        $rows = [];
        try {
            $header = fgetcsv($file, 0, ',', '"', '');
            abort_unless($header, 422, 'Tệp CSV trống.');
            $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]);
            $expected = ['transaction_ref', 'booking_reference', 'amount', 'currency', 'booked_at'];
            abort_unless(count($header) === 5 && count(array_unique($header)) === 5 && ! array_diff($header, $expected), 422, 'CSV cần các cột: '.implode(',', $expected));
            while (($cells = fgetcsv($file, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }
                abort_unless(count($cells) === 5, 422, 'Sai số cột tại dòng '.(count($rows) + 2));
                $d = array_combine($header, array_map('trim', $cells));
                Validator::make($d, ['transaction_ref' => 'required|string|regex:/^[A-Za-z0-9_.:\/-]{6,120}$/', 'booking_reference' => 'required|string|regex:/^[A-Za-z0-9-]{4,80}$/', 'amount' => 'required|integer|between:1,1000000000000', 'currency' => 'required|in:VND', 'booked_at' => 'required|date_format:Y-m-d H:i:s'])->validate();
                $d['amount'] = (int) $d['amount'];
                $d['booking_reference'] = strtoupper($d['booking_reference']);
                $d['booked_at'] = Carbon::parse($d['booked_at'], 'Asia/Ho_Chi_Minh')->utc()->format('Y-m-d H:i:s');
                abort_if(Carbon::parse($d['booked_at'])->gt(now()->addMinutes(5)), 422, 'Ngày giao dịch không được ở tương lai.');
                $d['payload_hash'] = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));
                abort_if(isset($rows[$d['transaction_ref']]), 422, 'Mã giao dịch lặp trong cùng tệp.');
                $rows[$d['transaction_ref']] = $d;
                abort_if(count($rows) > 2000, 422, 'Tệp tối đa 2.000 dòng.');
            }
        } finally {
            fclose($file);
        }
        abort_if(! $rows, 422, 'Tệp không có giao dịch.');

        return $this->db()->transaction(function () use ($u, $source, $filename, $hash, $rows) {
            $db = $this->db();
            $old = $db->table('statement_batches')->where('source', $source)->where('file_hash', $hash)->first();
            if ($old) {
                return $old;
            }
            $id = $db->table('statement_batches')->insertGetId(['source' => $source, 'filename' => basename($filename), 'file_hash' => $hash, 'row_count' => count($rows), 'created_by' => $u->id, 'created_at' => now()]);
            foreach ($rows as $row) {
                $old = $db->table('statement_rows')->where('source', $source)->where('transaction_ref', $row['transaction_ref'])->lockForUpdate()->first();
                if ($old) {
                    abort_unless(hash_equals($old->payload_hash, $row['payload_hash']), 409, 'Mã giao dịch đã nhập với dữ liệu khác: '.$row['transaction_ref']);

                    continue;
                }
                $match = $this->match((object) $row);
                $db->table('statement_rows')->insert($row + ['batch_id' => $id, 'source' => $source, 'result' => $match['result'], 'intent_id' => $match['intent_id']]);
            }
            Audit::record('statement.imported', (string) $id, null, (string) count($rows), $u->id, 'finance');

            return $db->table('statement_batches')->find($id);
        }, 3);
    }

    public function match(object $row): array
    {
        $intents = $this->db()->table('checkout_intents')->where('method', 'BANK')->where('reference', $row->booking_reference)->get();
        if ($intents->count() !== 1) {
            return ['result' => $intents->isEmpty() ? 'UNMATCHED' : 'AMBIGUOUS', 'intent_id' => null];
        }
        $i = $intents->first();
        $result = match (true) {
            $i->amount > $row->amount => 'UNDERPAID', $i->amount < $row->amount => 'OVERPAID',
            $i->status === 'DECLINED' => 'DECLINED',
            $i->status === 'CAPTURED' => $i->provider_transaction === $row->transaction_ref ? 'MATCHED' : 'DUPLICATE_PAYMENT',
            $this->db()->table('checkout_intents')->where('method', 'BANK')->where('provider_transaction', $row->transaction_ref)->where('id', '!=', $i->id)->exists() => 'DUPLICATE_TRANSACTION',
            default => 'READY',
        };

        return ['result' => $result, 'intent_id' => $i->id];
    }

    public function rematch(User $u, int $batch): int
    {
        Access::role($u, 'finance');
        abort_unless($this->db()->table('statement_batches')->where('id', $batch)->exists(), 404);
        $count = 0;
        foreach ($this->db()->table('statement_rows')->where('batch_id', $batch)->whereNull('confirmed_at')->pluck('id') as $id) {
            $this->db()->transaction(function () use ($id, &$count) {
                $row = $this->db()->table('statement_rows')->where('id', $id)->lockForUpdate()->first();
                if ($row->confirmed_at) {
                    return;
                }
                $this->db()->table('statement_rows')->where('id', $id)->update($this->match($row));
                $count++;
            });
        }
        Audit::record('statement.rematched', (string) $batch, null, (string) $count, $u->id, 'finance');

        return $count;
    }

    public function confirm(User $u, int $id): object
    {
        Access::role($u, 'finance');
        $base = $this->db()->table('statement_rows')->find($id) ?? abort(404);

        return $this->db()->transaction(function () use ($u, $base, $id) {
            abort_unless($base->intent_id, 409, 'Giao dịch chưa ghép được với yêu cầu chuyển khoản.');
            $this->db()->table('checkout_intents')->where('id', $base->intent_id)->lockForUpdate()->first();
            $row = $this->db()->table('statement_rows')->where('id', $id)->lockForUpdate()->first();
            if ($row->confirmed_at) {
                return $row;
            }
            $match = $this->match($row);
            abort_unless(in_array($match['result'], ['READY', 'MATCHED']) && $match['intent_id'] === $row->intent_id, 409, 'Mã, số tiền hoặc trạng thái giao dịch không khớp. Đối chiếu lại trước khi xác nhận.');
            app(GatewayPayments::class)->decideBank($u, $row->intent_id, true, $row->transaction_ref);
            $this->db()->table('statement_rows')->where('id', $id)->update(['result' => 'SETTLED', 'confirmed_by' => $u->id, 'confirmed_at' => now()]);
            Audit::record('statement.confirmed', (string) $id, null, 'SETTLED', $u->id, 'finance');

            return $this->db()->table('statement_rows')->find($id);
        }, 3);
    }

    private function balance(string $booking): string
    {
        $payment = $this->db()->table('payments')->where('booking_id', $booking)->first();
        $entries = $this->db()->table('ledger_entries')->where('booking_id', $booking)->orderBy('id')->get(['id', 'kind', 'amount']);

        return hash('sha256', json_encode([$payment?->status, $payment?->amount, $entries], JSON_THROW_ON_ERROR));
    }

    private function openEntry(?int $id, string $booking, bool $lock = false): ?object
    {
        if (! $id) {
            return null;
        }
        $entry = $this->db()->table('reconciliation_entries')->find($id) ?? abort(404);
        $run = $this->db()->table('reconciliation_runs')->where('id', $entry->run_id);
        if ($lock) {
            $run->lockForUpdate();
        }
        abort_unless($run->first()?->status === 'OPEN' && $entry->booking_id === $booking && in_array($entry->result, ['MISMATCH', 'EXCEPTION']), 409, 'Chọn sai lệch đúng vé trong kỳ còn mở.');

        return $entry;
    }

    public function adjustment(User $u, array $data): object
    {
        Access::role($u, 'finance');
        $d = Validator::make($data, ['request_id' => 'required|uuid', 'booking_id' => 'required|uuid', 'entry_id' => 'nullable|integer|min:1', 'amount' => 'required|integer|between:-100000000,100000000|not_in:0', 'reason' => 'required|string|between:10,2000'])->validate();
        abort_unless(DB::table('bookings')->where('id', $d['booking_id'])->exists(), 404);

        return $this->db()->transaction(function () use ($u, $d) {
            $db = $this->db();
            $payment = $db->table('payments')->where('booking_id', $d['booking_id'])->lockForUpdate()->first();
            abort_unless($payment && in_array($payment->status, ['CAPTURED', 'REFUNDED']), 409, 'Vé chưa có giao dịch thu/hoàn hợp lệ.');
            $old = $db->table('financial_adjustments')->find($d['request_id']);
            if ($old) {
                abort_unless($old->created_by === $u->id && $old->booking_id === $d['booking_id'] && $old->amount === (int) $d['amount'] && $old->reason === $d['reason'] && $old->entry_id === (($d['entry_id'] ?? null) ? (int) $d['entry_id'] : null), 409, 'Mã yêu cầu đã dùng.');

                return $old;
            }
            $this->openEntry($d['entry_id'] ?? null, $d['booking_id'], true);
            $db->table('financial_adjustments')->insert(['id' => $d['request_id'], 'booking_id' => $d['booking_id'], 'entry_id' => $d['entry_id'] ?? null, 'amount' => $d['amount'], 'fingerprint' => $this->balance($d['booking_id']), 'reason' => $d['reason'], 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('adjustment.requested', $d['request_id'], null, (string) $d['amount'], $u->id, 'finance');

            return $db->table('financial_adjustments')->find($d['request_id']);
        }, 3);
    }

    public function decideAdjustment(User $u, string $id, bool $approve, string $note): object
    {
        Access::role($u, 'finance');
        Validator::make(['note' => $note], ['note' => 'required|string|between:5,2000'])->validate();
        $base = $this->db()->table('financial_adjustments')->find($id) ?? abort(404);

        return $this->db()->transaction(function () use ($u, $id, $base, $approve, $note) {
            $db = $this->db();
            $db->table('payments')->where('booking_id', $base->booking_id)->lockForUpdate()->first();
            $a = $db->table('financial_adjustments')->where('id', $id)->lockForUpdate()->first();
            abort_if((int) $a->created_by === (int) $u->id, 409, 'Người duyệt phải khác người lập phiếu.');
            $status = $approve ? 'APPROVED' : 'REJECTED';
            if ($a->status !== 'PENDING') {
                abort_unless($a->status === $status, 409, 'Phiếu đã có quyết định khác.');

                return $a;
            }
            $entry = $this->openEntry($base->entry_id, $base->booking_id, true);
            if ($approve) {
                abort_unless(hash_equals($a->fingerprint, $this->balance($a->booking_id)), 409, 'Sổ giao dịch đã thay đổi; lập phiếu mới.');
                abort_if((int) $db->table('ledger_entries')->where('booking_id', $a->booking_id)->sum('amount') + $a->amount < 0, 409, 'Điều chỉnh làm số thu ròng âm.');
                $db->table('ledger_entries')->insert(['booking_id' => $a->booking_id, 'kind' => 'ADJUSTMENT:'.$a->id, 'amount' => $a->amount, 'created_at' => now()]);
                if ($entry) {
                    $db->table('reconciliation_entries')->where('id', $entry->id)->update(['result' => 'EXCEPTION', 'resolution' => 'Điều chỉnh đã duyệt '.$id.': '.$note, 'resolved_by' => $u->id]);
                }
            }
            $db->table('financial_adjustments')->where('id', $id)->update(['status' => $status, 'approved_by' => $u->id, 'decision_note' => $note, 'updated_at' => now()]);
            Audit::record('adjustment.decided', $id, 'PENDING', $status, $u->id, 'finance');

            return $db->table('financial_adjustments')->find($id);
        }, 3);
    }
}
