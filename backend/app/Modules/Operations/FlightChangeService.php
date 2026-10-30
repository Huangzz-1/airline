<?php

namespace App\Modules\Operations;

use App\Models\User;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FlightChangeService
{
    private function db()
    {
        return DB::connection('ops');
    }

    private function crewIds(int $flight): array
    {
        return $this->db()->table('crew_assignments')->where('flight_id', $flight)->where('active', true)->orderBy('crew_member_id')->pluck('crew_member_id')->all();
    }

    public function propose(User $u, array $data): object
    {
        $d = Validator::make($data, ['flight_id' => 'required|integer', 'kind' => 'required|in:AIRCRAFT,CREW', 'target_aircraft_id' => 'nullable|integer', 'crew_ids' => 'nullable|string|regex:/^[0-9]+(,[0-9]+){0,19}$/', 'reason' => 'required|string|between:5,255'])->validate();
        Access::role($u, ...($d['kind'] === 'AIRCRAFT' ? ['operations'] : ['crew_scheduler']));

        return $this->db()->transaction(function () use ($u, $d) {
            $f = $this->db()->table('flights')->where('id', $d['flight_id'])->lockForUpdate()->first() ?? abort(404);
            Access::airport($u, $f->origin);
            abort_unless(in_array($f->status, $d['kind'] === 'AIRCRAFT' ? ['SCHEDULED', 'DELAYED'] : ['SCHEDULED', 'DELAYED', 'BOARDING']) && ! $f->pending_change_id, 409, 'Chuyến không nhận phương án đổi nguồn lực ở trạng thái này.');
            $before = ['aircraft_id' => $f->aircraft_id, 'crew_ids' => $this->crewIds($f->id)];
            if ($d['kind'] === 'AIRCRAFT') {
                abort_unless(isset($d['target_aircraft_id']) && (int) $d['target_aircraft_id'] !== $f->aircraft_id, 422, 'Chọn tàu bay khác.');
                $new = $this->db()->table('aircraft')->find((int) $d['target_aircraft_id']) ?? abort(422, 'Tàu mới không tồn tại.');
                $old = $this->db()->table('aircraft')->find($f->aircraft_id);
                abort_unless($new->status === 'AVAILABLE' && $new->type === $old->type, 409, 'Chọn tàu bay khả dụng cùng loại và năng định.');
                $after = ['aircraft_id' => $new->id, 'capacity' => $new->capacity];
            } else {
                abort_unless(! empty($d['crew_ids']), 422, 'Cần danh sách tổ bay mới.');
                $ids = array_values(array_unique(array_map('intval', explode(',', $d['crew_ids']))));
                sort($ids);
                abort_if($ids === $before['crew_ids'], 422, 'Tổ bay mới không thay đổi.');
                $after = ['crew_ids' => $ids];
            }
            $id = (string) Str::uuid();
            $this->db()->table('flight_changes')->insert(['id' => $id, 'flight_id' => $f->id, 'kind' => $d['kind'], 'source_version' => $f->version, 'previous_status' => $f->status, 'before_data' => json_encode($before), 'after_data' => json_encode($after), 'reason' => $d['reason'], 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('flight_change.proposed', $id, null, 'PENDING', $u->id, 'ops');

            return $this->db()->table('flight_changes')->find($id);
        }, 3);
    }

    private function lockContext(object $plan): object
    {
        $base = $this->db()->table('flights')->find($plan->flight_id) ?? abort(404);
        $this->db()->table('airports')->where('code', $base->origin)->lockForUpdate()->first();
        $before = json_decode($plan->before_data, true);
        $after = json_decode($plan->after_data, true);
        $planes = array_unique([$base->aircraft_id, $before['aircraft_id'], $after['aircraft_id'] ?? $base->aircraft_id]);
        sort($planes);
        foreach ($planes as $id) {
            $this->db()->table('aircraft')->where('id', $id)->lockForUpdate()->first();
        }

        return $this->db()->table('flights')->where('id', $base->id)->lockForUpdate()->first();
    }

    public function decide(User $u, array $data): object
    {
        Access::role($u, 'ops_manager');
        $d = Validator::make($data, ['change_id' => 'required|uuid', 'approve' => 'required|boolean'])->validate();
        $base = $this->db()->table('flight_changes')->find($d['change_id']) ?? abort(404);

        return $this->db()->transaction(function () use ($u, $d, $base) {
            $f = $this->lockContext($base);
            Access::airport($u, $f->origin);
            $p = $this->db()->table('flight_changes')->where('id', $base->id)->lockForUpdate()->first();
            abort_unless($p->status === 'PENDING' && $p->created_by !== $u->id, 409, 'Cần phương án chờ duyệt và người duyệt độc lập.');
            if (! $d['approve']) {
                $this->db()->table('flight_changes')->where('id', $p->id)->update(['status' => 'REJECTED', 'approved_by' => $u->id, 'updated_at' => now()]);
                Audit::record('flight_change.rejected', $p->id, 'PENDING', 'REJECTED', $u->id, 'ops');

                return $this->db()->table('flight_changes')->find($p->id);
            }
            abort_unless($f->version === $p->source_version && ! $f->pending_change_id && in_array($f->status, $p->kind === 'AIRCRAFT' ? ['SCHEDULED', 'DELAYED'] : ['SCHEDULED', 'DELAYED', 'BOARDING']), 409, 'Nguồn đã thay đổi hoặc chuyến đang có phương án khác; cần lập lại.');
            abort_unless(now()->lt($f->departure), 409, 'Chuyến đã qua giờ khởi hành.');
            $after = json_decode($p->after_data, true);
            if ($p->kind === 'CREW') {
                return $this->replaceCrew($u, $p, $f, $after['crew_ids']);
            }
            $old = $this->db()->table('aircraft')->find($f->aircraft_id);
            $new = $this->db()->table('aircraft')->find($after['aircraft_id']);
            abort_unless($new && $new->status === 'AVAILABLE' && $new->type === $old->type && $new->capacity === $after['capacity'], 409, 'Tàu mới không còn phù hợp với phương án.');
            $this->assertAvailable($new->id, $f);
            $candidate = (object) array_merge((array) $f, ['aircraft_id' => $new->id]);
            $this->validateCrew($this->crewIds($f->id), $candidate);
            foreach (array_unique([$old->id, $new->id]) as $id) {
                $this->db()->table('change_reservations')->insert(['change_id' => $p->id, 'flight_id' => $f->id, 'aircraft_id' => $id, 'starts_at' => Carbon::parse($f->departure)->subMinutes(30), 'ends_at' => Carbon::parse($f->arrival)->addMinutes(30)]);
            }
            $fence = $f->version + 1;
            $this->db()->table('flights')->where('id', $f->id)->update(['status' => 'CHANGE_PENDING', 'pending_change_id' => $p->id, 'version' => $fence]);
            $this->db()->table('flight_changes')->where('id', $p->id)->update(['status' => 'PREPARING', 'approved_by' => $u->id, 'updated_at' => now()]);
            Events::emit('ops', 'AircraftChangePrepare', ['change_id' => $p->id, 'flight_id' => $f->id, 'target_aircraft_id' => $new->id, 'capacity' => $new->capacity, 'fence_version' => $fence, 'departure' => $f->departure, 'number' => $f->number]);
            Audit::record('aircraft_change.preparing', $p->id, 'PENDING', 'PREPARING', $u->id, 'ops');

            return $this->db()->table('flight_changes')->find($p->id);
        }, 3);
    }

    private function assertAvailable(int $plane, object $f): void
    {
        abort_if($this->db()->table('flights')->where('aircraft_id', $plane)->where('id', '!=', $f->id)->whereNotIn('status', ['DRAFT', 'PENDING', 'CANCELLED'])->where('departure', '<', Carbon::parse($f->arrival)->addMinutes(30))->where('arrival', '>', Carbon::parse($f->departure)->subMinutes(30))->exists(), 409, 'Tàu mới có xung đột lịch/quay đầu.');
        abort_if($this->db()->table('change_reservations')->where('aircraft_id', $plane)->where('starts_at', '<', Carbon::parse($f->arrival)->addMinutes(30))->where('ends_at', '>', Carbon::parse($f->departure)->subMinutes(30))->exists(), 409, 'Tàu đang được giữ cho phương án khác.');
    }

    private function validateCrew(array $ids, object $flight): void
    {
        sort($ids);
        $crew = $this->db()->table('crew_members')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($crew->count() === count($ids) && $crew->where('position', 'PILOT')->count() >= 1 && $crew->where('position', 'CABIN')->count() >= 1, 409, 'Cần tổ bay đủ vị trí phi công và tiếp viên.');
        foreach ($crew as $member) {
            app(OperationsService::class)->checkCrew($member, $flight);
        }
    }

    private function replaceCrew(User $u, object $p, object $f, array $ids): object
    {
        $all = array_unique(array_merge($ids, $this->crewIds($f->id)));
        sort($all);
        $this->db()->table('crew_members')->whereIn('id', $all)->orderBy('id')->lockForUpdate()->get();
        $this->validateCrew($ids, $f);
        $this->db()->table('crew_assignments')->where('flight_id', $f->id)->update(['active' => false]);
        foreach ($ids as $id) {
            $this->db()->table('crew_assignments')->updateOrInsert(['flight_id' => $f->id, 'crew_member_id' => $id], ['active' => true, 'assigned_by' => $u->id, 'created_at' => now()]);
        }
        $this->db()->table('flights')->where('id', $f->id)->update(['version' => $f->version + 1]);
        $this->db()->table('flight_changes')->where('id', $p->id)->update(['status' => 'COMPLETED', 'approved_by' => $u->id, 'updated_at' => now()]);
        $this->emitFlight($f->id, 'Tổ bay đã được thay thế và phê duyệt; giờ bay giữ nguyên.');
        Audit::record('crew.replaced', $p->id, 'PENDING', 'COMPLETED', $u->id, 'ops');

        return $this->db()->table('flight_changes')->find($p->id);
    }

    private function emitFlight(int $id, string $notice): void
    {
        $f = $this->db()->table('flights')->find($id);
        $plane = $this->db()->table('aircraft')->find($f->aircraft_id);
        Events::emit('ops', 'FlightChanged', ['flight_id' => $id, 'status' => $f->status, 'version' => $f->version, 'capacity' => $plane->capacity, 'price' => $f->sale_price, 'number' => $f->number, 'origin' => $f->origin, 'departure' => $f->departure, 'notice' => $notice]);
    }

    public function prepared(string $event, array $data): void
    {
        $base = $this->db()->table('flight_changes')->find($data['change_id']) ?? throw new \RuntimeException('Unknown aircraft change');
        $this->db()->transaction(function () use ($base, $event, $data) {
            $f = $this->lockContext($base);
            $p = $this->db()->table('flight_changes')->where('id', $base->id)->lockForUpdate()->first();
            if (! $this->db()->table('inbox')->insertOrIgnore(['id' => $event, 'received_at' => now()])) {
                return;
            }
            if (in_array($p->status, ['COMPLETED', 'FAILED'])) {
                return;
            }
            if ($p->status !== 'PREPARING' || $f->pending_change_id !== $p->id || $f->version !== $p->source_version + 1) {
                throw new \RuntimeException('Aircraft preparation phase mismatch');
            }
            if ($data['result'] === 'REJECTED') {
                $this->db()->table('flights')->where('id', $f->id)->update(['status' => $p->previous_status, 'pending_change_id' => null, 'version' => $f->version + 1]);
                $this->db()->table('flight_changes')->where('id', $p->id)->update(['status' => 'FAILED', 'failure_reason' => $data['reason'], 'updated_at' => now()]);
                $this->db()->table('change_reservations')->where('change_id', $p->id)->delete();
                $this->emitFlight($f->id, 'Phương án đổi tàu không áp dụng; tàu và chỗ ngồi cũ được giữ nguyên.');
                Audit::record('aircraft_change.failed', $p->id, 'PREPARING', $data['reason'], null, 'ops');

                return;
            }
            if ($data['result'] !== 'READY') {
                throw new \RuntimeException('Unknown preparation result');
            }
            $this->db()->table('flight_changes')->where('id', $p->id)->update(['status' => 'COMMITTING', 'updated_at' => now()]);
            $after = json_decode($p->after_data, true);
            Events::emit('ops', 'AircraftChangeCommit', ['change_id' => $p->id, 'flight_id' => $f->id, 'fence_version' => $f->version, 'target_aircraft_id' => $after['aircraft_id'], 'capacity' => $after['capacity'], 'departure' => $f->departure, 'number' => $f->number]);
            Audit::record('aircraft_change.ready', $p->id, 'PREPARING', 'COMMITTING', null, 'ops');
        }, 3);
    }

    public function committed(string $event, array $data): void
    {
        $base = $this->db()->table('flight_changes')->find($data['change_id']) ?? throw new \RuntimeException('Unknown aircraft change');
        $this->db()->transaction(function () use ($base, $event) {
            $f = $this->lockContext($base);
            $p = $this->db()->table('flight_changes')->where('id', $base->id)->lockForUpdate()->first();
            if (! $this->db()->table('inbox')->insertOrIgnore(['id' => $event, 'received_at' => now()])) {
                return;
            }
            if ($p->status === 'COMPLETED') {
                return;
            }
            if ($p->status !== 'COMMITTING' || $f->pending_change_id !== $p->id || $f->version !== $p->source_version + 1) {
                throw new \RuntimeException('Aircraft commit phase mismatch');
            }
            $after = json_decode($p->after_data, true);
            $this->db()->table('flights')->where('id', $f->id)->update(['aircraft_id' => $after['aircraft_id'], 'status' => $p->previous_status, 'pending_change_id' => null, 'version' => $f->version + 1]);
            $this->db()->table('flight_changes')->where('id', $p->id)->update(['status' => 'COMPLETED', 'updated_at' => now()]);
            $this->db()->table('change_reservations')->where('change_id', $p->id)->delete();
            $this->emitFlight($f->id, 'Đổi tàu đã hoàn tất. Vui lòng kiểm tra ghế và thẻ lên tàu mới trong hành trình.');
            Audit::record('aircraft_change.completed', $p->id, 'COMMITTING', 'COMPLETED', null, 'ops');
        }, 3);
    }

    public function detail(User $u, string $id): object
    {
        Access::role($u, 'operations', 'ops_manager', 'crew_scheduler');
        $p = $this->db()->table('flight_changes')->find($id) ?? abort(404);
        $f = $this->db()->table('flights')->find($p->flight_id);
        Access::airport($u, $f->origin);
        $p->flight = $f;
        $p->before = json_decode($p->before_data, true);
        $p->after = json_decode($p->after_data, true);
        $stock = DB::table('inventory')->where('flight_id', $f->id)->first();
        $p->inventory_frozen = $stock && $stock->frozen_change_id === $p->id;
        if ($p->kind === 'AIRCRAFT') {
            $p->aircraft_labels = $this->db()->table('aircraft')->whereIn('id', [$p->before['aircraft_id'], $p->after['aircraft_id']])->get()->mapWithKeys(fn ($a) => [$a->id => $a->registration.' · '.$a->type])->all();
        } else {
            $p->crew_labels = $this->db()->table('crew_members')->whereIn('id', array_merge($p->before['crew_ids'], $p->after['crew_ids']))->get()->mapWithKeys(fn ($c) => [$c->id => $c->code.' · '.$c->name])->all();
        }
        $p->impact = DB::table('bookings')->where('flight_id', $f->id)->whereIn('status', ['HELD', 'PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'EXCHANGE_PENDING'])->where(fn ($q) => $q->where('status', '!=', 'HELD')->orWhere('expires_at', '>', now()))->count();
        $p->mapping = DB::table('seat_reassignments as s')->join('bookings as b', 'b.id', '=', 's.booking_id')->where('s.change_id', $p->id)->get(['s.booking_id', 's.old_seat', 's.new_seat', 'b.reference']);

        return $p;
    }
}
