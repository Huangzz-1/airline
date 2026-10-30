<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Boarding\BoardingService;
use App\Modules\Booking\BookingService;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Operations\FlightChangeService;
use App\Modules\Operations\MasterDataService;
use App\Modules\Operations\OperationsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlightResourceChangeTest extends TestCase
{
    private function user(string $role, string $airport = 'HAN'): User
    {
        return User::factory()->create(['role' => $role, 'airport_code' => $airport]);
    }

    private function fixture(int $newCapacity = 12): array
    {
        $ops = $this->user('operations');
        $manager = $this->user('ops_manager');
        $scheduler = $this->user('crew_scheduler');
        $fleet = $this->user('fleet_manager');
        $svc = app(OperationsService::class);
        $old = $svc->execute($fleet, 'aircraft', ['registration' => 'VN-OLD', 'type' => 'A320', 'capacity' => 18])->id;
        $new = $svc->execute($fleet, 'aircraft', ['registration' => 'VN-NEW', 'type' => 'A320', 'capacity' => $newCapacity])->id;
        $departure = now()->addMinutes(45);
        $f = $svc->execute($ops, 'schedule', ['number' => 'AS7800', 'route_id' => DB::connection('ops')->table('routes')->where('destination', 'SGN')->first()->id, 'aircraft_id' => $old, 'departure' => $departure->toIso8601String(), 'arrival' => $departure->copy()->addHours(2)->toIso8601String(), 'sale_price' => 1700000, 'gate' => 'A2']);
        foreach ([1001, 1002] as $crew) {
            $svc->execute($scheduler, 'assign_crew', ['flight_id' => $f->id, 'crew_member_id' => $crew]);
        }
        $svc->execute($ops, 'submit', ['flight_id' => $f->id]);
        $svc->execute($manager, 'publish', ['flight_id' => $f->id]);
        $this->drain(1);
        $passenger = $this->user('passenger');
        $booking = app(BookingService::class)->hold($passenger->id, $f->id, (string) Str::uuid());
        app(BookingService::class)->pay($booking->id, $passenger->id, 'approve');
        $this->drain(1);
        $pass = app(BoardingService::class)->checkin($booking->id, $passenger->id, '3F');

        return compact('ops', 'manager', 'scheduler', 'fleet', 'old', 'new', 'f', 'passenger', 'booking', 'pass', 'departure');
    }

    private function drain(int $times = 3): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->assertSame(0, app(OutboxRelay::class)->drain());
        }
    }

    private function propose(array $f): object
    {
        return app(FlightChangeService::class)->propose($f['ops'], ['flight_id' => $f['f']->id, 'kind' => 'AIRCRAFT', 'target_aircraft_id' => $f['new'], 'reason' => 'Đổi tàu dự phòng đã kiểm tra']);
    }

    private function approve(array $f, object $p): void
    {
        app(FlightChangeService::class)->decide($f['manager'], ['change_id' => $p->id, 'approve' => true]);
    }

    public function test_aircraft_saga_freezes_remaps_reissues_then_opens_once(): void
    {
        $f = $this->fixture();
        $p = $this->propose($f);
        $this->approve($f, $p);
        $id = $f['f']->id;
        $this->assertDatabaseHas('flights', ['id' => $id, 'aircraft_id' => $f['old'], 'status' => 'CHANGE_PENDING'], 'ops');
        $this->assertSame(2, DB::connection('ops')->table('change_reservations')->count());
        $this->drain(1);
        $this->assertDatabaseHas('inventory', ['flight_id' => $id, 'status' => 'RECONFIGURING', 'frozen_change_id' => $p->id]);
        $this->assertDatabaseHas('flight_changes', ['id' => $p->id, 'status' => 'COMMITTING'], 'ops');
        $this->actingAs($f['passenger'])->postJson('/api/v1/actions/change_seat', ['booking_id' => $f['booking']->id, 'seat' => '1B'])->assertConflict();
        app(BookingService::class)->expire();
        $this->drain(1);
        $this->assertDatabaseHas('flights', ['id' => $id, 'aircraft_id' => $f['new'], 'status' => 'SCHEDULED'], 'ops');
        $this->assertDatabaseHas('inventory', ['flight_id' => $id, 'status' => 'RECONFIGURING']);
        $this->actingAs($f['passenger'])->postJson('/api/v1/bookings', ['flight_id' => $id, 'idempotency_key' => 'frozen-hold-key'])->assertConflict();
        $this->drain(1);
        $this->assertDatabaseHas('inventory', ['flight_id' => $id, 'capacity' => 12, 'status' => 'OPEN', 'frozen_change_id' => null]);
        $this->assertDatabaseHas('bookings', ['id' => $f['booking']->id, 'seat' => '1A', 'status' => 'CHECKED_IN', 'amount' => 1700000]);
        $newPass = app(BoardingService::class)->pass($f['booking']->id, $f['passenger']->id);
        $this->assertNotSame($f['pass']->token, $newPass->token);
        $this->assertSame(1, DB::table('seat_reassignments')->count());
        $this->assertSame(0, DB::connection('ops')->table('change_reservations')->count());
        app(OperationsService::class)->execute($f['ops'], 'flight_state', ['flight_id' => $id, 'status' => 'BOARDING', 'reason' => 'Đã xác nhận tàu và ghế mới']);
        $agent = $this->user('airport_agent');
        $this->actingAs($agent)->postJson('/api/v1/boarding', ['flight_id' => $id, 'token' => $f['pass']->token])->assertUnprocessable();
        $this->actingAs($agent)->postJson('/api/v1/boarding', ['flight_id' => $id, 'token' => $newPass->token])->assertOk();
        $this->assertSame(1, DB::connection('finance')->table('payments')->count());
        $this->assertSame(1, DB::table('tickets')->count());
    }

    public function test_insufficient_aircraft_rejects_and_preserves_old_seat_qr_and_bookings(): void
    {
        $f = $this->fixture(6);
        for ($i = 0; $i < 6; $i++) {
            app(BookingService::class)->hold($f['passenger']->id, $f['f']->id, 'extra-seat-'.$i);
        }
        $p = $this->propose($f);
        $this->approve($f, $p);
        $this->drain();
        $this->assertDatabaseHas('flight_changes', ['id' => $p->id, 'status' => 'FAILED'], 'ops');
        $this->assertDatabaseHas('flights', ['id' => $f['f']->id, 'aircraft_id' => $f['old'], 'status' => 'SCHEDULED'], 'ops');
        $this->assertDatabaseHas('inventory', ['flight_id' => $f['f']->id, 'capacity' => 18, 'status' => 'OPEN']);
        $this->assertDatabaseHas('bookings', ['id' => $f['booking']->id, 'seat' => '3F', 'status' => 'CHECKED_IN']);
        $this->assertSame($f['pass']->token, app(BoardingService::class)->pass($f['booking']->id, $f['passenger']->id)->token);
        $this->assertSame(0, DB::table('seat_reassignments')->count());
        $this->assertSame(7, DB::table('bookings')->where('flight_id', $f['f']->id)->count());
    }

    public function test_approval_roles_scope_staleness_and_rejection(): void
    {
        $f = $this->fixture();
        $p = $this->propose($f);
        $this->actingAs($f['passenger'])->postJson('/api/v1/actions/resource_decide', ['change_id' => $p->id, 'approve' => true])->assertForbidden();
        $this->actingAs($this->user('ops_manager', 'SGN'))->postJson('/api/v1/actions/resource_decide', ['change_id' => $p->id, 'approve' => true])->assertForbidden();
        $f['ops']->forceFill(['role' => 'ops_manager'])->save();
        $this->actingAs($f['ops'])->postJson('/api/v1/actions/resource_decide', ['change_id' => $p->id, 'approve' => true])->assertConflict();
        DB::connection('ops')->table('flights')->where('id', $f['f']->id)->increment('version');
        $this->actingAs($f['manager'])->postJson('/api/v1/actions/resource_decide', ['change_id' => $p->id, 'approve' => true])->assertConflict();
        $this->actingAs($f['manager'])->postJson('/api/v1/actions/resource_decide', ['change_id' => $p->id, 'approve' => false])->assertOk();
        $this->assertSame(0, DB::connection('ops')->table('change_reservations')->count());
    }

    public function test_reserved_planes_block_maintenance_master_edit_and_publish(): void
    {
        $f = $this->fixture();
        $p = $this->propose($f);
        $this->approve($f, $p);
        $this->actingAs($this->user('maintenance'))->postJson('/api/v1/actions/work_order', ['aircraft_id' => $f['new'], 'title' => 'Không được khóa tàu đang giữ', 'due_at' => now()->addDay()->toIso8601String()])->assertConflict();
        $master = app(MasterDataService::class)->propose($f['fleet'], ['entity' => 'aircraft', 'record_id' => (string) $f['new'], 'changes' => ['type' => 'A320', 'capacity' => 24], 'reason' => 'Sửa cấu hình khi tàu đang giữ']);
        $this->actingAs($this->user('fleet_manager'))->postJson('/api/v1/actions/master_approve', ['change_id' => $master->id])->assertConflict();
        $svc = app(OperationsService::class);
        $other = $svc->execute($f['ops'], 'schedule', ['number' => 'AS7900', 'route_id' => 1, 'aircraft_id' => $f['new'], 'departure' => $f['departure']->toIso8601String(), 'arrival' => $f['departure']->copy()->addHours(2)->toIso8601String(), 'sale_price' => 2000000, 'gate' => 'A2']);
        $svc->execute($f['ops'], 'submit', ['flight_id' => $other->id]);
        $this->actingAs($f['manager'])->postJson('/api/v1/actions/publish', ['flight_id' => $other->id])->assertConflict()->assertJsonPath('message', 'Tàu đang được giữ cho phương án đổi tàu.');
        $this->actingAs($f['ops'])->postJson('/api/v1/actions/disruption', ['flight_id' => $f['f']->id, 'type' => 'CANCEL', 'delay_minutes' => 0, 'reason' => 'Không hủy chồng phương án đang chạy'])->assertConflict();
    }

    public function test_replay_does_not_rotate_qr_again_and_preserves_manual_sales_closure(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->user('data_admin'))->postJson('/api/v1/actions/fare', ['flight_id' => $f['f']->id, 'price' => 1800000, 'capacity' => 18, 'status' => 'CLOSED', 'reason' => 'Tạm đóng bán có chủ đích'])->assertOk();
        $p = $this->propose($f);
        $this->approve($f, $p);
        $this->drain();
        $pass = app(BoardingService::class)->pass($f['booking']->id, $f['passenger']->id);
        $this->assertDatabaseHas('inventory', ['flight_id' => $f['f']->id, 'status' => 'CLOSED', 'manual_closed' => 1, 'capacity' => 12]);
        foreach (['ops', 'mysql'] as $n) {
            DB::connection($n)->table('outbox')->update(['delivered_at' => null]);
        }
        $this->drain();
        $this->assertSame($pass->token, app(BoardingService::class)->pass($f['booking']->id, $f['passenger']->id)->token);
        $this->assertSame(1, DB::table('seat_reassignments')->count());
    }

    public function test_commerce_outage_then_retry_completes_reserved_change(): void
    {
        $f = $this->fixture();
        $p = $this->propose($f);
        $this->approve($f, $p);
        $config = config('database.connections.mysql');
        config(['database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 1]);
        DB::purge('mysql');
        try {
            $this->assertGreaterThan(0, app(OutboxRelay::class)->drain());
        } finally {
            config(['database.connections.mysql' => $config]);
            DB::purge('mysql');
        }
        $this->assertDatabaseHas('flights', ['id' => $f['f']->id, 'status' => 'CHANGE_PENDING'], 'ops');
        DB::connection('ops')->table('outbox')->update(['available_at' => now()->subSecond()]);
        $this->drain();
        $this->assertDatabaseHas('flight_changes', ['id' => $p->id, 'status' => 'COMPLETED'], 'ops');
        $this->assertDatabaseHas('inventory', ['flight_id' => $f['f']->id, 'frozen_change_id' => null]);
    }

    public function test_operations_outage_while_stock_frozen_recovers_without_expiry_deadlock(): void
    {
        $f = $this->fixture();
        $p = $this->propose($f);
        $this->approve($f, $p);
        $this->drain(1);
        $config = config('database.connections.ops');
        config(['database.connections.ops.host' => '127.0.0.1', 'database.connections.ops.port' => 1]);
        DB::purge('ops');
        try {
            app(BookingService::class)->expire();
            $this->assertGreaterThan(0, app(OutboxRelay::class)->drain());
        } finally {
            config(['database.connections.ops' => $config]);
            DB::purge('ops');
        }
        DB::connection('ops')->table('outbox')->update(['available_at' => now()->subSecond()]);
        DB::table('outbox')->update(['available_at' => now()->subSecond()]);
        $this->drain();
        $this->assertDatabaseHas('flight_changes', ['id' => $p->id, 'status' => 'COMPLETED'], 'ops');
    }

    public function test_crew_replacement_revalidates_rules_keeps_history_and_invalidates_stale_plan(): void
    {
        $f = $this->fixture();
        $new = app(OperationsService::class)->execute($f['scheduler'], 'crew', ['code' => 'REPL-PILOT', 'name' => 'Phi công thay thế', 'position' => 'PILOT', 'qualification' => 'A320', 'license_expires' => now()->addYear()->toDateString()]);
        $svc = app(FlightChangeService::class);
        $data = ['flight_id' => $f['f']->id, 'kind' => 'CREW', 'crew_ids' => $new->id.',1002', 'reason' => 'Thay phi công dự bị đủ năng định'];
        $p = $svc->propose($f['scheduler'], $data);
        $stale = $svc->propose($f['scheduler'], $data);
        $this->actingAs($f['manager'])->postJson('/api/v1/actions/resource_decide', ['change_id' => $p->id, 'approve' => true])->assertOk()->assertJsonPath('status', 'COMPLETED');
        $this->assertDatabaseHas('crew_assignments', ['flight_id' => $f['f']->id, 'crew_member_id' => 1001, 'active' => 0], 'ops');
        $this->assertDatabaseHas('crew_assignments', ['flight_id' => $f['f']->id, 'crew_member_id' => $new->id, 'active' => 1], 'ops');
        $this->assertSame($f['pass']->token, app(BoardingService::class)->pass($f['booking']->id, $f['passenger']->id)->token);
        $this->actingAs($f['manager'])->postJson('/api/v1/actions/resource_decide', ['change_id' => $stale->id, 'approve' => true])->assertConflict();
        $this->actingAs($f['passenger'])->getJson('/api/v1/flight-changes/'.$p->id)->assertForbidden();
        $this->actingAs($f['ops'])->get('/workspace/recovery/'.$p->id)->assertOk();
    }

    public function test_invalid_crew_and_boarded_passenger_prevent_unsafe_change(): void
    {
        $f = $this->fixture();
        $svc = app(FlightChangeService::class);
        $p = $svc->propose($f['scheduler'], ['flight_id' => $f['f']->id, 'kind' => 'CREW', 'crew_ids' => '1002', 'reason' => 'Thiếu vị trí phi công']);
        $this->actingAs($f['manager'])->postJson('/api/v1/actions/resource_decide', ['change_id' => $p->id, 'approve' => true])->assertConflict();
        DB::table('bookings')->where('id', $f['booking']->id)->update(['status' => 'BOARDED']);
        $p = $this->propose($f);
        $this->approve($f, $p);
        $this->drain();
        $this->assertDatabaseHas('flight_changes', ['id' => $p->id, 'status' => 'FAILED'], 'ops');
        $this->assertSame($f['pass']->token, app(BoardingService::class)->pass($f['booking']->id, $f['passenger']->id)->token);
    }

    public function test_valid_seats_are_preserved_before_remapping_and_fare_quotas_clamp(): void
    {
        $f = $this->fixture();
        $second = app(BookingService::class)->hold($f['passenger']->id, $f['f']->id, 'second-valid-seat');
        app(BookingService::class)->pay($second->id, $f['passenger']->id, 'approve');
        $this->drain(1);
        $old = app(BoardingService::class)->checkin($second->id, $f['passenger']->id, '1A');
        DB::table('fare_classes')->insert(['flight_id' => $f['f']->id, 'code' => 'FLEX', 'name' => 'Linh hoạt', 'quota' => 18, 'price' => 1900000, 'active' => true]);
        $p = $this->propose($f);
        $this->approve($f, $p);
        $this->drain();
        $this->assertDatabaseHas('bookings', ['id' => $second->id, 'seat' => '1A']);
        $this->assertDatabaseHas('bookings', ['id' => $f['booking']->id, 'seat' => '1B']);
        $this->assertSame(2, DB::table('seat_reassignments')->count());
        $this->assertDatabaseHas('fare_classes', ['flight_id' => $f['f']->id, 'code' => 'FLEX', 'quota' => 12, 'price' => 1900000]);
        $this->assertNotSame($old->token, app(BoardingService::class)->pass($second->id, $f['passenger']->id)->token);
    }

    public function test_pending_payment_can_resume_after_change_without_duplicate_charge(): void
    {
        $f = $this->fixture();
        $this->freezeTime();
        $second = app(BookingService::class)->hold($f['passenger']->id, $f['f']->id, 'pending-capture-swap');
        app(BookingService::class)->pay($second->id, $f['passenger']->id, 'approve');
        $p = $this->propose($f);
        $this->approve($f, $p);
        $this->assertGreaterThan(0, app(OutboxRelay::class)->drain());
        $this->assertDatabaseHas('bookings', ['id' => $second->id, 'status' => 'PAYMENT_PENDING']);
        app(BookingService::class)->expire();
        $this->drain(2);
        $this->travel(3)->seconds();
        $this->drain(1);
        $this->assertDatabaseHas('bookings', ['id' => $second->id, 'status' => 'CONFIRMED']);
        $this->assertSame(2, DB::connection('finance')->table('payments')->count());
        $this->assertSame(2, DB::table('tickets')->count());
        $this->travelBack();
    }

    public function test_existing_disruption_cannot_overwrite_a_change_in_progress(): void
    {
        $f = $this->fixture();
        $plan = app(OperationsService::class)->execute($f['ops'], 'disruption', ['flight_id' => $f['f']->id, 'type' => 'CANCEL', 'delay_minutes' => 0, 'reason' => 'Kế hoạch hủy đã lập trước']);
        $p = $this->propose($f);
        $this->approve($f, $p);
        $this->actingAs($f['manager'])->postJson('/api/v1/actions/approve_disruption', ['disruption_id' => $plan->id])->assertConflict();
        $this->assertDatabaseHas('flights', ['id' => $f['f']->id, 'pending_change_id' => $p->id, 'status' => 'CHANGE_PENDING'], 'ops');
        $this->drain();
    }
}
