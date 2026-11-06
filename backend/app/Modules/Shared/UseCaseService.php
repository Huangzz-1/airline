<?php

namespace App\Modules\Shared;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Transactional handlers for the data and workflow objects introduced by the
 * Use Case specification.  The existing domain services remain the source of
 * truth for booking and flight state machines; this service owns the smaller
 * master-data, configuration and evidence records around them.
 */
class UseCaseService
{
    private const OPS_ROLES = ['operations', 'ops_manager'];

    public function execute(User $user, string $action, array $input): object
    {
        $handlers = [
            'airport_update' => 'airportUpdate', 'airport_area' => 'airportArea', 'season_rule' => 'seasonRule',
            'aircraft_model' => 'aircraftModel', 'crew_qualification' => 'crewQualification', 'crew_availability' => 'crewAvailability',
            'crew_roster' => 'crewRoster', 'maintenance_item' => 'maintenanceItem', 'work_item' => 'workItem',
            'maintenance_material' => 'maintenanceMaterial', 'work_material' => 'workMaterial',
            'ground_service' => 'groundService', 'ground_task_service' => 'groundTaskService', 'ground_equipment' => 'groundEquipment',
            'ground_task_equipment' => 'groundTaskEquipment', 'ground_handoff' => 'groundHandoff',
            'integration_connection' => 'integrationConnection', 'fare_rule_version' => 'fareRuleVersion',
            'baggage_allowance' => 'baggageAllowance', 'ancillary' => 'ancillary', 'ancillary_decide' => 'ancillaryDecide', 'baggage_incident' => 'baggageIncident', 'baggage_incident_resolve' => 'baggageIncidentResolve',
            'notification_template' => 'notificationTemplate', 'notification_send' => 'notificationSend',
            'customer_interaction' => 'customerInteraction', 'system_config' => 'systemConfig', 'document_verify' => 'documentVerify',
            'assistance' => 'assistance', 'actual_time' => 'actualTime', 'fee_catalog' => 'feeCatalog',
            'reconciliation_reason' => 'reconciliationReason', 'reconciliation_adjustment' => 'reconciliationAdjustment',
            'reconciliation_adjustment_decide' => 'reconciliationAdjustmentDecide', 'reconciliation_import' => 'reconciliationImport',
            'dataset' => 'dataset', 'model_run' => 'modelRun', 'recommendation' => 'recommendation',
            'support_compensation' => 'supportCompensation', 'support_compensation_decide' => 'supportCompensationDecide', 'notification_delivery_update' => 'notificationDeliveryUpdate',
        ];
        abort_unless(isset($handlers[$action]), 404, 'Thao tác không được hỗ trợ.');

        return DB::connection($this->connectionFor($action))->transaction(
            fn () => $this->{$handlers[$action]}($user, $input), 3
        );
    }

    private function connectionFor(string $action): string
    {
        return match (true) {
            in_array($action, ['airport_update', 'airport_area', 'season_rule', 'aircraft_model', 'crew_qualification', 'crew_availability', 'crew_roster', 'maintenance_item', 'work_item', 'maintenance_material', 'work_material', 'ground_service', 'ground_task_service', 'ground_equipment', 'ground_task_equipment', 'ground_handoff', 'actual_time'], true) => 'ops',
            in_array($action, ['fee_catalog', 'reconciliation_reason', 'reconciliation_adjustment', 'reconciliation_adjustment_decide', 'reconciliation_import'], true) => 'finance',
            default => 'mysql',
        };
    }

    private function validate(array $input, array $rules): array
    {
        return Validator::make($input, $rules)->validate();
    }

    private function ops(User $u): \Illuminate\Database\Connection
    {
        Access::role($u, ...self::OPS_ROLES);
        return DB::connection('ops');
    }

    private function audit(string $action, int|string $id, User $u, ?string $before = null, ?string $after = null, string $node = 'mysql'): void
    {
        Audit::record($action, (string) $id, $before, $after, $u->id, $node);
    }

    private function result(int|string $id, string $status = 'ACTIVE', array $extra = []): object
    {
        return (object) array_merge(['id' => $id, 'status' => $status], $extra);
    }

    private function airportUpdate(User $u, array $input): object
    {
        Access::role($u, 'data_admin');
        $d = $this->validate($input, ['code' => 'required|regex:/^[A-Z]{3}$/', 'name' => 'required|string|max:120', 'city' => 'required|string|max:100', 'timezone' => 'required|timezone', 'icao' => 'nullable|regex:/^[A-Z0-9]{4}$/', 'country_code' => 'nullable|regex:/^[A-Z]{2}$/', 'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180', 'operating_hours' => 'nullable|array', 'active' => 'sometimes|boolean']);
        $db = DB::connection('ops');
        $row = $db->table('airports')->where('code', $d['code'])->lockForUpdate()->first();
        abort_unless($row, 404, 'Sân bay không tồn tại.');
        $before = json_encode($row, JSON_THROW_ON_ERROR);
        $d['active'] = $d['active'] ?? ($row->active ?? true);
        if (isset($d['operating_hours'])) $d['operating_hours'] = json_encode($d['operating_hours'], JSON_THROW_ON_ERROR);
        $db->table('airports')->where('code', $d['code'])->update($d);
        $this->audit('airport.updated', $d['code'], $u, $before, json_encode($d, JSON_THROW_ON_ERROR), 'ops');
        return $this->result($d['code']);
    }

    private function airportArea(User $u, array $input): object
    {
        Access::role($u, 'data_admin');
        $d = $this->validate($input, ['id' => 'nullable|integer|min:1', 'airport_code' => 'required|size:3', 'parent_id' => 'nullable|integer|min:1', 'code' => 'required|string|max:40', 'name' => 'required|string|max:160', 'type' => 'required|in:TERMINAL,ZONE,GATE,STAND,COUNTER,BELT', 'active' => 'sometimes|boolean', 'metadata' => 'nullable|array']);
        $db = DB::connection('ops');
        abort_unless($db->table('airports')->where('code', $d['airport_code'])->exists(), 422, 'Sân bay không tồn tại.');
        if (! empty($d['parent_id'])) abort_unless($db->table('airport_areas')->where('id', $d['parent_id'])->where('airport_code', $d['airport_code'])->exists(), 422, 'Khu vực cha không cùng sân bay.');
        $id = $d['id'] ?? null; $created = ! $id; unset($d['id']); $d['active'] = $d['active'] ?? true;
        if ($id) { $old = $db->table('airport_areas')->where('id', $id)->lockForUpdate()->first() ?? abort(404); $db->table('airport_areas')->where('id', $id)->update($d); }
        else { abort_if($db->table('airport_areas')->where('airport_code', $d['airport_code'])->where('code', $d['code'])->exists(), 409, 'Mã khu vực đã tồn tại.'); $id = $db->table('airport_areas')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]); $old = null; }
        $this->audit($created ? 'airport_area.created' : 'airport_area.updated', $id, $u, $old ? json_encode($old, JSON_THROW_ON_ERROR) : null, json_encode($d, JSON_THROW_ON_ERROR), 'ops');
        return $this->result($id);
    }

    private function seasonRule(User $u, array $input): object
    {
        $db = $this->ops($u);
        $d = $this->validate($input, ['id' => 'nullable|integer|min:1', 'code' => 'required|string|max:60', 'route_id' => 'required|integer', 'aircraft_id' => 'nullable|integer', 'from_date' => 'required|date', 'to_date' => 'required|date|after_or_equal:from_date', 'weekdays' => 'required|array|min:1|max:7', 'weekdays.*' => 'integer|between:1,7', 'local_departure' => 'required|date_format:H:i', 'duration_minutes' => 'required|integer|between:15,1440', 'sale_price' => 'required|integer|min:0', 'status' => 'sometimes|in:DRAFT,APPROVED,PUBLISHED,ARCHIVED']);
        abort_unless($db->table('routes')->where('id', $d['route_id'])->exists(), 422, 'Đường bay không tồn tại.');
        if (! empty($d['aircraft_id'])) abort_unless($db->table('aircraft')->where('id', $d['aircraft_id'])->exists(), 422, 'Tàu bay không tồn tại.');
        $id = $d['id'] ?? null; $created = ! $id; unset($d['id']); $d['weekdays'] = json_encode(array_values(array_unique($d['weekdays'])), JSON_THROW_ON_ERROR); $d['status'] = $d['status'] ?? 'DRAFT';
        if ($id) { $old = $db->table('flight_seasons')->where('id', $id)->lockForUpdate()->first() ?? abort(404); $db->table('flight_seasons')->where('id', $id)->update($d + ['version' => $old->version + 1, 'updated_at' => now()]); }
        else { abort_if($db->table('flight_seasons')->where('code', $d['code'])->exists(), 409, 'Mã mùa bay đã tồn tại.'); $id = $db->table('flight_seasons')->insertGetId($d + ['created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $old = null; }
        $this->audit($created ? 'season.created' : 'season.updated', $id, $u, $old ? json_encode($old, JSON_THROW_ON_ERROR) : null, json_encode($d, JSON_THROW_ON_ERROR), 'ops');
        return $this->result($id, $d['status']);
    }

    private function aircraftModel(User $u, array $input): object
    {
        Access::role($u, 'fleet_manager', 'data_admin');
        $d = $this->validate($input, ['id' => 'nullable|integer|min:1', 'code' => 'required|string|max:40', 'manufacturer' => 'required|string|max:100', 'name' => 'required|string|max:120', 'default_capacity' => 'nullable|integer|between:1,600', 'cabin_layout' => 'nullable|array', 'active' => 'sometimes|boolean']);
        $db = DB::connection('ops'); $id = $d['id'] ?? null; $created = ! $id; unset($d['id']); $d['active'] = $d['active'] ?? true; if (isset($d['cabin_layout'])) $d['cabin_layout'] = json_encode($d['cabin_layout'], JSON_THROW_ON_ERROR);
        if ($id) { $old = $db->table('aircraft_models')->where('id', $id)->lockForUpdate()->first() ?? abort(404); $db->table('aircraft_models')->where('id', $id)->update($d + ['updated_at' => now()]); }
        else { abort_if($db->table('aircraft_models')->where('code', $d['code'])->exists(), 409, 'Mã dòng tàu bay đã tồn tại.'); $id = $db->table('aircraft_models')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]); $old = null; }
        $this->audit($created ? 'aircraft_model.created' : 'aircraft_model.updated', $id, $u, $old ? json_encode($old, JSON_THROW_ON_ERROR) : null, json_encode($d, JSON_THROW_ON_ERROR), 'ops'); return $this->result($id);
    }

    private function crewQualification(User $u, array $input): object
    {
        Access::role($u, 'crew_scheduler'); $d = $this->validate($input, ['crew_member_id' => 'required|integer', 'code' => 'required|string|max:80', 'valid_from' => 'nullable|date', 'valid_to' => 'nullable|date|after_or_equal:valid_from', 'document_ref' => 'nullable|string|max:120', 'active' => 'sometimes|boolean']); $db = DB::connection('ops'); abort_unless($db->table('crew_members')->where('id', $d['crew_member_id'])->exists(), 422); $d['active'] = $d['active'] ?? true; abort_if($db->table('crew_qualifications')->where('crew_member_id', $d['crew_member_id'])->where('code', $d['code'])->exists(), 409, 'Năng định đã tồn tại.'); $id = $db->table('crew_qualifications')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]); $this->audit('crew.qualification.created', $id, $u, null, $d['code'], 'ops'); return $this->result($id);
    }

    private function crewAvailability(User $u, array $input): object
    {
        Access::role($u, 'crew_scheduler'); $d = $this->validate($input, ['crew_member_id' => 'required|integer', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'status' => 'required|in:AVAILABLE,LEAVE,RESERVE,UNAVAILABLE', 'reason' => 'nullable|string|max:255']); $db = DB::connection('ops'); abort_unless($db->table('crew_members')->where('id', $d['crew_member_id'])->exists(), 422); abort_if($db->table('crew_availability')->where('crew_member_id', $d['crew_member_id'])->where('starts_at', '<', $d['ends_at'])->where('ends_at', '>', $d['starts_at'])->where('status', '!=', 'AVAILABLE')->exists(), 409, 'Lịch khả dụng bị trùng.'); $id = $db->table('crew_availability')->insertGetId($d + ['created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('crew.availability.created', $id, $u, null, $d['status'], 'ops'); return $this->result($id);
    }

    private function crewRoster(User $u, array $input): object
    {
        Access::role($u, 'crew_scheduler'); $d = $this->validate($input, ['crew_member_id' => 'required|integer', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'type' => 'required|in:DUTY,REST,LEAVE,RESERVE', 'status' => 'sometimes|in:PLANNED,CONFIRMED,CANCELLED', 'reason' => 'nullable|string|max:255']); $db = DB::connection('ops'); abort_unless($db->table('crew_members')->where('id', $d['crew_member_id'])->exists(), 422); $d['status'] = $d['status'] ?? 'PLANNED'; abort_if($db->table('crew_rosters')->where('crew_member_id', $d['crew_member_id'])->where('starts_at', '<', $d['ends_at'])->where('ends_at', '>', $d['starts_at'])->where('status', '!=', 'CANCELLED')->exists(), 409, 'Lịch trực/nghỉ bị trùng.'); $id = $db->table('crew_rosters')->insertGetId($d + ['created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('crew.roster.created', $id, $u, null, $d['type'], 'ops'); return $this->result($id, $d['status']);
    }

    private function maintenanceItem(User $u, array $input): object
    {
        Access::role($u, 'maintenance'); $d = $this->validate($input, ['aircraft_id' => 'required|integer', 'code' => 'required|string|max:60', 'title' => 'required|string|max:180', 'interval_hours' => 'nullable|integer|min:1', 'interval_cycles' => 'nullable|integer|min:1', 'interval_days' => 'nullable|integer|min:1', 'next_due_at' => 'nullable|date', 'active' => 'sometimes|boolean']); $db = DB::connection('ops'); abort_unless($db->table('aircraft')->where('id', $d['aircraft_id'])->exists(), 422); $d['active'] = $d['active'] ?? true; abort_if($db->table('maintenance_items')->where('aircraft_id', $d['aircraft_id'])->where('code', $d['code'])->exists(), 409, 'Hạng mục đã tồn tại.'); $id = $db->table('maintenance_items')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]); $this->audit('maintenance.item.created', $id, $u, null, $d['code'], 'ops'); return $this->result($id);
    }

    private function workItem(User $u, array $input): object
    {
        Access::role($u, 'maintenance'); $d = $this->validate($input, ['work_order_id' => 'required|integer', 'maintenance_item_id' => 'nullable|integer', 'description' => 'required|string|max:255', 'status' => 'sometimes|in:OPEN,IN_PROGRESS,COMPLETED,FAILED', 'quantity' => 'sometimes|integer|min:1', 'evidence' => 'nullable|string|max:4000']); $db = DB::connection('ops'); abort_unless($db->table('work_orders')->where('id', $d['work_order_id'])->exists(), 422); if (! empty($d['maintenance_item_id'])) abort_unless($db->table('maintenance_items')->where('id', $d['maintenance_item_id'])->exists(), 422); $d['status'] = $d['status'] ?? 'OPEN'; $d['quantity'] = $d['quantity'] ?? 1; $id = $db->table('work_order_items')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]); $this->audit('maintenance.item.assigned', $id, $u, null, $d['status'], 'ops'); return $this->result($id, $d['status']);
    }

    private function maintenanceMaterial(User $u, array $input): object
    {
        Access::role($u, 'maintenance'); $d = $this->validate($input, ['code' => 'required|string|max:60', 'name' => 'required|string|max:180', 'unit' => 'sometimes|string|max:20', 'stock' => 'sometimes|integer|min:0', 'reorder_level' => 'sometimes|integer|min:0', 'active' => 'sometimes|boolean']); $db = DB::connection('ops'); abort_if($db->table('maintenance_materials')->where('code', $d['code'])->exists(), 409, 'Mã vật tư đã tồn tại.'); $d['unit'] = $d['unit'] ?? 'EA'; $d['stock'] = $d['stock'] ?? 0; $d['reorder_level'] = $d['reorder_level'] ?? 0; $d['active'] = $d['active'] ?? true; $id = $db->table('maintenance_materials')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]); $this->audit('maintenance.material.created', $id, $u, null, $d['code'], 'ops'); return $this->result($id);
    }

    private function workMaterial(User $u, array $input): object
    {
        Access::role($u, 'maintenance'); $d = $this->validate($input, ['work_order_id' => 'required|integer', 'material_id' => 'required|integer', 'quantity' => 'required|integer|min:1', 'unit_cost' => 'sometimes|integer|min:0']); $db = DB::connection('ops'); $m = $db->table('maintenance_materials')->where('id', $d['material_id'])->lockForUpdate()->first() ?? abort(404); abort_unless($db->table('work_orders')->where('id', $d['work_order_id'])->exists(), 422); abort_if($m->stock < $d['quantity'], 409, 'Không đủ vật tư.'); abort_if($db->table('work_order_materials')->where('work_order_id', $d['work_order_id'])->where('material_id', $d['material_id'])->exists(), 409, 'Vật tư đã được ghi nhận cho lệnh.'); $db->table('maintenance_materials')->where('id', $m->id)->update(['stock' => $m->stock - $d['quantity'], 'updated_at' => now()]); $id = $db->table('work_order_materials')->insertGetId($d + ['unit_cost' => $d['unit_cost'] ?? 0, 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('maintenance.material.issued', $id, $u, (string) $m->stock, (string) ($m->stock - $d['quantity']), 'ops'); return $this->result($id);
    }

    private function groundService(User $u, array $input): object
    {
        Access::role($u, 'data_admin', 'operations'); $d = $this->validate($input, ['airport_code' => 'required|size:3', 'code' => 'required|string|max:50', 'name' => 'required|string|max:160', 'default_duration_minutes' => 'sometimes|integer|between:1,1440', 'mandatory' => 'sometimes|boolean', 'active' => 'sometimes|boolean']); $db = DB::connection('ops'); Access::airport($u, $d['airport_code']); abort_unless($db->table('airports')->where('code', $d['airport_code'])->exists(), 422); abort_if($db->table('ground_services')->where('airport_code', $d['airport_code'])->where('code', $d['code'])->exists(), 409, 'Dịch vụ đã tồn tại.'); $d += ['default_duration_minutes' => 30, 'mandatory' => false, 'active' => true]; $id = $db->table('ground_services')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]); $this->audit('ground.service.created', $id, $u, null, $d['code'], 'ops'); return $this->result($id);
    }

    private function groundTaskService(User $u, array $input): object
    {
        Access::role($u, 'ground_agent', 'operations'); $d = $this->validate($input, ['ground_task_id' => 'required|integer', 'service_id' => 'required|integer', 'sequence' => 'sometimes|integer|min:1']); $db = DB::connection('ops'); $task = $db->table('ground_tasks')->find($d['ground_task_id']) ?? abort(404); $service = $db->table('ground_services')->find($d['service_id']) ?? abort(404); Access::airport($u, $db->table('flights')->where('id', $task->flight_id)->value('origin')); abort_unless($db->table('flights')->where('id', $task->flight_id)->value('origin') === $service->airport_code, 422); $d['sequence'] = $d['sequence'] ?? 1; $id = $db->table('ground_task_services')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]); $this->audit('ground.task.service.added', $id, $u, null, (string) $service->code, 'ops'); return $this->result($id);
    }

    private function groundEquipment(User $u, array $input): object
    {
        Access::role($u, 'data_admin', 'operations', 'ground_agent'); $d = $this->validate($input, ['airport_code' => 'required|size:3', 'code' => 'required|string|max:60', 'name' => 'required|string|max:160', 'type' => 'required|string|max:40', 'status' => 'sometimes|in:AVAILABLE,IN_USE,MAINTENANCE,UNAVAILABLE', 'active' => 'sometimes|boolean']); $db = DB::connection('ops'); Access::airport($u, $d['airport_code']); abort_if($db->table('ground_equipment')->where('airport_code', $d['airport_code'])->where('code', $d['code'])->exists(), 409); $d += ['status' => 'AVAILABLE', 'active' => true]; $id = $db->table('ground_equipment')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]); $this->audit('ground.equipment.created', $id, $u, null, $d['code'], 'ops'); return $this->result($id, $d['status']);
    }

    private function groundTaskEquipment(User $u, array $input): object
    {
        Access::role($u, 'ground_agent', 'operations'); $d = $this->validate($input, ['ground_task_id' => 'required|integer', 'equipment_id' => 'required|integer', 'quantity' => 'sometimes|integer|min:1']); $db = DB::connection('ops'); $task = $db->table('ground_tasks')->find($d['ground_task_id']) ?? abort(404); $equipment = $db->table('ground_equipment')->find($d['equipment_id']) ?? abort(404); Access::airport($u, $db->table('flights')->where('id', $task->flight_id)->value('origin')); abort_unless($equipment->airport_code === $db->table('flights')->where('id', $task->flight_id)->value('origin'), 422); abort_unless($equipment->active && $equipment->status === 'AVAILABLE', 409, 'Thiết bị không khả dụng.'); $id = $db->table('ground_task_equipment')->insertGetId($d + ['quantity' => $d['quantity'] ?? 1, 'created_at' => now(), 'updated_at' => now()]); $db->table('ground_equipment')->where('id', $equipment->id)->update(['status' => 'IN_USE', 'updated_at' => now()]); $this->audit('ground.equipment.assigned', $id, $u, 'AVAILABLE', 'IN_USE', 'ops'); return $this->result($id);
    }

    private function groundHandoff(User $u, array $input): object
    {
        Access::role($u, 'ground_agent', 'operations'); $d = $this->validate($input, ['ground_task_id' => 'required|integer', 'to_user' => 'required|integer', 'note' => 'nullable|string|max:2000']); $db = DB::connection('ops'); $task = $db->table('ground_tasks')->find($d['ground_task_id']) ?? abort(404); $flight = $db->table('flights')->find($task->flight_id) ?? abort(404); Access::airport($u, $flight->origin); $id = $db->table('ground_task_handoffs')->insertGetId($d + ['from_user' => $u->id, 'handed_at' => now(), 'created_at' => now(), 'updated_at' => now()]); $db->table('ground_tasks')->where('id', $task->id)->update(['assigned_to' => $d['to_user'], 'updated_at' => now()]); $this->audit('ground.task.handed_off', $id, $u, (string) $task->assigned_to, (string) $d['to_user'], 'ops'); return $this->result($id);
    }

    private function integrationConnection(User $u, array $input): object
    {
        Access::role($u, 'admin', 'integration_admin'); $d = $this->validate($input, ['id' => 'nullable|integer', 'code' => 'required|string|max:80', 'name' => 'required|string|max:160', 'protocol' => 'required|string|max:30', 'endpoint' => 'nullable|url|max:255', 'configuration' => 'nullable|array', 'status' => 'sometimes|in:DISABLED,TESTING,ACTIVE,ERROR']); $db = DB::connection('ops'); $id = $d['id'] ?? null; $created = ! $id; unset($d['id']); if (isset($d['configuration'])) $d['configuration'] = json_encode($d['configuration'], JSON_THROW_ON_ERROR); $d['status'] = $d['status'] ?? 'DISABLED'; if ($id) { $old = $db->table('integration_connections')->where('id', $id)->lockForUpdate()->first() ?? abort(404); $db->table('integration_connections')->where('id', $id)->update($d + ['updated_by' => $u->id, 'updated_at' => now()]); } else { abort_if($db->table('integration_connections')->where('code', $d['code'])->exists(), 409); $id = $db->table('integration_connections')->insertGetId($d + ['updated_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $old = null; } $this->audit($created ? 'integration.created' : 'integration.updated', $id, $u, $old ? json_encode($old, JSON_THROW_ON_ERROR) : null, json_encode($d, JSON_THROW_ON_ERROR), 'ops'); return $this->result($id, $d['status']);
    }

    private function fareRuleVersion(User $u, array $input): object
    {
        Access::role($u, 'data_admin'); $d = $this->validate($input, ['fare_code' => 'required|string|max:20', 'name' => 'required|string|max:120', 'rules' => 'required|array', 'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after:effective_from', 'version' => 'sometimes|integer|min:1', 'active' => 'sometimes|boolean']); $db = DB::table('fare_rule_versions'); $d['rules'] = json_encode($d['rules'], JSON_THROW_ON_ERROR); $d['version'] = $d['version'] ?? ((int) $db->where('fare_code', $d['fare_code'])->max('version') + 1); $d['active'] = $d['active'] ?? true; abort_if($db->where('fare_code', $d['fare_code'])->where('version', $d['version'])->exists(), 409, 'Phiên bản giá đã tồn tại.'); $id = $db->insertGetId($d + ['created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('fare.rule.created', $id, $u, null, (string) $d['version']); return $this->result($id);
    }

    private function baggageAllowance(User $u, array $input): object
    {
        Access::role($u, 'data_admin'); $d = $this->validate($input, ['fare_code' => 'required|string|max:20', 'pieces' => 'sometimes|integer|min:0|max:20', 'included_kg' => 'sometimes|numeric|min:0|max:999', 'excess_kg_price' => 'sometimes|integer|min:0', 'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after:effective_from', 'active' => 'sometimes|boolean']); $d['pieces'] = $d['pieces'] ?? 0; $d['included_kg'] = $d['included_kg'] ?? 0; $d['excess_kg_price'] = $d['excess_kg_price'] ?? 0; $d['active'] = $d['active'] ?? true; $id = DB::table('baggage_allowances')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]); $this->audit('baggage.allowance.created', $id, $u, null, $d['fare_code']); return $this->result($id);
    }

    private function ancillary(User $u, array $input): object
    {
        Access::role($u, 'passenger', 'customer_service', 'airport_agent'); $d = $this->validate($input, ['reservation_id' => 'required|uuid', 'booking_id' => 'nullable|uuid', 'type' => 'required|string|max:40', 'quantity' => 'required|integer|between:1,20', 'amount' => 'required|integer|min:0', 'currency' => 'sometimes|size:3', 'idempotency_key' => 'required|string|max:80', 'metadata' => 'nullable|array']); $reservation = DB::table('reservations')->where('id', $d['reservation_id'])->first() ?? abort(404); abort_unless($u->role !== 'passenger' || (int) $reservation->user_id === (int) $u->id, 404); $d['currency'] = $d['currency'] ?? 'VND'; $d['metadata'] = isset($d['metadata']) ? json_encode($d['metadata'], JSON_THROW_ON_ERROR) : null; $existing = DB::table('reservation_ancillaries')->where('idempotency_key', $d['idempotency_key'])->first(); if ($existing) return (object) (array) $existing; $id = (string) Str::uuid(); DB::table('reservation_ancillaries')->insert($d + ['id' => $id, 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now()]); $this->audit('reservation.ancillary.created', $id, $u, null, $d['type']); return $this->result($id, 'PENDING');
    }

    private function baggageIncident(User $u, array $input): object
    {
        Access::role($u, 'airport_agent', 'customer_service'); $d = $this->validate($input, ['baggage_id' => 'required|integer', 'type' => 'required|in:LOST,DAMAGED,DELAYED,OPENED', 'description' => 'required|string|min:5|max:4000']); $db = DB::table('baggage_incidents'); abort_unless(DB::table('baggage')->where('id', $d['baggage_id'])->exists(), 404); $id = $db->insertGetId($d + ['status' => 'OPEN', 'opened_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('baggage.incident.opened', $id, $u, null, $d['type']); return $this->result($id, 'OPEN');
    }


    private function baggageIncidentResolve(User $u, array $input): object
    {
        Access::role($u, 'airport_agent', 'customer_service');
        $d = $this->validate($input, ['id' => 'required|integer', 'resolution' => 'required|string|min:5|max:4000']);
        $db = DB::table('baggage_incidents');
        $incident = $db->where('id', $d['id'])->lockForUpdate()->first() ?? abort(404);
        abort_unless($incident->status === 'OPEN', 409, 'S? c? h?nh l? ?? ???c x? l?.');
        $db->where('id', $incident->id)->update(['status' => 'RESOLVED', 'description' => $incident->description."\nResolution: ".$d['resolution'], 'resolved_by' => $u->id, 'resolved_at' => now(), 'updated_at' => now()]);
        $this->audit('baggage.incident.resolved', $incident->id, $u, 'OPEN', 'RESOLVED');
        return $this->result($incident->id, 'RESOLVED');
    }

    private function notificationTemplate(User $u, array $input): object
    {
        Access::role($u, 'admin', 'integration_admin', 'customer_service'); $d = $this->validate($input, ['code' => 'required|string|max:100', 'channel' => 'required|in:IN_APP,EMAIL,SMS', 'locale' => 'sometimes|string|max:10', 'subject' => 'nullable|string|max:255', 'body' => 'required|string|max:10000', 'version' => 'sometimes|integer|min:1', 'active' => 'sometimes|boolean']); $d['locale'] = $d['locale'] ?? 'vi'; $d['version'] = $d['version'] ?? ((int) DB::table('notification_templates')->where('code', $d['code'])->where('channel', $d['channel'])->max('version') + 1); $d['active'] = $d['active'] ?? true; $id = DB::table('notification_templates')->insertGetId($d + ['created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('notification.template.created', $id, $u, null, $d['code']); return $this->result($id);
    }

    private function notificationSend(User $u, array $input): object
    {
        Access::role($u, 'admin', 'integration_admin', 'customer_service', 'operations'); $d = $this->validate($input, ['user_id' => 'required|integer', 'channel' => 'required|in:IN_APP,EMAIL,SMS', 'title' => 'required|string|max:255', 'body' => 'required|string|max:10000', 'template_id' => 'nullable|integer']); abort_unless(User::where('id', $d['user_id'])->exists(), 404); $id = (string) Str::uuid(); DB::table('notifications')->insert(['id' => $id, 'user_id' => $d['user_id'], 'title' => $d['title'], 'body' => $d['body'], 'created_at' => now(), 'updated_at' => now()]); $delivery = DB::table('notification_deliveries')->insertGetId(['template_id' => $d['template_id'] ?? null, 'user_id' => $d['user_id'], 'channel' => $d['channel'], 'status' => $d['channel'] === 'IN_APP' ? 'SENT' : 'PENDING', 'sent_at' => $d['channel'] === 'IN_APP' ? now() : null, 'created_at' => now(), 'updated_at' => now()]); $this->audit('notification.sent', $delivery, $u, null, $d['channel']); return $this->result($delivery, $d['channel'] === 'IN_APP' ? 'SENT' : 'PENDING', ['notification_id' => $id]);
    }

    private function customerInteraction(User $u, array $input): object
    {
        Access::role($u, 'passenger', 'customer_service', 'admin'); $d = $this->validate($input, ['user_id' => 'required|integer', 'type' => 'required|string|max:40', 'reference_type' => 'nullable|string|max:60', 'reference_id' => 'nullable|string|max:80', 'summary' => 'required|string|max:4000', 'metadata' => 'nullable|array']); abort_unless(User::where('id', $d['user_id'])->exists(), 404); abort_unless($u->role !== 'passenger' || (int) $d['user_id'] === (int) $u->id, 404); if (isset($d['metadata'])) $d['metadata'] = json_encode($d['metadata'], JSON_THROW_ON_ERROR); $id = DB::table('customer_interactions')->insertGetId($d + ['created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('customer.interaction.created', $id, $u, null, $d['type']); return $this->result($id);
    }

    private function systemConfig(User $u, array $input): object
    {
        Access::role($u, 'admin', 'integration_admin', 'data_admin'); $d = $this->validate($input, ['scope' => 'sometimes|string|max:60', 'key' => 'required|string|max:120', 'value' => 'required|array', 'effective_from' => 'nullable|date', 'effective_until' => 'nullable|date|after:effective_from']); $d['scope'] = $d['scope'] ?? 'global'; $d['value'] = json_encode($d['value'], JSON_THROW_ON_ERROR); $version = (int) DB::table('system_configurations')->where('scope', $d['scope'])->where('key', $d['key'])->max('version') + 1; $id = DB::table('system_configurations')->insertGetId($d + ['version' => $version, 'updated_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('system.config.updated', $id, $u, null, $d['key']); return $this->result($id);
    }

    private function documentVerify(User $u, array $input): object
    {
        Access::role($u, 'airport_agent', 'customer_service'); $d = $this->validate($input, ['reservation_passenger_id' => 'required|uuid', 'document_type' => 'required|string|max:30', 'document_number' => 'required|string|max:120', 'status' => 'required|in:PENDING,VERIFIED,REJECTED', 'result' => 'nullable|string|max:2000']); $hash = hash('sha256', $d['document_number']); unset($d['document_number']); $id = (string) Str::uuid(); DB::table('passenger_document_verifications')->insert($d + ['id' => $id, 'document_hash' => $hash, 'verified_by' => $u->id, 'verified_at' => $d['status'] === 'PENDING' ? null : now(), 'created_at' => now(), 'updated_at' => now()]); $this->audit('passenger.document.'.$d['status'], $id, $u, null, $d['document_type']); return $this->result($id, $d['status']);
    }

    private function assistance(User $u, array $input): object
    {
        Access::role($u, 'passenger', 'customer_service', 'airport_agent'); $d = $this->validate($input, ['reservation_id' => 'required|uuid', 'passenger_id' => 'required|uuid', 'type' => 'required|string|max:40', 'notes' => 'nullable|string|max:4000', 'assigned_to' => 'nullable|integer']); $reservation = DB::table('reservations')->where('id', $d['reservation_id'])->first() ?? abort(404); abort_unless($u->role !== 'passenger' || (int) $reservation->user_id === (int) $u->id, 404); $id = (string) Str::uuid(); DB::table('passenger_assistance_requests')->insert($d + ['id' => $id, 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]); $this->audit('passenger.assistance.created', $id, $u, null, $d['type']); return $this->result($id, 'OPEN');
    }

    private function actualTime(User $u, array $input): object
    {
        Access::role($u, 'operations', 'ops_manager'); $d = $this->validate($input, ['flight_id' => 'required|integer', 'type' => 'required|in:DEPARTURE,ARRIVAL', 'event_time' => 'required|date', 'source' => 'sometimes|in:MANUAL,IOT,INTEGRATION', 'reason' => 'nullable|string|max:255']); $db = DB::connection('ops'); $f = $db->table('flights')->where('id', $d['flight_id'])->lockForUpdate()->first() ?? abort(404); Access::airport($u, $f->origin); $time = date('Y-m-d H:i:s', strtotime($d['event_time'])); if ($d['type'] === 'ARRIVAL') abort_unless($f->actual_departure || $f->status === 'DEPARTED', 409, 'Cần ghi nhận giờ khởi hành trước.'); $column = $d['type'] === 'DEPARTURE' ? 'actual_departure' : 'actual_arrival'; $db->table('flights')->where('id', $f->id)->update([$column => $time, 'version' => $f->version + 1]); $id = $db->table('flight_actual_events')->insertGetId(['flight_id' => $f->id, 'type' => $d['type'], 'event_time' => $time, 'source' => $d['source'] ?? 'MANUAL', 'reason' => $d['reason'] ?? null, 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('flight.actual_time.updated', $id, $u, (string) ($f->{$column} ?? ''), $time, 'ops'); return $this->result($id, 'RECORDED', ['flight_id' => $f->id, 'field' => $column, 'event_time' => $time]);
    }

    private function feeCatalog(User $u, array $input): object
    {
        Access::role($u, 'finance'); $d = $this->validate($input, ['code' => 'required|string|max:60', 'name' => 'required|string|max:160', 'category' => 'required|string|max:40', 'amount' => 'required|integer|min:0', 'currency' => 'sometimes|size:3', 'conditions' => 'nullable|array', 'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after:effective_from', 'active' => 'sometimes|boolean']); $d['currency'] = $d['currency'] ?? 'VND'; $d['active'] = $d['active'] ?? true; if (isset($d['conditions'])) $d['conditions'] = json_encode($d['conditions'], JSON_THROW_ON_ERROR); abort_if(DB::connection('finance')->table('fee_catalog')->where('code', $d['code'])->exists(), 409); $id = DB::connection('finance')->table('fee_catalog')->insertGetId($d + ['created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('fee.created', $id, $u, null, $d['code'], 'finance'); return $this->result($id);
    }

    private function reconciliationReason(User $u, array $input): object
    {
        Access::role($u, 'finance'); $d = $this->validate($input, ['code' => 'required|string|max:60', 'label' => 'required|string|max:160', 'active' => 'sometimes|boolean']); $d['active'] = $d['active'] ?? true; $db = DB::connection('finance'); abort_if($db->table('reconciliation_reason_codes')->where('code', $d['code'])->exists(), 409); $id = $db->table('reconciliation_reason_codes')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]); $this->audit('reconciliation.reason.created', $id, $u, null, $d['code'], 'finance'); return $this->result($id);
    }

    private function reconciliationAdjustment(User $u, array $input): object
    {
        Access::role($u, 'finance'); $d = $this->validate($input, ['run_id' => 'required|integer', 'entry_id' => 'required|integer', 'reason_code_id' => 'nullable|integer', 'amount' => 'required|integer', 'explanation' => 'required|string|min:5|max:4000']); $db = DB::connection('finance'); $run = $db->table('reconciliation_runs')->find($d['run_id']) ?? abort(404); abort_unless($run->status === 'OPEN', 409, 'Kỳ đối soát đã khóa.'); $entry = $db->table('reconciliation_entries')->where('id', $d['entry_id'])->where('run_id', $d['run_id'])->first() ?? abort(404); abort_unless($entry->result !== 'MATCHED', 409, 'Bản ghi đã khớp.'); if (! empty($d['reason_code_id'])) abort_unless($db->table('reconciliation_reason_codes')->where('id', $d['reason_code_id'])->exists(), 422); $id = $db->table('reconciliation_adjustments')->insertGetId($d + ['status' => 'PENDING', 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('reconciliation.adjustment.requested', $id, $u, null, 'PENDING', 'finance'); return $this->result($id, 'PENDING');
    }

    private function reconciliationAdjustmentDecide(User $u, array $input): object
    {
        Access::role($u, 'finance'); $d = $this->validate($input, ['id' => 'required|integer', 'approve' => 'required|boolean', 'note' => 'nullable|string|max:2000']); $db = DB::connection('finance'); $row = $db->table('reconciliation_adjustments')->where('id', $d['id'])->lockForUpdate()->first() ?? abort(404); abort_unless($row->status === 'PENDING' && (int) $row->created_by !== (int) $u->id, 409, 'Phiếu đã xử lý hoặc cần người duyệt độc lập.'); $status = $d['approve'] ? 'APPROVED' : 'REJECTED'; $db->table('reconciliation_adjustments')->where('id', $row->id)->update(['status' => $status, 'approved_by' => $u->id, 'updated_at' => now()]); if ($d['approve']) $db->table('reconciliation_entries')->where('id', $row->entry_id)->update(['result' => 'RESOLVED', 'resolution' => $d['note'] ?? $row->explanation, 'resolved_by' => $u->id]); $this->audit('reconciliation.adjustment.'.$status, $row->id, $u, 'PENDING', $status, 'finance'); return $this->result($row->id, $status);
    }

    private function reconciliationImport(User $u, array $input): object
    {
        Access::role($u, 'finance'); $d = $this->validate($input, ['source' => 'required|string|max:60', 'filename' => 'required|string|max:255', 'file_hash' => 'required|size:64', 'row_count' => 'required|integer|min:0']); $db = DB::connection('finance'); $existing = $db->table('reconciliation_imports')->where('source', $d['source'])->where('file_hash', $d['file_hash'])->first(); if ($existing) return (object) (array) $existing; $id = $db->table('reconciliation_imports')->insertGetId($d + ['status' => 'IMPORTED', 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('reconciliation.imported', $id, $u, null, $d['file_hash'], 'finance'); return $this->result($id, 'IMPORTED');
    }

    private function dataset(User $u, array $input): object
    {
        Access::role($u, 'analyst', 'data_admin', 'admin'); $d = $this->validate($input, ['code' => 'required|string|max:80', 'name' => 'required|string|max:160', 'source' => 'required|string|max:80', 'schema_definition' => 'nullable|array', 'status' => 'sometimes|in:DRAFT,READY,ARCHIVED']); $d['status'] = $d['status'] ?? 'DRAFT'; if (isset($d['schema_definition'])) $d['schema_definition'] = json_encode($d['schema_definition'], JSON_THROW_ON_ERROR); $db = DB::table('analytics_datasets'); abort_if($db->where('code', $d['code'])->exists(), 409); $id = $db->insertGetId($d + ['created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('analytics.dataset.created', $id, $u, null, $d['code']); return $this->result($id, $d['status']);
    }

    private function modelRun(User $u, array $input): object
    {
        Access::role($u, 'analyst', 'data_admin', 'admin'); $d = $this->validate($input, ['dataset_id' => 'required|integer', 'model_type' => 'required|string|max:60', 'train_ratio' => 'sometimes|numeric|between:0.5,0.95', 'metrics' => 'nullable|array', 'artifact_ref' => 'nullable|string|max:255', 'status' => 'sometimes|in:QUEUED,RUNNING,COMPLETED,FAILED']); abort_unless(DB::table('analytics_datasets')->where('id', $d['dataset_id'])->exists(), 422); $d['train_ratio'] = $d['train_ratio'] ?? 0.8; $d['status'] = $d['status'] ?? 'QUEUED'; if (isset($d['metrics'])) $d['metrics'] = json_encode($d['metrics'], JSON_THROW_ON_ERROR); $id = DB::table('analytics_model_runs')->insertGetId($d + ['created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('analytics.model_run.created', $id, $u, null, $d['status']); return $this->result($id, $d['status']);
    }

    private function recommendation(User $u, array $input): object
    {
        Access::role($u, 'analyst', 'data_admin', 'operations', 'maintenance'); $d = $this->validate($input, ['model_run_id' => 'required|integer', 'target_type' => 'required|string|max:60', 'target_id' => 'required|string|max:80', 'recommendation' => 'required|string|max:4000', 'confidence' => 'nullable|numeric|between:0,1', 'explanation' => 'nullable|array', 'status' => 'sometimes|in:PROPOSED,ACCEPTED,REJECTED']); abort_unless(DB::table('analytics_model_runs')->where('id', $d['model_run_id'])->exists(), 422); $d['status'] = $d['status'] ?? 'PROPOSED'; if (isset($d['explanation'])) $d['explanation'] = json_encode($d['explanation'], JSON_THROW_ON_ERROR); $id = DB::table('analytics_recommendations')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]); $this->audit('analytics.recommendation.created', $id, $u, null, $d['status']); return $this->result($id, $d['status']);
    }

    private function supportCompensation(User $u, array $input): object
    {
        Access::role($u, 'customer_service', 'finance'); $d = $this->validate($input, ['case_id' => 'required|integer', 'amount' => 'required|integer|min:0', 'currency' => 'sometimes|size:3', 'reason' => 'required|string|min:5|max:255']); $db = DB::table('support_compensations'); abort_unless(DB::table('support_cases')->where('id', $d['case_id'])->exists(), 404); $id = $db->insertGetId($d + ['currency' => $d['currency'] ?? 'VND', 'status' => 'PENDING', 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]); $this->audit('support.compensation.requested', $id, $u, null, 'PENDING'); return $this->result($id, 'PENDING');
    }

    private function supportCompensationDecide(User $u, array $input): object
    {
        Access::role($u, 'customer_service', 'finance');
        $d = $this->validate($input, ['id' => 'required|integer', 'approve' => 'required|boolean']);
        $db = DB::table('support_compensations');
        $row = $db->where('id', $d['id'])->lockForUpdate()->first() ?? abort(404);
        abort_unless($row->status === 'PENDING' && (int) $row->created_by !== (int) $u->id, 409, 'Phi?u ?? x? l? ho?c c?n ng??i duy?t ??c l?p.');
        $status = $d['approve'] ? 'APPROVED' : 'REJECTED';
        $db->where('id', $row->id)->update(['status' => $status, 'approved_by' => $u->id, 'approved_at' => now(), 'updated_at' => now()]);
        $this->audit('support.compensation.'.$status, $row->id, $u, 'PENDING', $status);
        return $this->result($row->id, $status);
    }

    private function ancillaryDecide(User $u, array $input): object
    {
        Access::role($u, 'customer_service', 'finance', 'airport_agent');
        $d = $this->validate($input, ['id' => 'required|uuid', 'status' => 'required|in:CONFIRMED,CANCELLED']);
        $row = DB::table('reservation_ancillaries')->where('id', $d['id'])->lockForUpdate()->first() ?? abort(404);
        abort_unless($row->status === 'PENDING', 409, 'Ti?n ?ch ?? ???c x? l?.');
        DB::table('reservation_ancillaries')->where('id', $row->id)->update(['status' => $d['status'], 'updated_at' => now()]);
        $this->audit('reservation.ancillary.'.$d['status'], $row->id, $u, 'PENDING', $d['status']);
        return $this->result($row->id, $d['status']);
    }

    private function notificationDeliveryUpdate(User $u, array $input): object
    {
        Access::role($u, 'admin', 'integration_admin', 'customer_service');
        $d = $this->validate($input, ['id' => 'required|integer', 'status' => 'required|in:SENT,FAILED,CANCELLED', 'provider_ref' => 'nullable|string|max:255', 'error' => 'nullable|string|max:2000']);
        $row = DB::table('notification_deliveries')->where('id', $d['id'])->lockForUpdate()->first() ?? abort(404);
        abort_unless($row->status === 'PENDING', 409, 'L?n g?i ?? k?t th?c.');
        DB::table('notification_deliveries')->where('id', $row->id)->update(['status' => $d['status'], 'provider_ref' => $d['provider_ref'] ?? null, 'error' => $d['error'] ?? null, 'sent_at' => $d['status'] === 'SENT' ? now() : null, 'updated_at' => now()]);
        $this->audit('notification.delivery.'.$d['status'], $row->id, $u, 'PENDING', $d['status']);
        return $this->result($row->id, $d['status']);
    }

}
