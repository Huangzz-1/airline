<?php

namespace App\Modules\Operations;

use App\Models\User;
use App\Modules\Booking\CommerceRepository;
use App\Modules\Booking\FareRules;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PassengerRecovery
{
    private function source(User $u, int $id): object
    {
        Access::role($u, 'operations', 'ops_manager');
        $f = app(FlightCatalog::class)->get($id);
        Access::airport($u, $f->origin);

        return $f;
    }

    private function peers(object $booking)
    {
        if (! $booking->reservation_id || ! $booking->passenger_id) {
            return collect();
        }

        return DB::table('bookings as b')->join('reservation_segments as s', 's.id', '=', 'b.reservation_segment_id')
            ->where('b.reservation_id', $booking->reservation_id)->where('b.passenger_id', $booking->passenger_id)->where('b.id', '!=', $booking->id)
            ->whereIn('b.status', ['HELD', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'PAYMENT_PENDING', 'EXCHANGE_PENDING'])
            ->where(fn ($q) => $q->where('b.status', '!=', 'HELD')->orWhere('b.expires_at', '>', now()))->orderBy('s.position')->select('b.*', 's.position')->get();
    }

    private function fingerprint(object $b): string
    {
        $f = app(FlightCatalog::class)->get($b->flight_id);
        $peers = $this->peers($b)->map(function ($p) {
            $f = app(FlightCatalog::class)->get($p->flight_id);

            return [$p->id, $p->status, $f->id, $f->departure, $f->arrival, $f->version];
        })->all();

        return hash('sha256', json_encode([$b->id, $b->status, $b->fare_class, $f->version, $peers], JSON_THROW_ON_ERROR));
    }

    public function connectionProblem(object $b, object $target): ?string
    {
        $position = $b->reservation_segment_id ? DB::table('reservation_segments')->where('id', $b->reservation_segment_id)->value('position') : null;
        foreach ($this->peers($b) as $p) {
            $f = app(FlightCatalog::class)->get($p->flight_id);
            if ($f->id === $target->id) {
                return 'Chuyến đã có trong lịch trình của hành khách.';
            }
            if ($position && $p->position < $position && Carbon::parse($f->arrival)->addMinutes(60)->gt(Carbon::parse($target->departure))) {
                return 'Không đủ 60 phút sau chuyến trước.';
            }
            if ($position && $p->position > $position && Carbon::parse($target->arrival)->addMinutes(60)->gt(Carbon::parse($f->departure))) {
                return 'Không kịp chuyến tiếp theo trong mã đặt chỗ.';
            }
        }

        return null;
    }

    private function target(object $b, int $id, string $fare): object
    {
        $source = app(FlightCatalog::class)->get($b->flight_id);
        $target = app(FlightCatalog::class)->get($id);
        abort_unless($target->id !== $source->id && $target->origin === $source->origin && $target->destination === $source->destination && now()->lt(Carbon::parse($target->departure)->subMinutes(30)), 422, 'Chọn chuyến khác cùng đường bay còn mở bán.');
        abort_if($problem = $this->connectionProblem($b, $target), 409, $problem ?? 'Lịch trình không hợp lệ.');
        $option = collect(app(FlightCatalog::class)->options(['origin' => $target->origin, 'destination' => $target->destination, 'date' => Carbon::parse($target->departure)->tz('Asia/Ho_Chi_Minh')->toDateString()]))->first(fn ($o) => $o->flight->id === $id && $o->fare->code === $fare && $o->remaining >= 1);
        abort_unless($option, 409, 'Chuyến/hạng vé thay thế đã hết chỗ.');

        return $option;
    }

    public function impact(User $u, int $id): array
    {
        $f = $this->source($u, $id);
        $ops = DB::connection('ops');
        $bookings = DB::table('bookings')->where('flight_id', $id)->whereIn('status', ['HELD', 'PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'EXCHANGE_PENDING'])
            ->where(fn ($q) => $q->where('status', '!=', 'HELD')->orWhere('expires_at', '>', now()))->get();
        $people = DB::table('reservation_passengers')->whereIn('id', $bookings->pluck('passenger_id')->filter())->get()->keyBy('id');
        $users = User::whereIn('id', $bookings->pluck('user_id')->unique())->pluck('name', 'id');
        $routes = $ops->table('flights')->where('origin', $f->origin)->where('destination', $f->destination)->where('id', '!=', $id)->whereIn('status', ['SCHEDULED', 'DELAYED', 'BOARDING'])->where('departure', '>', now()->addMinutes(30))->where('departure', '<=', now()->addDays(7))->orderBy('departure')->limit(40)->get();
        $options = [];
        foreach ($routes->groupBy(fn ($r) => Carbon::parse($r->departure)->tz('Asia/Ho_Chi_Minh')->toDateString()) as $date => $flights) {
            foreach (app(FlightCatalog::class)->options(['origin' => $f->origin, 'destination' => $f->destination, 'date' => $date]) as $o) {
                if ($o->remaining >= 1 && $flights->contains('id', $o->flight->id)) {
                    $options[] = $o;
                }
            }
        }
        foreach ($bookings as $b) {
            $b->passenger_name = $people->get($b->passenger_id)?->name ?? $users->get($b->user_id);
            $b->connections = $this->peers($b)->map(function ($p) use ($b) {
                $f = app(FlightCatalog::class)->get($p->flight_id);

                return ['flight' => $f, 'problem' => $this->connectionProblem($b, app(FlightCatalog::class)->get($b->flight_id))];
            });
            $b->alternatives = collect($options)->filter(fn ($o) => $o->flight->id !== $id && ! $this->connectionProblem($b, $o->flight))->values();
        }

        return ['flight' => $f, 'bookings' => $bookings, 'crew' => $ops->table('crew_assignments as a')->join('crew_members as c', 'c.id', '=', 'a.crew_member_id')->where('a.flight_id', $id)->where('a.active', true)->get(['c.name', 'c.position', 'c.qualification']), 'allocations' => $ops->table('resource_allocations as a')->join('airport_resources as r', 'r.id', '=', 'a.resource_id')->where('a.flight_id', $id)->get(['r.code', 'r.type', 'a.starts_at', 'a.ends_at']), 'tasks' => $ops->table('ground_tasks')->where('flight_id', $id)->get(), 'offers' => DB::table('recovery_offers')->where('flight_id', $id)->orderByDesc('created_at')->get()];
    }

    public function propose(User $u, array $input): object
    {
        Access::role($u, 'operations');
        $d = Validator::make($input, ['request_id' => 'required|uuid', 'booking_id' => 'required|uuid', 'target_flight_id' => 'required|integer', 'fare_class' => 'required|regex:/^[A-Z0-9_]{1,20}$/', 'reason' => 'required|string|between:5,255'])->validate();
        $b = DB::table('bookings')->find($d['booking_id']) ?? abort(404);
        $this->source($u, $b->flight_id);

        return DB::transaction(function () use ($u, $d, $b) {
            $ids = [$b->flight_id, (int) $d['target_flight_id']];
            sort($ids);
            foreach (array_unique($ids) as $id) {
                app(CommerceRepository::class)->lockInventory($id, true);
            }
            $b = DB::table('bookings')->where('id', $b->id)->lockForUpdate()->first();
            abort_unless(in_array($b->status, ['HELD', 'CONFIRMED', 'CHECKED_IN', 'REFUND_PENDING']), 409, 'Trạng thái vé không cho phép lập phương án.');
            $old = DB::table('recovery_offers')->find($d['request_id']);
            if ($old) {
                abort_unless($old->booking_id === $b->id && $old->target_flight_id === (int) $d['target_flight_id'] && $old->fare_class === $d['fare_class'] && $old->created_by === $u->id, 409, 'Mã đề nghị đã dùng.');

                return $old;
            }
            $target = $this->target($b, (int) $d['target_flight_id'], $d['fare_class']);
            DB::table('recovery_offers')->insert(['id' => $d['request_id'], 'booking_id' => $b->id, 'flight_id' => $b->flight_id, 'target_flight_id' => $target->flight->id, 'fare_class' => $d['fare_class'], 'quoted_amount' => $target->fare->price, 'fingerprint' => $this->fingerprint($b), 'reason' => $d['reason'], 'created_by' => $u->id, 'expires_at' => now()->addHours(24)->min(Carbon::parse($target->flight->departure)->subMinutes(30)), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('recovery.proposed', $d['request_id'], null, 'PENDING', $u->id);

            return DB::table('recovery_offers')->find($d['request_id']);
        }, 3);
    }

    public function decide(User $u, string $id, bool $approve): object
    {
        Access::role($u, 'ops_manager');
        $base = DB::table('recovery_offers')->find($id) ?? abort(404);
        $this->source($u, $base->flight_id);

        return DB::transaction(function () use ($u, $id, $base, $approve) {
            $ids = [$base->flight_id, $base->target_flight_id];
            sort($ids);
            foreach (array_unique($ids) as $flight) {
                app(CommerceRepository::class)->lockInventory($flight, true);
            }
            $offer = DB::table('recovery_offers')->where('id', $id)->lockForUpdate()->first();
            abort_if($offer->created_by === $u->id, 409, 'Người duyệt phải khác người lập.');
            $status = $approve ? 'APPROVED' : 'REJECTED';
            if ($offer->status !== 'PENDING') {
                abort_unless($offer->status === $status, 409, 'Phương án đã có quyết định khác.');

                return $offer;
            }
            $b = DB::table('bookings')->find($offer->booking_id);
            if ($approve) {
                abort_unless(now()->lt($offer->expires_at) && hash_equals($offer->fingerprint, $this->fingerprint($b)), 409, 'Vé hoặc lịch bay đã thay đổi; cần lập lại phương án.');
                $quote = $this->target($b, $offer->target_flight_id, $offer->fare_class);
                abort_unless((int) $quote->fare->price === $offer->quoted_amount, 409, 'Giá chuyến thay thế đã thay đổi.');
            }
            DB::table('recovery_offers')->where('id', $id)->update(['status' => $status, 'approved_by' => $u->id, 'updated_at' => now()]);
            if ($approve) {
                Events::notify($b->user_id, 'Phương án chuyến bay thay thế', 'Đã có phương án cho vé '.$b->reference.'. Xem chi tiết tại '.url('/recovery-offers/'.$id).'. Chỗ và giá được kiểm tra lại trước khi bạn xác nhận.');
            }
            Audit::record('recovery.decided', $id, 'PENDING', $status, $u->id);

            return DB::table('recovery_offers')->find($id);
        }, 3);
    }

    public function customer(User $u, string $id): array
    {
        Access::role($u, 'passenger');
        $o = DB::table('recovery_offers as o')->join('bookings as b', 'b.id', '=', 'o.booking_id')->where('o.id', $id)->where('b.user_id', $u->id)->select('o.*')->first() ?? abort(404);
        abort_unless($o->status === 'APPROVED' && now()->lt($o->expires_at), 409, 'Phương án chưa được duyệt hoặc đã hết hạn.');
        $b = DB::table('bookings')->find($o->booking_id);
        $target = $this->target($b, $o->target_flight_id, $o->fare_class);
        abort_unless((int) $target->fare->price === $o->quoted_amount, 409, 'Giá đã thay đổi; vui lòng liên hệ hỗ trợ để nhận phương án mới.');

        return ['offer' => $o, 'booking' => $b, 'source' => app(FlightCatalog::class)->get($b->flight_id), 'target' => $target->flight, 'fare' => $target->fare, 'sourceRules' => FareRules::decode($b->fare_rules)];
    }
}
