<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Boarding\SeatMap;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Operations\OperationsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CabinConfigurationTest extends TestCase
{
    public function test_fleet_can_create_aircraft_with_type_specific_layouts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'fleet_manager']));
        foreach (['ATR72' => 68, 'A320' => 180, 'B787' => 294] as $type => $capacity) {
            $id = $this->postJson('/api/v1/aircraft', ['registration' => 'VN-NATIVE-'.$type, 'type' => $type, 'capacity' => $capacity, 'layout_preset' => 'AUTO'])->assertSuccessful()->json('id');
            $plane = DB::connection('ops')->table('aircraft')->find($id);
            $this->assertCount($capacity, SeatMap::seats($capacity, $plane->seat_layout));
        }
    }

    public function test_cabin_revision_requires_independent_approval_and_published_flights_keep_their_snapshot(): void
    {
        $fleet = User::factory()->create(['role' => 'fleet_manager']);
        $reviewer = User::factory()->create(['role' => 'fleet_manager']);
        $svc = app(OperationsService::class);
        $plane = $svc->execute($fleet, 'aircraft', ['registration' => 'VN-CABIN', 'type' => 'A320', 'capacity' => 180]);
        $this->actingAs($fleet)->get('/workspace/fleet-cabin?aircraft_id='.$plane->id.'&layout_preset=AUTO')->assertOk()->assertSee('Thương gia')->assertSee('Bản xem trước');
        $change = $this->postJson('/workspace/fleet-cabin', ['aircraft_id' => $plane->id, 'layout_preset' => 'AUTO', 'reason' => 'Chia khoang trước khi công bố lịch'])->assertCreated()->json('id');
        $this->postJson('/api/v1/actions/master_approve', ['change_id' => $change])->assertConflict();
        $this->actingAs($reviewer)->postJson('/api/v1/actions/master_approve', ['change_id' => $change])->assertSuccessful();
        $this->assertNotNull(DB::connection('ops')->table('aircraft')->where('id', $plane->id)->value('seat_layout'));
        $ops = User::factory()->create(['role' => 'operations', 'airport_code' => 'HAN']);
        $manager = User::factory()->create(['role' => 'ops_manager', 'airport_code' => 'HAN']);
        $scheduler = User::factory()->create(['role' => 'crew_scheduler']);
        $route = DB::connection('ops')->table('routes')->where('origin', 'HAN')->where('destination', 'SGN')->first();
        $f = $svc->execute($ops, 'schedule', ['number' => 'AS6767', 'route_id' => $route->id, 'aircraft_id' => $plane->id, 'departure' => now()->addHours(3)->toIso8601String(), 'arrival' => now()->addHours(5)->toIso8601String(), 'sale_price' => 1500000, 'gate' => 'A2']);
        foreach ([1001, 1002] as $id) {
            $svc->execute($scheduler, 'assign_crew', ['flight_id' => $f->id, 'crew_member_id' => $id]);
        }
        $svc->execute($ops, 'submit', ['flight_id' => $f->id]);
        $svc->execute($manager, 'publish', ['flight_id' => $f->id]);
        $this->assertSame(0, app(OutboxRelay::class)->drain());
        $snapshot = DB::table('inventory')->where('flight_id', $f->id)->value('seat_layout');
        $this->assertTrue(SeatMap::contains('1A', 180, $snapshot, 'BUSINESS'));
        $this->assertFalse(SeatMap::contains('1A', 180, $snapshot, 'ECONOMY'));
        $change = $this->actingAs($fleet)->postJson('/workspace/fleet-cabin', ['aircraft_id' => $plane->id, 'layout_preset' => 'REGIONAL', 'reason' => 'Thử chặn thay cấu hình đang khai thác'])->assertCreated()->json('id');
        $this->actingAs($reviewer)->postJson('/api/v1/actions/master_approve', ['change_id' => $change])->assertConflict();
        $this->assertSame($snapshot, DB::table('inventory')->where('flight_id', $f->id)->value('seat_layout'));
        $this->actingAs(User::factory()->create())->get('/workspace/fleet-cabin')->assertForbidden();
    }

    public function test_invalid_custom_layout_is_rejected_before_a_revision_is_stored(): void
    {
        $fleet = User::factory()->create(['role' => 'fleet_manager']);
        $plane = app(OperationsService::class)->execute($fleet, 'aircraft', ['registration' => 'VN-LAYOUT', 'type' => 'A320', 'capacity' => 180]);
        $this->actingAs($fleet)->postJson('/api/v1/actions/master_propose', ['entity' => 'aircraft', 'record_id' => (string) $plane->id, 'reason' => 'Kiểm tra sơ đồ không hợp lệ', 'changes' => ['type' => 'A320', 'capacity' => 180, 'seat_layout' => ['cabins' => [['code' => 'ECONOMY', 'rows' => 30, 'blocks' => ['AAA', 'DEF']]]]]])->assertUnprocessable();
        $this->assertSame(0, DB::connection('ops')->table('master_changes')->count());
    }
}
