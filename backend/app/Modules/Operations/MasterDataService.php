<?php

namespace App\Modules\Operations;

use App\Models\User;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MasterDataService
{
    const RULES = [
        'airports' => ['data_admin', ['name' => 'required|string|max:120', 'city' => 'required|string|max:100', 'timezone' => 'required|timezone']],
        'routes' => ['data_admin', ['active' => 'required|boolean', 'duration_minutes' => 'required|integer|between:15,1440', 'distance_km' => 'required|integer|between:1,30000']],
        'airport_resources' => ['data_admin', ['active' => 'required|boolean']],
        'aircraft' => ['fleet_manager', ['type' => 'required|string|max:80', 'capacity' => 'required|integer|between:1,600']],
        'crew_members' => ['crew_scheduler', ['qualification' => 'required|string|max:80', 'license_expires' => 'required|date|after:today', 'available' => 'required|boolean']],
    ];

    private function record($db, string $entity, string $id, bool $lock = false)
    {
        $q = $db->table($entity)->where($entity === 'airports' ? 'code' : 'id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? abort(404);
    }

    public function propose(User $u, array $data): object
    {
        $base = Validator::make($data, ['entity' => 'required|in:'.implode(',', array_keys(self::RULES)), 'record_id' => 'required|string|max:30', 'reason' => 'required|string|min:5|max:255', 'changes' => 'required|array'])->validate();
        [$role,$rules] = self::RULES[$base['entity']];
        Access::role($u, $role);
        $changes = Validator::make($base['changes'], $rules)->validate();
        $db = DB::connection('ops');

        return $db->transaction(function () use ($u, $db, $base, $changes) {
            $current = $this->record($db, $base['entity'], $base['record_id'], true);
            $id = $db->table('master_changes')->insertGetId(['entity' => $base['entity'], 'record_id' => $base['record_id'], 'before_data' => json_encode($current), 'after_data' => json_encode($changes), 'reason' => $base['reason'], 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('master.proposed', (string) $id, null, 'PENDING', $u->id, 'ops');

            return (object) ['id' => $id];
        });
    }

    public function approve(User $u, int $id): object
    {
        $db = DB::connection('ops');
        $base = $db->table('master_changes')->find($id) ?? abort(404);
        Access::role($u, self::RULES[$base->entity][0]);
        if (json_decode($base->before_data) === null) {
            return $this->approveImported($u, $id, $base);
        }

        return $db->transaction(function () use ($u, $db, $id, $base) {
            $current = $this->record($db, $base->entity, $base->record_id, true);
            $change = $db->table('master_changes')->where('id', $id)->lockForUpdate()->first();
            abort_unless($change->status === 'PENDING' && $change->created_by !== $u->id, 409, 'Cần người duyệt độc lập.');
            $actual = (array) $current;
            $before = (array) json_decode($change->before_data);
            ksort($actual);
            ksort($before);
            abort_unless($actual === $before, 409, 'Dữ liệu nguồn đã đổi; cần lập phiên bản mới.');
            $d = json_decode($change->after_data, true);
            if ($change->entity === 'aircraft') {
                abort_if($db->table('change_reservations')->where('aircraft_id', $current->id)->exists(), 409, 'Tàu đang giữ cho phương án điều chỉnh.');
                abort_if($db->table('flights')->where('aircraft_id', $current->id)->whereNotIn('status', ['DRAFT', 'PENDING', 'CANCELLED', 'ARRIVED'])->where('arrival', '>', now())->exists(), 409, 'Tàu đã có lịch công bố; cần quy trình đổi tàu.');
            }
            if ($change->entity === 'crew_members') {
                abort_if($db->table('crew_assignments as a')->join('flights as f', 'f.id', '=', 'a.flight_id')->where('a.active', true)->where('a.crew_member_id', $current->id)->whereNotIn('f.status', ['DRAFT', 'PENDING', 'CANCELLED', 'ARRIVED'])->where('f.arrival', '>', now())->exists(), 409, 'Nhân sự có phân công đã công bố; cần thay thế trước.');
            }
            if ($change->entity === 'airport_resources' && ! $d['active']) {
                abort_if($db->table('resource_allocations')->where('resource_id', $current->id)->where('ends_at', '>', now())->exists(), 409, 'Tài nguyên còn phân bổ hiệu lực.');
            }
            $db->table($change->entity)->where($change->entity === 'airports' ? 'code' : 'id', $change->record_id)->update($d);
            $db->table('master_changes')->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $u->id, 'updated_at' => now()]);
            Audit::record('master.approved', (string) $id, 'PENDING', 'APPROVED', $u->id, 'ops');

            return (object) ['id' => $id];
        }, 3);
    }

    private function approveImported(User $u, int $id, object $base): object
    {
        abort_unless($base->entity === 'airports', 409);
        $db = DB::connection('ops');

        return $db->transaction(function () use ($db, $u, $id, $base) {
            $change = $db->table('master_changes')->where('id', $id)->lockForUpdate()->first();
            abort_unless($change->status === 'PENDING' && $change->created_by !== $u->id, 409, 'Cần người duyệt độc lập.');
            abort_if($db->table('airports')->where('code', $base->record_id)->lockForUpdate()->first(), 409, 'Mã sân bay đã được sử dụng.');
            $db->table('airports')->insert(json_decode($change->after_data, true));
            $db->table('master_changes')->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $u->id, 'updated_at' => now()]);
            Audit::record('master.import.approved', (string) $id, 'PENDING', 'APPROVED', $u->id, 'ops');

            return (object) ['id' => $id];
        }, 3);
    }
}
