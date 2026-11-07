<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Shared\UseCaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UseCaseBackendWorkflowTest extends TestCase
{
    private function user(string $role, string $airport = 'HAN'): User
    {
        return User::factory()->create(['role' => $role, 'airport_code' => $airport]);
    }

    public function test_master_data_crew_maintenance_and_ground_records_are_transactional(): void
    {
        $data = $this->user('data_admin');
        $ops = $this->user('operations');
        $crew = $this->user('crew_scheduler');
        $maintenance = $this->user('maintenance');

        $this->actingAs($data)->postJson('/api/v1/actions/airport_update', [
            'code' => 'HAN', 'name' => 'Noi Bai', 'city' => 'Ha Noi', 'timezone' => 'Asia/Ho_Chi_Minh',
            'icao' => 'VVNB', 'country_code' => 'VN', 'latitude' => 21.221, 'longitude' => 105.807,
            'operating_hours' => ['open' => '00:00', 'close' => '23:59'],
        ])->assertOk();
        $this->assertDatabaseHas('airports', ['code' => 'HAN', 'icao' => 'VVNB'], 'ops');

        $area = $this->actingAs($data)->postJson('/api/v1/actions/airport_area', [
            'airport_code' => 'HAN', 'code' => 'T1-Z1', 'name' => 'Terminal 1 Zone 1', 'type' => 'ZONE',
        ])->assertOk()->json('id');
        $this->assertDatabaseHas('airport_areas', ['id' => $area], 'ops');

        $this->actingAs($crew)->postJson('/api/v1/actions/crew_qualification', [
            'crew_member_id' => 1001, 'code' => 'A320-RECURRENT', 'valid_from' => now()->toDateString(), 'valid_to' => now()->addYear()->toDateString(),
        ])->assertOk();
        $this->actingAs($crew)->postJson('/api/v1/actions/crew_roster', [
            'crew_member_id' => 1001, 'starts_at' => now()->addDays(3)->toIso8601String(), 'ends_at' => now()->addDays(3)->addHours(8)->toIso8601String(), 'type' => 'DUTY',
        ])->assertOk();

        $material = $this->actingAs($maintenance)->postJson('/api/v1/actions/maintenance_material', [
            'code' => 'FILTER-01', 'name' => 'Cabin filter', 'stock' => 5, 'reorder_level' => 1,
        ])->assertOk()->json('id');
        $item = $this->actingAs($maintenance)->postJson('/api/v1/actions/maintenance_item', [
            'aircraft_id' => 100, 'code' => 'CHECK-01', 'title' => 'Scheduled inspection', 'interval_days' => 30,
        ])->assertOk()->json('id');
        $workItem = $this->actingAs($maintenance)->postJson('/api/v1/actions/work_item', [
            'work_order_id' => 100, 'maintenance_item_id' => $item, 'description' => 'Replace filter',
        ])->assertOk()->json('id');
        $this->actingAs($maintenance)->postJson('/api/v1/actions/work_material', [
            'work_order_id' => 100, 'material_id' => $material, 'quantity' => 1,
        ])->assertOk();
        $this->assertDatabaseHas('work_order_items', ['id' => $workItem], 'ops');
        $this->assertDatabaseHas('maintenance_materials', ['id' => $material, 'stock' => 4], 'ops');

        $service = $this->actingAs($ops)->postJson('/api/v1/actions/ground_service', [
            'airport_code' => 'HAN', 'code' => 'CLEAN', 'name' => 'Cabin cleaning',
        ])->assertOk()->json('id');
        $equipment = $this->actingAs($ops)->postJson('/api/v1/actions/ground_equipment', [
            'airport_code' => 'HAN', 'code' => 'BUS-01', 'name' => 'Apron bus', 'type' => 'BUS',
        ])->assertOk()->json('id');
        $task = DB::connection('ops')->table('ground_tasks')->insertGetId([
            'flight_id' => 1, 'title' => 'Turnaround', 'assigned_to' => $ops->id, 'due_at' => now()->addHour(), 'mandatory' => true, 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($ops)->postJson('/api/v1/actions/ground_task_service', ['ground_task_id' => $task, 'service_id' => $service])->assertOk();
        $this->actingAs($ops)->postJson('/api/v1/actions/ground_task_equipment', ['ground_task_id' => $task, 'equipment_id' => $equipment])->assertOk();
        $this->assertDatabaseHas('ground_equipment', ['id' => $equipment, 'status' => 'IN_USE'], 'ops');
    }

    public function test_finance_analytics_and_idempotent_reconciliation_actions(): void
    {
        $finance = $this->user('finance');
        $analyst = $this->user('analyst');

        $this->actingAs($finance)->postJson('/api/v1/actions/fee_catalog', [
            'code' => 'CHANGE-FEE', 'name' => 'Change fee', 'category' => 'BOOKING', 'amount' => 100000, 'effective_from' => now()->toIso8601String(),
        ])->assertOk();
        $reason = $this->actingAs($finance)->postJson('/api/v1/actions/reconciliation_reason', [
            'code' => 'LATE_CALLBACK', 'label' => 'Late provider callback',
        ])->assertOk()->json('id');
        $run = DB::connection('finance')->table('reconciliation_runs')->insertGetId(['period_start' => now()->toDateString(), 'period_end' => now()->toDateString(), 'status' => 'OPEN', 'created_by' => $finance->id, 'created_at' => now(), 'updated_at' => now()]);
        $entry = DB::connection('finance')->table('reconciliation_entries')->insertGetId(['run_id' => $run, 'booking_id' => (string) Str::uuid(), 'result' => 'MISMATCH', 'detail' => 'Amount differs']);
        $adjustment = $this->actingAs($finance)->postJson('/api/v1/actions/reconciliation_adjustment', [
            'run_id' => $run, 'entry_id' => $entry, 'reason_code_id' => $reason, 'amount' => 1000, 'explanation' => 'Provider callback arrived after settlement',
        ])->assertOk()->json('id');
        $this->actingAs($finance)->postJson('/api/v1/actions/reconciliation_adjustment_decide', ['id' => $adjustment, 'approve' => true])->assertConflict();
        $approver = $this->user('finance');
        $this->actingAs($approver)->postJson('/api/v1/actions/reconciliation_adjustment_decide', ['id' => $adjustment, 'approve' => true])->assertOk();
        $this->assertDatabaseHas('reconciliation_entries', ['id' => $entry, 'result' => 'RESOLVED'], 'finance');

        $dataset = $this->actingAs($analyst)->postJson('/api/v1/actions/dataset', ['code' => 'FLIGHT_DELAY_V1', 'name' => 'Flight delay history', 'source' => 'flights', 'schema_definition' => ['target' => 'delay_minutes']])->assertOk()->json('id');
        $runId = $this->actingAs($analyst)->postJson('/api/v1/actions/model_run', ['dataset_id' => $dataset, 'model_type' => 'baseline-regression', 'status' => 'COMPLETED', 'metrics' => ['mae' => 4.2]])->assertOk()->json('id');
        $this->actingAs($analyst)->postJson('/api/v1/actions/recommendation', ['model_run_id' => $runId, 'target_type' => 'flight', 'target_id' => '1', 'recommendation' => 'Review turnaround buffer', 'confidence' => 0.82, 'explanation' => ['top_feature' => 'late_arrival']])->assertOk();
        $this->assertDatabaseHas('analytics_model_runs', ['id' => $runId, 'status' => 'COMPLETED']);
    }

    public function test_configuration_notifications_actual_times_and_assistance_are_audited(): void
    {
        $ops = $this->user('operations');
        $admin = $this->user('admin');
        $support = $this->user('customer_service');
        $passenger = $this->user('passenger');

        $this->actingAs($ops)->postJson('/api/v1/actions/actual_time', [
            'flight_id' => 1, 'type' => 'DEPARTURE', 'event_time' => now()->addMinutes(5)->toIso8601String(), 'reason' => 'Ramp confirmed',
        ])->assertOk();
        $this->assertDatabaseHas('flight_actual_events', ['flight_id' => 1, 'type' => 'DEPARTURE'], 'ops');

        $this->actingAs($admin)->postJson('/api/v1/actions/integration_connection', [
            'code' => 'SMS-SANDBOX', 'name' => 'SMS sandbox', 'protocol' => 'HTTP', 'endpoint' => 'https://example.test/sms', 'configuration' => ['timeout' => 5], 'status' => 'TESTING',
        ])->assertOk();
        $delivery = $this->actingAs($support)->postJson('/api/v1/actions/notification_send', [
            'user_id' => $passenger->id, 'channel' => 'SMS', 'title' => 'Flight update', 'body' => 'Your flight changed.',
        ])->assertOk()->json('id');
        $this->actingAs($admin)->postJson('/api/v1/actions/notification_delivery_update', [
            'id' => $delivery, 'status' => 'SENT', 'provider_ref' => 'sms-001',
        ])->assertOk();
        $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery, 'status' => 'SENT']);

        $reservation = (string) Str::uuid();
        DB::table('reservations')->insert(['id' => $reservation, 'reference' => 'AB'.strtoupper(Str::random(6)), 'user_id' => $passenger->id, 'trip_type' => 'ONE_WAY', 'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', $reservation), 'total_amount' => 0, 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $passengerId = (string) Str::uuid();
        DB::table('reservation_passengers')->insert(['id' => $passengerId, 'reservation_id' => $reservation, 'position' => 1, 'name' => 'Test Passenger']);
        $this->actingAs($passenger)->postJson('/api/v1/actions/assistance', [
            'reservation_id' => $reservation, 'passenger_id' => $passengerId, 'type' => 'WHEELCHAIR', 'notes' => 'Requires airport assistance',
        ])->assertOk();
        $this->actingAs($support)->postJson('/api/v1/actions/document_verify', [
            'reservation_passenger_id' => $passengerId, 'document_type' => 'PASSPORT', 'document_number' => 'P1234567', 'status' => 'VERIFIED',
        ])->assertOk();
        $this->assertDatabaseHas('passenger_assistance_requests', ['reservation_id' => $reservation, 'status' => 'OPEN']);
    }

    public function test_analytics_dataset_cleaning_training_and_evaluation_pipeline(): void
    {
        $analyst = $this->user('analyst');
        $dataset = $this->actingAs($analyst)->postJson('/api/v1/actions/dataset', [
            'code' => 'OPS_EVENTS_PIPELINE', 'name' => 'Operational event history', 'source' => 'flight_actual_events',
        ])->assertOk()->json('id');

        $this->actingAs($analyst)->postJson('/api/v1/actions/dataset_clean', [
            'dataset_id' => $dataset,
            'records' => [
                ['event_key' => 'evt-1', 'flight_id' => 1, 'event_time' => now()->subHour()->toIso8601String(), 'delay_minutes' => 12],
                ['event_key' => 'evt-2', 'flight_id' => 1, 'event_time' => now()->subMinutes(30)->toIso8601String(), 'delay_minutes' => 4],
                ['event_key' => 'evt-invalid', 'flight_id' => 999999, 'event_time' => now()->toIso8601String(), 'delay_minutes' => 2],
            ],
        ])->assertOk()->assertJsonPath('valid', 2)->assertJsonPath('rejected', 1);

        $run = $this->actingAs($analyst)->postJson('/api/v1/actions/model_run', [
            'dataset_id' => $dataset, 'model_type' => 'delay-baseline', 'train_ratio' => 0.5,
        ])->assertOk()->json('id');
        $this->actingAs($analyst)->postJson('/api/v1/actions/model_train', [
            'model_run_id' => $run, 'target_field' => 'delay_minutes',
        ])->assertOk()->assertJsonPath('status', 'COMPLETED');
        $this->actingAs($analyst)->postJson('/api/v1/actions/model_evaluate', ['model_run_id' => $run])->assertOk();
        $this->assertDatabaseHas('analytics_model_runs', ['id' => $run, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('analytics_dataset_records', ['dataset_id' => $dataset, 'status' => 'REJECTED']);
    }
}
