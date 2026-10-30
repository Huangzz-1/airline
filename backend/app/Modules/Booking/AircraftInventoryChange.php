<?php

namespace App\Modules\Booking;

use App\Modules\Boarding\SeatMap;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AircraftInventoryChange
{
    private const OCCUPIED = ['PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'EXCHANGE_PENDING'];

    private function bookings(int $flight)
    {
        return DB::table('bookings')->where('flight_id', $flight)->where(fn ($q) => $q->whereIn('status', self::OCCUPIED)->orWhere(fn ($x) => $x->where('status', 'HELD')->where('expires_at', '>', now())))->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
    }

    public function prepare(string $id, array $p): void
    {
        DB::transaction(function () use ($id, $p) {
            $stock = app(CommerceRepository::class)->lockInventory($p['flight_id'], true);
            if (DB::table('inbox')->where('id', $id)->exists()) {
                return;
            }
            $old = DB::table('aircraft_change_receipts')->where('change_id', $p['change_id'])->first();
            if ($old) {
                $this->same($old, $p);
                DB::table('inbox')->insert(['id' => $id, 'received_at' => now()]);

                return;
            }
            if ($stock->frozen_change_id && $stock->frozen_change_id !== $p['change_id']) {
                throw new \RuntimeException('Inventory frozen by another change');
            }
            $bookings = $this->bookings($p['flight_id']);
            $capacity = min((int) $stock->capacity, (int) $p['capacity']);
            $reason = null;
            if ($stock->source_version >= $p['fence_version']) {
                $reason = 'Phiên bản tồn chỗ đã thay đổi; không áp dụng dữ liệu cũ.';
            } elseif ($bookings->contains('status', 'BOARDED')) {
                $reason = 'Đã có khách boarding; cần quy trình offload riêng trước khi đổi tàu.';
            } elseif ($bookings->count() > $capacity) {
                $reason = 'Tàu mới không đủ chỗ cho các booking còn hiệu lực ('.$bookings->count().'/'.$capacity.').';
            }
            $status = $reason ? 'REJECTED' : 'PREPARED';
            DB::table('aircraft_change_receipts')->insert(['change_id' => $p['change_id'], 'flight_id' => $p['flight_id'], 'target_aircraft_id' => $p['target_aircraft_id'], 'fence_version' => $p['fence_version'], 'old_capacity' => $stock->capacity, 'old_status' => $stock->status, 'new_capacity' => $capacity, 'requested_capacity' => $p['capacity'], 'departure' => Carbon::parse($p['departure'])->utc(), 'flight_number' => $p['number'], 'status' => $status, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            if (! $reason) {
                DB::table('inventory')->where('flight_id', $p['flight_id'])->update(['frozen_change_id' => $p['change_id'], 'status' => 'RECONFIGURING', 'source_version' => $p['fence_version']]);
            }
            DB::table('inbox')->insert(['id' => $id, 'received_at' => now()]);
            Events::emit('mysql', 'AircraftChangePrepared', ['change_id' => $p['change_id'], 'result' => $reason ? 'REJECTED' : 'READY', 'reason' => $reason]);
            Audit::record('aircraft_change.inventory_prepare', $p['change_id'], null, $status);
        }, 3);
    }

    private function same(object $receipt, array $p): void
    {
        if ($receipt->flight_id !== (int) $p['flight_id'] || $receipt->target_aircraft_id !== (int) $p['target_aircraft_id'] || $receipt->fence_version !== (int) $p['fence_version'] || $receipt->requested_capacity !== (int) $p['capacity'] || ! Carbon::parse($receipt->departure)->eq(Carbon::parse($p['departure'])) || $receipt->flight_number !== $p['number']) {
            throw new \RuntimeException('Change replay payload mismatch');
        }
    }

    public function commit(string $id, array $p): void
    {
        DB::transaction(function () use ($id, $p) {
            $stock = app(CommerceRepository::class)->lockInventory($p['flight_id'], true);
            if (DB::table('inbox')->where('id', $id)->exists()) {
                return;
            }
            $receipt = DB::table('aircraft_change_receipts')->where('change_id', $p['change_id'])->lockForUpdate()->first() ?? throw new \RuntimeException('Change not prepared');
            $this->same($receipt, $p);
            if ($receipt->status === 'COMMITTED') {
                DB::table('inbox')->insert(['id' => $id, 'received_at' => now()]);

                return;
            }
            if ($receipt->status !== 'PREPARED' || $stock->frozen_change_id !== $p['change_id']) {
                throw new \RuntimeException('Inventory commit phase mismatch');
            }
            $bookings = $this->bookings($p['flight_id']);
            if ($bookings->count() > $receipt->new_capacity || $bookings->contains('status', 'BOARDED')) {
                throw new \RuntimeException('Frozen inventory invariant violated');
            }
            $checked = $bookings->where('status', 'CHECKED_IN');
            $occupied = [];
            foreach ($checked as $b) {
                if ($b->seat && SeatMap::contains($b->seat, $receipt->new_capacity)) {
                    $occupied[$b->seat] = true;
                }
            }
            foreach ($checked as $b) {
                $seat = $b->seat;
                if (! $seat || ! SeatMap::contains($seat, $receipt->new_capacity)) {
                    $seat = null;
                    for ($i = 0; $i < $receipt->new_capacity; $i++) {
                        $candidate = (intdiv($i, 6) + 1).chr(65 + $i % 6);
                        if (! isset($occupied[$candidate])) {
                            $seat = $candidate;
                            break;
                        }
                    }
                    if (! $seat) {
                        throw new \RuntimeException('No remapping seat available');
                    }
                    $occupied[$seat] = true;
                }
                DB::table('bookings')->where('id', $b->id)->update(['seat' => $seat, 'updated_at' => now()]);
                $pass = DB::table('boarding_passes')->where('booking_id', $b->id)->lockForUpdate()->first();
                if (! $pass || $pass->used_at) {
                    throw new \RuntimeException('Boarding pass is not eligible for reissue');
                }
                $token = bin2hex(random_bytes(32));
                DB::table('boarding_passes')->where('id', $pass->id)->update(['token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token), 'revoked_at' => null, 'used_at' => null, 'expires_at' => Carbon::parse($receipt->departure)->subMinutes(10), 'updated_at' => now()]);
                DB::table('seat_reassignments')->insert(['change_id' => $p['change_id'], 'booking_id' => $b->id, 'old_seat' => $b->seat, 'new_seat' => $seat, 'created_at' => now()]);
                Events::notify($b->user_id, 'Đổi tàu chuyến '.$receipt->flight_number, 'Ghế '.$b->seat.' → '.$seat.'. QR cũ hết hiệu lực; thẻ mới đã cấp trong hành trình.');
                Audit::record('aircraft_change.seat', $b->id, $b->seat, $seat);
            }
            DB::table('inventory')->where('flight_id', $p['flight_id'])->update(['capacity' => $receipt->new_capacity]);
            DB::table('fare_classes')->where('flight_id', $p['flight_id'])->where('quota', '>', $receipt->new_capacity)->update(['quota' => $receipt->new_capacity]);
            DB::table('aircraft_change_receipts')->where('change_id', $p['change_id'])->update(['status' => 'COMMITTED', 'updated_at' => now()]);
            DB::table('inbox')->insert(['id' => $id, 'received_at' => now()]);
            Events::emit('mysql', 'AircraftChangeCommitted', ['change_id' => $p['change_id']]);
            Audit::record('aircraft_change.inventory_commit', $p['change_id'], 'PREPARED', 'COMMITTED');
        }, 3);
    }
}
