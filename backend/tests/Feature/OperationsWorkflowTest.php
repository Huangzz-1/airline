<?php

namespace Tests\Feature;

use App\Http\Controllers\WorkspaceController;
use App\Models\User;
use App\Modules\Boarding\BoardingService;
use App\Modules\Booking\BookingService;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Operations\OperationsService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationsWorkflowTest extends TestCase
{
    private function user(string $role, string $airport = 'HAN'): User
    {
        return User::factory()->create(['role' => $role, 'airport_code' => $airport]);
    }

    private function action(User $u, string $action, array $data)
    {
        return $this->actingAs($u)->postJson('/api/v1/actions/'.$action, $data);
    }

    private function draft(): array
    {
        $ops = $this->user('operations');
        $manager = $this->user('ops_manager');
        $fleet = $this->user('fleet_manager');
        $scheduler = $this->user('crew_scheduler');
        $plane = $this->action($fleet, 'aircraft', ['registration' => 'VN-'.strtoupper(Str::random(7)), 'type' => 'A320', 'capacity' => 12])->assertOk()->json('id');
        $start = now()->addDays(10)->startOfDay()->addHours(5);
        $id = $this->action($ops, 'schedule', ['number' => 'AS'.random_int(200, 9999), 'route_id' => DB::connection('ops')->table('routes')->first()->id, 'aircraft_id' => $plane, 'departure' => $start->toIso8601String(), 'arrival' => $start->copy()->addHours(2)->toIso8601String(), 'sale_price' => 1500000, 'gate' => 'A2'])->assertOk()->json('id');
        foreach (['PILOT', 'CABIN'] as $position) {
            $crew = $this->action($scheduler, 'crew', ['code' => Str::random(12), 'name' => 'Crew demo', 'position' => $position, 'qualification' => 'A320', 'license_expires' => now()->addYear()->toDateString()])->assertOk()->json('id');
            $this->action($scheduler, 'assign_crew', ['flight_id' => $id, 'crew_member_id' => $crew])->assertOk();
        }

        return compact('ops', 'manager', 'fleet', 'scheduler', 'plane', 'id', 'start');
    }

    private function publish(array $f): void
    {
        $this->action($f['ops'], 'submit', ['flight_id' => $f['id']])->assertOk();
        $this->action($f['manager'], 'publish', ['flight_id' => $f['id']])->assertOk();
    }

    private function paid(): array
    {
        $p = $this->user('passenger');
        $b = app(BookingService::class)->hold($p->id, 1, (string) Str::uuid());
        app(BookingService::class)->pay($b->id, $p->id, 'approve');
        $this->assertSame(0, app(OutboxRelay::class)->drain());

        return [$p, $b];
    }

    public function test_new_schedule_crew_approval_and_inventory_projection_replay(): void
    {
        $f = $this->draft();
        $this->assertFalse(DB::table('inventory')->where('flight_id', $f['id'])->exists());
        $this->publish($f);
        $this->assertDatabaseHas('flights', ['id' => $f['id'], 'status' => 'SCHEDULED'], 'ops');
        $this->assertDatabaseHas('inventory', ['flight_id' => $f['id'], 'capacity' => 12, 'price' => 1500000]);
        DB::connection('ops')->table('outbox')->update(['delivered_at' => null]);
        $this->assertSame(0, app(OutboxRelay::class)->drain());
        $this->assertSame(1, DB::table('inventory')->where('flight_id', $f['id'])->count());
        $passenger = $this->user('passenger');
        $this->assertNotEmpty(app(BookingService::class)->hold($passenger->id, $f['id'], (string) Str::uuid())->id);
    }

    public function test_roles_and_airport_scope_are_checked_for_workspace_and_mutations(): void
    {
        $passenger = $this->user('passenger');
        foreach (['catalog', 'scheduling', 'fleet', 'maintenance', 'crew', 'baggage', 'ground', 'finance', 'analytics', 'iot', 'fare', 'resources', 'disruptions'] as $section) {
            $this->actingAs($passenger)->getJson('/api/v1/workspace/'.$section)->assertForbidden();
        }
        $this->action($passenger, 'aircraft', ['registration' => 'VN-HACK', 'type' => 'A320', 'capacity' => 12])->assertForbidden();
        $this->action($this->user('operations', 'SGN'), 'submit', ['flight_id' => 1])->assertForbidden();
        $this->actingAs($this->user('admin'))->get('/workspace/finance')->assertForbidden();
    }

    public function test_master_data_duplicate_codes_and_route_validation(): void
    {
        $u = $this->user('data_admin');
        $this->action($u, 'airport', ['code' => 'CXR', 'name' => 'Cam Ranh', 'city' => 'Khánh Hòa', 'timezone' => 'Asia/Ho_Chi_Minh'])->assertOk();
        $this->action($u, 'airport', ['code' => 'CXR', 'name' => 'Duplicate', 'city' => 'Khánh Hòa', 'timezone' => 'Asia/Ho_Chi_Minh'])->assertConflict();
        $this->action($u, 'route', ['origin' => 'HAN', 'destination' => 'HAN', 'duration_minutes' => 100, 'distance_km' => 500])->assertUnprocessable();
        $this->action($u, 'route', ['origin' => 'HAN', 'destination' => 'XXX', 'duration_minutes' => 100, 'distance_km' => 500])->assertUnprocessable();
    }

    public function test_publish_rejects_missing_crew_self_approval_and_aircraft_conflict(): void
    {
        $f = $this->draft();
        DB::connection('ops')->table('crew_assignments')->where('flight_id', $f['id'])->delete();
        $this->action($f['ops'], 'submit', ['flight_id' => $f['id']])->assertOk();
        $this->action($f['manager'], 'publish', ['flight_id' => $f['id']])->assertConflict();
        $f = $this->draft();
        $this->action($f['ops'], 'submit', ['flight_id' => $f['id']])->assertOk();
        $same = $f['ops'];
        $same->forceFill(['role' => 'ops_manager'])->save();
        $this->action($same, 'publish', ['flight_id' => $f['id']])->assertConflict();
        $same->forceFill(['role' => 'operations'])->save();
        DB::connection('ops')->table('flights')->insert(['number' => 'AS99999', 'origin' => 'HAN', 'destination' => 'SGN', 'aircraft_id' => $f['plane'], 'departure' => $f['start'], 'arrival' => $f['start']->copy()->addHours(2), 'status' => 'SCHEDULED', 'gate' => 'A3']);
        $this->action($f['manager'], 'publish', ['flight_id' => $f['id']])->assertConflict();
    }

    public function test_crew_qualification_expiry_and_rest_conflicts_are_rejected(): void
    {
        $f = $this->draft();
        $crew = DB::connection('ops')->table('crew_members')->whereIn('id', DB::connection('ops')->table('crew_assignments')->where('flight_id', $f['id'])->pluck('crew_member_id'))->first();
        DB::connection('ops')->table('crew_members')->where('id', $crew->id)->update(['license_expires' => now()->subDay()]);
        $this->action($f['ops'], 'submit', ['flight_id' => $f['id']])->assertOk();
        $this->action($f['manager'], 'publish', ['flight_id' => $f['id']])->assertConflict();
        DB::connection('ops')->table('crew_members')->where('id', $crew->id)->update(['license_expires' => now()->addYear()]);
        $this->action($f['manager'], 'publish', ['flight_id' => $f['id']])->assertOk();
        $new = $this->action($f['ops'], 'schedule', ['number' => 'AS99001', 'route_id' => 1, 'aircraft_id' => $f['plane'], 'departure' => $f['start']->copy()->addHours(5)->toIso8601String(), 'arrival' => $f['start']->copy()->addHours(7)->toIso8601String(), 'sale_price' => 2000000, 'gate' => 'A2'])->assertOk()->json('id');
        $this->action($f['scheduler'], 'assign_crew', ['flight_id' => $new, 'crew_member_id' => $crew->id])->assertConflict();
    }

    public function test_maintenance_locks_aircraft_and_requires_independent_release(): void
    {
        $f = $this->draft();
        $tech = $this->user('maintenance');
        $approver = $this->user('maintenance');
        $work = $this->action($tech, 'work_order', ['aircraft_id' => $f['plane'], 'title' => 'Kiểm tra định kỳ', 'due_at' => now()->addDays(2)->toIso8601String()])->assertOk()->json('id');
        $this->assertDatabaseHas('aircraft', ['id' => $f['plane'], 'status' => 'MAINTENANCE'], 'ops');
        $this->action($f['ops'], 'submit', ['flight_id' => $f['id']])->assertOk();
        $this->action($f['manager'], 'publish', ['flight_id' => $f['id']])->assertConflict();
        $this->action($approver, 'release_work', ['work_order_id' => $work])->assertConflict();
        $this->action($tech, 'complete_work', ['work_order_id' => $work, 'evidence' => 'Đã kiểm tra theo checklist mô phỏng.'])->assertOk();
        $this->action($tech, 'release_work', ['work_order_id' => $work])->assertConflict();
        $this->action($approver, 'release_work', ['work_order_id' => $work])->assertOk();
        $this->assertDatabaseHas('aircraft', ['id' => $f['plane'], 'status' => 'AVAILABLE'], 'ops');
        $this->action($f['manager'], 'publish', ['flight_id' => $f['id']])->assertOk();
    }

    public function test_resource_overlap_and_release_before_reallocation(): void
    {
        $f = $this->draft();
        $res = DB::connection('ops')->table('airport_resources')->where('airport_code', 'HAN')->where('type', 'GATE')->first();
        $data = ['flight_id' => $f['id'], 'resource_id' => $res->id, 'starts_at' => $f['start']->copy()->subHour()->toIso8601String(), 'ends_at' => $f['start']->copy()->addHour()->toIso8601String(), 'reason' => 'Phân bổ cửa khai thác'];
        $id = $this->action($f['ops'], 'allocate', $data)->assertOk()->json('id');
        $this->action($f['ops'], 'allocate', $data)->assertConflict();
        $this->action($f['ops'], 'unallocate', ['allocation_id' => $id, 'reason' => 'Điều phối lại cửa'])->assertOk();
        $this->action($f['ops'], 'allocate', $data)->assertOk();
    }

    public function test_ground_sla_evidence_ownership_and_flight_state_machine(): void
    {
        $ops = $this->user('operations');
        $ground = $this->user('ground_agent');
        $other = $this->user('ground_agent');
        $id = $this->action($ops, 'ground_task', ['flight_id' => 1, 'title' => 'Vệ sinh khoang', 'assigned_to' => $ground->id, 'due_at' => now()->addMinutes(10)->toIso8601String(), 'mandatory' => true])->assertOk()->json('id');
        $this->action($ops, 'flight_state', ['flight_id' => 1, 'status' => 'GATE_CLOSED', 'reason' => 'Đóng cửa chuyến'])->assertConflict();
        $this->action($other, 'ground_state', ['task_id' => $id, 'status' => 'IN_PROGRESS'])->assertNotFound();
        $this->action($ground, 'ground_state', ['task_id' => $id, 'status' => 'IN_PROGRESS'])->assertOk();
        $this->action($ground, 'ground_state', ['task_id' => $id, 'status' => 'COMPLETED'])->assertUnprocessable();
        $this->action($ground, 'ground_state', ['task_id' => $id, 'status' => 'COMPLETED', 'evidence' => 'Đã vệ sinh và có xác nhận bàn giao.'])->assertOk();
        $this->action($ops, 'flight_state', ['flight_id' => 1, 'status' => 'GATE_CLOSED', 'reason' => 'Đã đủ nhiệm vụ'])->assertOk();
        $this->action($ops, 'flight_state', ['flight_id' => 1, 'status' => 'DEPARTED', 'reason' => 'Pushback mô phỏng'])->assertOk();
        $this->action($ops, 'flight_state', ['flight_id' => 1, 'status' => 'ARRIVED', 'reason' => 'Đến nơi mô phỏng'])->assertOk();
        $this->assertDatabaseHas('aircraft', ['id' => 1, 'cycles' => 1], 'ops');
        $this->action($ops, 'flight_state', ['flight_id' => 1, 'status' => 'ARRIVED', 'reason' => 'Gửi lại'])->assertConflict();
        $this->assertDatabaseHas('aircraft', ['id' => 1, 'cycles' => 1], 'ops');
    }

    public function test_baggage_tag_scan_dedup_custody_and_passenger_ownership(): void
    {
        [$p,$b] = $this->paid();
        app(BoardingService::class)->checkin($b->id, $p->id, '1A');
        $agent = $this->user('airport_agent');
        $request = (string) Str::uuid();
        $tag = $this->action($agent, 'bag_tag', ['booking_id' => $b->id, 'weight' => 20, 'request_id' => $request])->assertOk()->json();
        $this->action($agent, 'bag_tag', ['booking_id' => $b->id, 'weight' => 20, 'request_id' => $request])->assertOk();
        $this->assertSame(1, DB::table('baggage')->count());
        $data = ['event_id' => (string) Str::uuid(), 'tag' => $tag['tag'], 'flight_id' => 1, 'status' => 'LOADED', 'location' => 'HAN-GATE', 'custody' => 'Đội mặt đất'];
        $this->action($agent, 'bag_scan', $data)->assertConflict();
        foreach (['ACCEPTED', 'SCREENED', 'LOADED'] as $state) {
            $data['event_id'] = (string) Str::uuid();
            $data['status'] = $state;
            $this->action($agent, 'bag_scan', $data)->assertOk();
        }
        $this->action($agent, 'bag_scan', $data)->assertOk();
        $this->assertSame(4, DB::table('baggage_events')->count());
        $this->actingAs($p)->getJson('/api/v1/baggage/'.$tag['id'])->assertOk()->assertJsonPath('status', 'LOADED');
        $this->actingAs($this->user('passenger'))->getJson('/api/v1/baggage/'.$tag['id'])->assertNotFound();
        $this->action($this->user('airport_agent', 'DAD'), 'bag_scan', $data)->assertForbidden();
    }

    public function test_refund_requires_finance_decision_releases_inventory_and_replays_once(): void
    {
        [$p,$b] = $this->paid();
        $this->action($p, 'refund_request', ['booking_id' => $b->id, 'reason' => 'Thay đổi kế hoạch cá nhân'])->assertOk();
        $id = DB::connection('finance')->table('refunds')->first()->id;
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'REFUND_PENDING']);
        $this->action($p, 'refund_decide', ['refund_id' => $id, 'approve' => 1])->assertForbidden();
        $this->action($this->user('finance'), 'refund_decide', ['refund_id' => $id, 'approve' => 1])->assertOk();
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'REFUNDED']);
        DB::connection('finance')->table('outbox')->where('type', 'RefundResult')->update(['delivered_at' => null]);
        app(OutboxRelay::class)->drain();
        $this->assertSame(1, DB::connection('finance')->table('ledger_entries')->where('kind', 'REFUND')->count());
    }

    public function test_rejected_refund_can_be_requested_again_and_checked_in_again(): void
    {
        [$p,$b] = $this->paid();
        app(BoardingService::class)->checkin($b->id, $p->id, '1A');
        $this->action($p, 'refund_request', ['booking_id' => $b->id, 'reason' => 'Đề nghị hoàn sandbox'])->assertOk();
        $r = DB::connection('finance')->table('refunds')->first();
        $fin = $this->user('finance');
        $this->action($fin, 'refund_decide', ['refund_id' => $r->id, 'approve' => 0])->assertOk();
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'CONFIRMED']);
        $pass = app(BoardingService::class)->checkin($b->id, $p->id, '2A');
        $this->assertNull($pass->revoked_at);
        $this->action($p, 'refund_request', ['booking_id' => $b->id, 'reason' => 'Yêu cầu hoàn lần hai'])->assertOk();
        $this->assertDatabaseHas('refunds', ['id' => $r->id, 'status' => 'REQUESTED'], 'finance');
    }

    public function test_cancellation_approval_compensates_payment_and_rejects_self_approval(): void
    {
        [$p,$b] = $this->paid();
        $ops = $this->user('operations');
        $manager = $this->user('ops_manager');
        $plan = $this->action($ops, 'disruption', ['flight_id' => 1, 'type' => 'CANCEL', 'delay_minutes' => 0, 'reason' => 'Thời tiết mô phỏng'])->assertOk()->json('id');
        $this->action($manager, 'approve_disruption', ['disruption_id' => $plan])->assertOk();
        app(OutboxRelay::class)->drain();
        $this->assertDatabaseHas('flights', ['id' => 1, 'status' => 'CANCELLED'], 'ops');
        $this->assertDatabaseHas('inventory', ['flight_id' => 1, 'status' => 'CLOSED']);
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'REFUNDED']);
        $this->action($manager, 'approve_disruption', ['disruption_id' => $plan])->assertConflict();
        $this->assertGreaterThan(0, DB::table('notifications')->where('user_id', $p->id)->count());
    }

    public function test_delay_approval_records_version_and_notifies_passengers(): void
    {
        [$p,$b] = $this->paid();
        $old = DB::connection('ops')->table('flights')->find(1);
        $ops = $this->user('operations');
        $manager = $this->user('ops_manager');
        $id = $this->action($ops, 'disruption', ['flight_id' => 1, 'type' => 'DELAY', 'delay_minutes' => 20, 'reason' => 'Thay đổi giờ mô phỏng'])->assertOk()->json('id');
        $this->action($manager, 'approve_disruption', ['disruption_id' => $id])->assertOk();
        $f = DB::connection('ops')->table('flights')->find(1);
        $this->assertSame(2, $f->version);
        $this->assertSame('DELAYED', $f->status);
        $this->assertSame(20, (int) Carbon::parse($old->departure)->diffInMinutes($f->departure));
        $this->assertTrue(DB::table('notifications')->where('user_id', $p->id)->exists());
    }

    public function test_reconciliation_mismatch_exception_independent_close_and_csv(): void
    {
        [$p,$b] = $this->paid();
        $fin = $this->user('finance');
        $approver = $this->user('finance');
        DB::connection('finance')->table('payments')->where('booking_id', $b->id)->update(['amount' => 1]);
        $id = $this->action($fin, 'reconcile', ['start' => now()->toDateString(), 'end' => now()->toDateString()])->assertOk()->json('id');
        $entry = DB::connection('finance')->table('reconciliation_entries')->where('run_id', $id)->first();
        $this->assertSame('MISMATCH', $entry->result);
        $this->action($approver, 'close_period', ['run_id' => $id])->assertConflict();
        $this->action($fin, 'resolve', ['entry_id' => $entry->id, 'note' => 'Chấp nhận ngoại lệ có căn cứ mô phỏng'])->assertOk();
        $this->action($fin, 'close_period', ['run_id' => $id])->assertConflict();
        $this->action($approver, 'close_period', ['run_id' => $id])->assertOk();
        $this->actingAs($approver)->get('/workspace/reports/'.$id.'/export')->assertOk()->assertDownload();
        $this->action($fin, 'resolve', ['entry_id' => $entry->id, 'note' => 'Sửa kỳ đã khóa không được phép'])->assertConflict();
    }

    public function test_iot_duplicate_late_event_cannot_overwrite_latest_and_forecast_explains(): void
    {
        $u = $this->user('integration_admin');
        $id = $this->action($u, 'device', ['code' => 'SIM-HAN-01', 'airport_code' => 'HAN', 'threshold' => 50])->assertOk()->json('id');
        $d = ['event_id' => (string) Str::uuid(), 'device_id' => $id, 'sequence' => 2, 'value' => 70, 'event_time' => now()->toIso8601String()];
        $this->action($u, 'telemetry', $d)->assertOk()->assertJsonPath('alert', 1);
        $this->action($u, 'telemetry', $d)->assertOk();
        $d['value'] = 71;
        $this->action($u, 'telemetry', $d)->assertConflict();
        $d['event_id'] = (string) Str::uuid();
        $d['sequence'] = 1;
        $d['event_time'] = now()->subMinutes(10)->toIso8601String();
        $d['value'] = 20;
        $this->action($u, 'telemetry', $d)->assertOk()->assertJsonPath('late', 1);
        $this->assertSame('70.00', DB::connection('ops')->table('devices')->find($id)->latest_value);
        $this->assertSame(2, DB::connection('ops')->table('telemetry')->count());
        $this->action($this->user('analyst'), 'forecast', ['flight_id' => 1])->assertOk()->assertJsonPath('model', 'rules-demo-v1')->assertJsonPath('simulated', true)->assertJsonPath('factors.recent_iot_alerts', 1);
    }

    public function test_support_case_ownership_responses_and_notification_scope(): void
    {
        [$p,$b] = $this->paid();
        $other = $this->user('passenger');
        $data = ['booking_id' => $b->id, 'type' => 'ASSISTANCE', 'subject' => 'Yêu cầu xe lăn', 'message' => 'Cần hỗ trợ tại cửa khởi hành.'];
        $this->action($other, 'support', $data)->assertNotFound();
        $id = $this->action($p, 'support', $data)->assertOk()->json('id');
        $this->action($p, 'support_reply', ['case_id' => $id, 'status' => 'RESOLVED', 'response' => 'Tự giải quyết không được phép'])->assertForbidden();
        $this->action($this->user('customer_service'), 'support_reply', ['case_id' => $id, 'status' => 'RESOLVED', 'response' => 'Đã ghi nhận và chuyển đội phục vụ hỗ trợ.'])->assertOk();
        $notice = DB::table('notifications')->where('user_id', $p->id)->latest()->first();
        $this->action($other, 'read_notice', ['notification_id' => $notice->id])->assertNotFound();
        $this->action($p, 'read_notice', ['notification_id' => $notice->id])->assertOk();
    }

    public function test_fare_cannot_reduce_capacity_below_sold_and_held_places(): void
    {
        [$p,$b] = $this->paid();
        $this->assertNotEmpty(app(BookingService::class)->hold($p->id, 1, (string) Str::uuid())->id);
        $data = ['flight_id' => 1, 'price' => 2000000, 'capacity' => 1, 'status' => 'OPEN', 'reason' => 'Điều chỉnh tồn mô phỏng'];
        $this->action($this->user('data_admin'), 'fare', $data)->assertConflict();
        $data['capacity'] = 60;
        $this->action($this->user('data_admin'), 'fare', $data)->assertOk();
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'amount' => 1290000]);
    }

    public function test_all_workspace_pages_render_for_allowed_roles_without_exposing_others(): void
    {
        foreach (WorkspaceController::sections() as $section => [$title,$roles]) {
            $u = $this->user($roles[0]);
            $this->actingAs($u)->get('/workspace/'.$section)->assertOk()->assertSee($title);
        }
    }

    public function test_master_change_requires_independent_approval_and_detects_stale_source(): void
    {
        $a = $this->user('data_admin');
        $b = $this->user('data_admin');
        $id = $this->action($a, 'master_propose', ['entity' => 'airports', 'record_id' => 'HAN', 'changes' => ['name' => 'Nội Bài mới', 'city' => 'Hà Nội', 'timezone' => 'Asia/Ho_Chi_Minh'], 'reason' => 'Chuẩn hóa tên hiển thị'])->assertOk()->json('id');
        $this->action($a, 'master_approve', ['change_id' => $id])->assertConflict();
        $this->action($b, 'master_approve', ['change_id' => $id])->assertOk();
        $this->assertDatabaseHas('airports', ['code' => 'HAN', 'name' => 'Nội Bài mới'], 'ops');
        $data = ['entity' => 'airport_resources', 'record_id' => (string) DB::connection('ops')->table('airport_resources')->first()->id, 'changes' => ['active' => false], 'reason' => 'Ngừng sử dụng mô phỏng'];
        $first = $this->action($a, 'master_propose', $data)->assertOk()->json('id');
        $second = $this->action($a, 'master_propose', $data)->assertOk()->json('id');
        $this->action($b, 'master_approve', ['change_id' => $first])->assertOk();
        $this->action($b, 'master_approve', ['change_id' => $second])->assertConflict();
    }

    public function test_reissued_qr_rejects_old_token_and_offload_records_bag_custody(): void
    {
        [$p,$b] = $this->paid();
        $pass = app(BoardingService::class)->checkin($b->id, $p->id, '1A');
        $agent = $this->user('airport_agent');
        $new = $this->action($p, 'change_seat', ['booking_id' => $b->id, 'seat' => '2A'])->assertOk()->json('token');
        $this->assertNotSame($pass->token, $new);
        $this->actingAs($agent)->postJson('/api/v1/boarding', ['flight_id' => 1, 'token' => $pass->token])->assertUnprocessable();
        $bag = $this->action($agent, 'bag_tag', ['booking_id' => $b->id, 'weight' => 12, 'request_id' => (string) Str::uuid()])->assertOk()->json('id');
        $this->action($agent, 'offload', ['booking_id' => $b->id, 'reason' => 'Xác nhận khách vắng và đã dỡ hành lý mô phỏng'])->assertOk();
        $this->assertDatabaseHas('baggage', ['id' => $bag, 'status' => 'OFFLOADED']);
        $this->actingAs($agent)->postJson('/api/v1/boarding', ['flight_id' => 1, 'token' => $new])->assertConflict();
    }

    public function test_fare_bucket_quota_and_idempotency_payload(): void
    {
        $admin = $this->user('data_admin');
        $p = $this->user('passenger');
        $other = $this->user('passenger');
        $this->action($admin, 'fare_class', ['flight_id' => 1, 'code' => 'FLEX', 'name' => 'Linh hoạt', 'quota' => 1, 'price' => 1900000, 'active' => true])->assertOk();
        $d = ['flight_id' => 1, 'fare_class' => 'FLEX', 'idempotency_key' => 'fare-flex-key'];
        $this->actingAs($p)->postJson('/api/v1/bookings', $d)->assertCreated()->assertJsonPath('amount', 1900000)->assertJsonPath('fare_class', 'FLEX');
        $this->actingAs($other)->postJson('/api/v1/bookings', $d)->assertConflict();
        $d['fare_class'] = 'ECONOMY';
        $this->actingAs($p)->postJson('/api/v1/bookings', $d)->assertConflict();
    }

    public function test_change_unpaid_flight_is_atomic_and_does_not_extend_hold_ttl(): void
    {
        $p = $this->user('passenger');
        $b = app(BookingService::class)->hold($p->id, 1, 'change-flight-key');
        $target = DB::connection('ops')->table('flights')->where('destination', 'SGN')->where('id', '!=', 1)->first();
        $data = ['booking_id' => $b->id, 'flight_id' => $target->id, 'request_id' => (string) Str::uuid()];
        $this->action($p, 'change_flight', $data)->assertOk()->assertJsonPath('flight_id', $target->id);
        $this->action($p, 'change_flight', $data)->assertOk();
        $updated = DB::table('bookings')->find($b->id);
        $this->assertSame($b->expires_at, $updated->expires_at);
        $this->assertSame(1, DB::table('booking_changes')->count());
        app(BookingService::class)->pay($b->id, $p->id, 'approve');
        app(OutboxRelay::class)->drain();
        $data['request_id'] = (string) Str::uuid();
        $data['flight_id'] = 1;
        $this->action($p, 'change_flight', $data)->assertConflict();
    }

    public function test_projection_retries_commerce_outage_without_duplicate_inventory(): void
    {
        $f = $this->draft();
        app(OperationsService::class)->execute($f['ops'], 'submit', ['flight_id' => $f['id']]);
        app(OperationsService::class)->execute($f['manager'], 'publish', ['flight_id' => $f['id']]);
        $original = config('database.connections.mysql');
        config(['database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 1]);
        DB::purge('mysql');
        try {
            $this->assertGreaterThan(0, app(OutboxRelay::class)->drain());
        } finally {
            config(['database.connections.mysql' => $original]);
            DB::purge('mysql');
        }
        DB::connection('ops')->table('outbox')->update(['available_at' => now()->subSecond()]);
        $this->assertSame(0, app(OutboxRelay::class)->drain());
        $this->assertSame(1, DB::table('inventory')->where('flight_id', $f['id'])->count());
    }

    public function test_cancel_during_pending_payment_waits_then_compensates_capture(): void
    {
        $p = $this->user('passenger');
        $b = app(BookingService::class)->hold($p->id, 1, 'cancel-pending-key');
        app(BookingService::class)->pay($b->id, $p->id, 'approve');
        $ops = $this->user('operations');
        $manager = $this->user('ops_manager');
        $id = $this->action($ops, 'disruption', ['flight_id' => 1, 'type' => 'CANCEL', 'delay_minutes' => 0, 'reason' => 'Phê duyệt hủy trong lúc thanh toán'])->assertOk()->json('id');
        $this->action($manager, 'approve_disruption', ['disruption_id' => $id])->assertOk();
        $this->travel(3)->seconds();
        $this->assertSame(0, app(OutboxRelay::class)->drain());
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'REFUNDED']);
        $this->assertSame(1, DB::connection('finance')->table('ledger_entries')->where('kind', 'REFUND')->count());
        $this->travelBack();
    }

    public function test_paid_exchange_capture_then_refund_and_failure_restores_source(): void
    {
        [$p,$b] = $this->paid();
        $target = DB::connection('ops')->table('flights')->where('destination', 'SGN')->where('id', '!=', 1)->first();
        $fin = $this->user('finance');
        $request = (string) Str::uuid();
        $d = ['request_id' => $request, 'booking_id' => $b->id, 'flight_id' => $target->id, 'reason' => 'Đổi lịch theo kế hoạch cá nhân'];
        $this->action($p, 'exchange_request', $d)->assertOk();
        $this->action($p, 'exchange_request', $d)->assertOk();
        $req = DB::table('exchange_requests')->find($request);
        $this->actingAs($p)->postJson('/api/v1/bookings/'.$req->target_booking_id.'/pay', ['scenario' => 'approve'])->assertConflict();
        $this->action($fin, 'exchange_decide', ['exchange_id' => $request, 'approve' => true, 'scenario' => 'approve'])->assertOk();
        $this->assertSame(0, app(OutboxRelay::class)->drain());
        $this->assertDatabaseHas('exchange_requests', ['id' => $request, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'REFUNDED']);
        $this->assertDatabaseHas('bookings', ['id' => $req->target_booking_id, 'status' => 'CONFIRMED']);
        [$p2,$b2] = $this->paid();
        $request2 = (string) Str::uuid();
        $this->action($p2, 'exchange_request', ['request_id' => $request2, 'booking_id' => $b2->id, 'flight_id' => $target->id, 'reason' => 'Thử nhánh thanh toán thất bại'])->assertOk();
        $this->action($fin, 'exchange_decide', ['exchange_id' => $request2, 'approve' => true, 'scenario' => 'decline'])->assertOk();
        $this->assertDatabaseHas('bookings', ['id' => $b2->id, 'status' => 'CONFIRMED']);
        $this->assertDatabaseHas('exchange_requests', ['id' => $request2, 'status' => 'FAILED']);
    }

    public function test_exchange_target_expiry_or_cancellation_does_not_change_paid_source(): void
    {
        [$p,$b] = $this->paid();
        $target = DB::connection('ops')->table('flights')->where('destination', 'SGN')->where('id', '!=', 1)->first();
        $request = (string) Str::uuid();
        $this->action($p, 'exchange_request', ['request_id' => $request, 'booking_id' => $b->id, 'flight_id' => $target->id, 'reason' => 'Đổi nhưng không duyệt kịp'])->assertOk();
        $this->travel(11)->minutes();
        app(BookingService::class)->expire();
        $this->assertDatabaseHas('exchange_requests', ['id' => $request, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'CONFIRMED']);
        $this->travelBack();
    }

    public function test_recurring_schedule_and_batch_submission_are_atomic(): void
    {
        $f = $this->draft();
        $from = now()->addDays(20)->startOfDay();
        $to = $from->copy()->addDays(2);
        $ids = $this->action($f['ops'], 'season', ['number' => 'AS7000', 'route_id' => 1, 'aircraft_id' => $f['plane'], 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'weekdays' => '1,2,3,4,5,6,7', 'local_departure' => '09:00', 'duration_minutes' => 120, 'sale_price' => 1500000, 'gate' => 'A2'])->assertOk()->json('flight_ids');
        $this->assertCount(3, $ids);
        $this->action($f['ops'], 'submit_batch', ['flight_ids' => implode(',', $ids)])->assertOk();
        $this->action($f['manager'], 'publish_batch', ['flight_ids' => implode(',', $ids)])->assertConflict();
        $this->assertSame(0, DB::connection('ops')->table('flights')->whereIn('id', $ids)->where('status', 'SCHEDULED')->count());
    }

    public function test_csv_import_stages_requires_independent_approval_and_rolls_back_bad_rows(): void
    {
        $a = $this->user('data_admin');
        $b = $this->user('data_admin');
        $csv = "code,name,city,timezone\nVCA,Cần Thơ,Cần Thơ,Asia/Ho_Chi_Minh\n";
        $file = UploadedFile::fake()->createWithContent('airports.csv', $csv);
        $this->actingAs($a)->post('/workspace/master/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('staged', 1);
        $this->assertDatabaseMissing('airports', ['code' => 'VCA'], 'ops');
        $id = DB::connection('ops')->table('master_changes')->first()->id;
        $this->action($a, 'master_approve', ['change_id' => $id])->assertConflict();
        $this->action($b, 'master_approve', ['change_id' => $id])->assertOk();
        $this->assertDatabaseHas('airports', ['code' => 'VCA'], 'ops');
        $this->actingAs($b)->get('/workspace/master/export/airports')->assertOk()->assertDownload();
        $bad = UploadedFile::fake()->createWithContent('bad.csv', "code,name,city,timezone\nHUI,Phú Bài,Huế,Asia/Ho_Chi_Minh\nABC,Lỗi,Lỗi,Invalid/Zone\n");
        $this->actingAs($a)->post('/workspace/master/import', ['file' => $bad], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(1, DB::connection('ops')->table('master_changes')->count());
    }

    public function test_resource_api_aliases_share_validation_and_roles(): void
    {
        $u = $this->user('data_admin');
        $this->actingAs($u)->postJson('/api/v1/airports', ['code' => 'VDO', 'name' => 'Vân Đồn', 'city' => 'Quảng Ninh', 'timezone' => 'Asia/Ho_Chi_Minh'])->assertOk();
        $this->actingAs($this->user('operations'))->postJson('/api/v1/schedules', [])->assertUnprocessable();
        $this->actingAs($this->user('passenger'))->postJson('/api/v1/devices', ['code' => 'BAD', 'airport_code' => 'HAN', 'threshold' => 50])->assertForbidden();
    }

    public function test_support_history_is_append_only_and_scoped_to_owner(): void
    {
        $p = $this->user('passenger');
        $care = $this->user('customer_service');
        $id = $this->action($p, 'support', ['type' => 'COMPLAINT', 'subject' => 'Yêu cầu hỗ trợ chuyến', 'message' => 'Nội dung yêu cầu đầu tiên.'])->assertOk()->json('id');
        $this->action($care, 'support_reply', ['case_id' => $id, 'status' => 'IN_PROGRESS', 'response' => 'Đang kiểm tra thông tin chuyến của bạn.'])->assertOk();
        $this->action($p, 'support_followup', ['case_id' => $id, 'message' => 'Tôi bổ sung thêm nội dung cần thiết.'])->assertOk();
        $this->action($care, 'support_reply', ['case_id' => $id, 'status' => 'RESOLVED', 'response' => 'Đã giải quyết yêu cầu theo quy trình.'])->assertOk();
        $this->assertSame(4, DB::table('support_messages')->where('case_id', $id)->count());
        $this->actingAs($p)->getJson('/api/v1/support-cases/'.$id)->assertOk()->assertJsonCount(4, 'messages');
        $this->actingAs($this->user('passenger'))->get('/support/'.$id)->assertNotFound();
        $this->actingAs($p)->get('/support/'.$id)->assertOk()->assertSee('Nội dung yêu cầu đầu tiên.');
        $this->action($p, 'support_followup', ['case_id' => $id, 'message' => 'Không thể ghi vào yêu cầu đã đóng.'])->assertConflict();
    }
}
