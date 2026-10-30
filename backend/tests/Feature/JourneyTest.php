<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Boarding\BoardingService;
use App\Modules\Booking\BookingService;
use App\Modules\Finance\OutboxRelay;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class JourneyTest extends TestCase
{
    private function passenger(): User
    {
        return User::factory()->create();
    }

    private function booked(User $u): object
    {
        $svc = app(BookingService::class);
        $b = $svc->hold($u->id, 1, 'key-'.bin2hex(random_bytes(8)));
        $svc->pay($b->id, $u->id, 'approve');
        $this->assertSame(0, app(OutboxRelay::class)->drain());

        return DB::table('bookings')->find($b->id);
    }

    public function test_three_nodes_are_independent_and_own_the_correct_tables(): void
    {
        $uuids = [];
        foreach (['ops' => 'flights', 'mysql' => 'bookings', 'finance' => 'payments'] as $node => $table) {
            $uuids[] = DB::connection($node)->selectOne('SELECT @@server_uuid AS uuid')->uuid;
            $this->assertTrue(Schema::connection($node)->hasTable($table));
        }
        $this->assertCount(3, array_unique($uuids));
        $this->assertFalse(Schema::connection('mysql')->hasTable('payments'));
        $this->assertFalse(Schema::connection('finance')->hasTable('bookings'));
    }

    public function test_test_database_identity_cannot_read_demo_schema(): void
    {
        try {
            DB::select('SELECT COUNT(*) FROM airline.users');
            $this->fail('Test identity must not access the application database');
        } catch (QueryException $e) {
            $this->assertSame(1142, $e->errorInfo[1]);
        }
    }

    public function test_registration_cannot_grant_admin_and_verification_is_required(): void
    {
        Notification::fake();
        $this->post('/register', ['name' => 'Nguyễn An', 'email' => 'an@example.test', 'password' => 'ExamplePassword10!', 'password_confirmation' => 'ExamplePassword10!', 'consent' => 1, 'role' => 'admin'])->assertRedirect('/email/verify');
        $u = User::where('email', 'an@example.test')->firstOrFail();
        $this->assertSame('passenger', $u->role);
        $this->postJson('/api/v1/bookings', ['flight_id' => 1, 'idempotency_key' => 'unverified-key'])->assertForbidden();
        Notification::assertSentTo($u, VerifyEmail::class);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $u->id, 'hash' => sha1($u->email)]);
        $this->get($url)->assertRedirect('/journeys');
        $this->assertNotNull($u->fresh()->email_verified_at);
    }

    public function test_full_journey_sandbox_checkin_qr_and_duplicate_boarding(): void
    {
        $u = $this->passenger();
        $this->actingAs($u);
        $id = $this->postJson('/api/v1/bookings', ['flight_id' => 1, 'idempotency_key' => 'full-journey-key'])->assertCreated()->json('id');
        $this->postJson("/api/v1/bookings/$id/pay", ['scenario' => 'approve'])->assertOk()->assertJsonPath('status', 'CONFIRMED');
        $this->postJson("/api/v1/bookings/$id/pay", ['scenario' => 'approve'])->assertOk();
        $this->assertSame(1, DB::connection('finance')->table('payments')->count());
        $this->assertSame(1, DB::table('tickets')->count());
        $pass = $this->postJson("/api/v1/bookings/$id/checkin", ['seat' => '1A'])->assertOk()->json();
        $this->assertSame(64, strlen($pass['token']));
        $this->assertStringNotContainsString($u->email, $pass['token']);
        $this->get("/journeys/$id")->assertOk()->assertSee('<svg', false);
        $agent = User::factory()->create(['role' => 'airport_agent', 'airport_code' => 'HAN']);
        $this->actingAs($agent);
        $this->postJson('/api/v1/boarding', ['flight_id' => 1, 'token' => $pass['token']])->assertOk()->assertJsonPath('status', 'BOARDED');
        $this->postJson('/api/v1/boarding', ['flight_id' => 1, 'token' => $pass['token']])->assertConflict();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'boarding.accepted')->count());
    }

    public function test_ownership_and_role_boundaries(): void
    {
        $u = $this->passenger();
        $b = $this->booked($u);
        $other = $this->passenger();
        $this->actingAs($other);
        $this->getJson('/api/v1/bookings/'.$b->id)->assertNotFound();
        $this->postJson('/api/v1/bookings/'.$b->id.'/pay', ['scenario' => 'approve'])->assertNotFound();
        $this->postJson('/api/v1/bookings/'.$b->id.'/checkin', ['seat' => '1A'])->assertNotFound();
        $this->postJson('/api/v1/boarding', ['flight_id' => 1, 'token' => str_repeat('a', 64)])->assertForbidden();
        $this->get('/staff')->assertForbidden();
        $this->post('/admin/users/'.$u->id.'/role', ['role' => 'admin'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/api/v1/boarding', ['flight_id' => 1, 'token' => str_repeat('a', 64)])->assertForbidden();
    }

    public function test_hold_idempotency_expiry_and_cancellation_release_inventory(): void
    {
        $u = $this->passenger();
        $svc = app(BookingService::class);
        DB::table('inventory')->where('flight_id', 1)->update(['capacity' => 1]);
        $b = $svc->hold($u->id, 1, 'same-request');
        $again = $svc->hold($u->id, 1, 'same-request');
        $this->assertSame($b->id, $again->id);
        $this->travel(11)->minutes();
        $svc->expire();
        $svc->expire();
        $this->assertSame('EXPIRED', DB::table('bookings')->find($b->id)->status);
        $b2 = $svc->hold($u->id, 1, 'new-request');
        $svc->cancel($b2->id, $u->id);
        $svc->cancel($b2->id, $u->id);
        $this->assertNotEmpty($svc->hold($u->id, 1, 'third-request')->id);
        $this->travelBack();
    }

    public function test_last_seat_has_exactly_one_winner_across_two_processes(): void
    {
        $a = $this->passenger();
        $b = $this->passenger();
        DB::table('inventory')->where('flight_id', 1)->update(['capacity' => 1]);
        $start = microtime(true) + 1;
        $processes = [];
        foreach ([$a, $b] as $u) {
            $p = new Process([PHP_BINARY, base_path('tests/hold-worker.php'), (string) $u->id, (string) $start], base_path(), ['DB_DATABASE' => 'airline_test', 'APP_ENV' => 'testing']);
            $p->start();
            $processes[] = $p;
        }
        $results = [];
        foreach ($processes as $p) {
            $p->wait();
            $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
            $results[] = trim($p->getOutput());
        }
        sort($results);
        $this->assertSame(['409', 'HELD'], $results);
        $this->assertSame(1, DB::table('bookings')->where('status', 'HELD')->count());
    }

    public function test_declined_payment_compensates_without_ticket(): void
    {
        $u = $this->passenger();
        $svc = app(BookingService::class);
        DB::table('inventory')->where('flight_id', 1)->update(['capacity' => 1]);
        $b = $svc->hold($u->id, 1, 'decline-request');
        $svc->pay($b->id, $u->id, 'decline');
        app(OutboxRelay::class)->drain();
        $this->assertSame('CANCELLED', DB::table('bookings')->find($b->id)->status);
        $this->assertSame(0, DB::table('tickets')->count());
        $this->assertNotEmpty($svc->hold($u->id, 1, 'after-decline')->id);
    }

    public function test_finance_network_failure_keeps_pending_then_retries_once(): void
    {
        $u = $this->passenger();
        $svc = app(BookingService::class);
        $b = $svc->hold($u->id, 1, 'outage-request');
        $svc->pay($b->id, $u->id, 'approve');
        $original = config('database.connections.finance');
        config(['database.connections.finance.host' => '127.0.0.1', 'database.connections.finance.port' => 1]);
        DB::purge('finance');
        try {
            $this->assertGreaterThan(0, app(OutboxRelay::class)->drain());
        } finally {
            config(['database.connections.finance' => $original]);
            DB::purge('finance');
        }
        $this->assertSame('PAYMENT_PENDING', DB::table('bookings')->find($b->id)->status);
        $this->assertSame(0, DB::table('tickets')->count());
        DB::table('outbox')->update(['available_at' => now()->subSecond()]);
        $this->assertSame(0, app(OutboxRelay::class)->drain());
        // Simulate crash after remote commit but before local acknowledgement.
        DB::table('outbox')->update(['delivered_at' => null]);
        DB::connection('finance')->table('outbox')->update(['delivered_at' => null]);
        $this->assertSame(0, app(OutboxRelay::class)->drain());
        $this->assertSame('CONFIRMED', DB::table('bookings')->find($b->id)->status);
        $this->assertSame(1, DB::connection('finance')->table('payments')->count());
        $this->assertSame(1, DB::table('tickets')->count());
    }

    public function test_checkin_rejects_unpaid_and_taken_seat(): void
    {
        $u = $this->passenger();
        $b = app(BookingService::class)->hold($u->id, 1, 'unpaid-request');
        $this->actingAs($u);
        $this->postJson('/api/v1/bookings/'.$b->id.'/checkin', ['seat' => '1A'])->assertConflict();
        $paid = $this->booked($u);
        $this->postJson('/api/v1/bookings/'.$paid->id.'/checkin', ['seat' => '99A'])->assertUnprocessable();
        $this->postJson('/api/v1/bookings/'.$paid->id.'/checkin', ['seat' => '1A'])->assertOk();
        $other = $this->passenger();
        $b2 = $this->booked($other);
        $this->actingAs($other)->postJson('/api/v1/bookings/'.$b2->id.'/checkin', ['seat' => '1A'])->assertConflict();
    }

    public function test_qr_expiry_wrong_flight_revocation_and_airport_scope(): void
    {
        $u = $this->passenger();
        $b = $this->booked($u);
        $pass = app(BoardingService::class)->checkin($b->id, $u->id, '2B');
        $agent = User::factory()->create(['role' => 'airport_agent', 'airport_code' => 'SGN']);
        $this->actingAs($agent);
        $this->postJson('/api/v1/boarding', ['flight_id' => 1, 'token' => $pass->token])->assertForbidden();
        $agent->forceFill(['airport_code' => 'HAN'])->save();
        $this->postJson('/api/v1/boarding', ['flight_id' => 1, 'token' => str_repeat('x', 64)])->assertUnprocessable();
        DB::connection('ops')->table('flights')->where('id', 2)->update(['departure' => now()->addMinutes(45), 'status' => 'BOARDING']);
        $this->postJson('/api/v1/boarding', ['flight_id' => 2, 'token' => $pass->token])->assertConflict();
        DB::table('boarding_passes')->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/v1/boarding', ['flight_id' => 1, 'token' => $pass->token])->assertConflict();
        DB::table('boarding_passes')->update(['expires_at' => now()->addMinutes(20), 'revoked_at' => now()]);
        $this->postJson('/api/v1/boarding', ['flight_id' => 1, 'token' => $pass->token])->assertConflict();
    }

    public function test_time_windows_are_enforced(): void
    {
        $u = $this->passenger();
        $b = $this->booked($u);
        $this->actingAs($u);
        DB::connection('ops')->table('flights')->where('id', 1)->update(['departure' => now()->addHours(25), 'status' => 'SCHEDULED']);
        $this->postJson('/api/v1/bookings/'.$b->id.'/checkin', ['seat' => '1A'])->assertConflict();
        DB::connection('ops')->table('flights')->where('id', 1)->update(['departure' => now()->addMinutes(29)]);
        $this->postJson('/api/v1/bookings/'.$b->id.'/checkin', ['seat' => '1A'])->assertConflict();
    }

    public function test_password_reset_is_one_time_and_login_logout_work(): void
    {
        Notification::fake();
        $u = $this->passenger();
        $this->post('/forgot-password', ['email' => $u->email])->assertRedirect();
        $token = null;
        Notification::assertSentTo($u, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $data = ['email' => $u->email, 'token' => $token, 'password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!'];
        $this->post('/reset-password', $data)->assertRedirect('/login');
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $u->email, 'password' => 'NewPassword2026!'])->assertRedirect('/journeys');
        $this->assertAuthenticatedAs($u);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_admin_role_changes_are_audited_and_finance_can_view_transactions(): void
    {
        $u = $this->passenger();
        $this->booked($u);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/staff')->assertOk();
        $this->post('/admin/users/'.$u->id.'/role', ['role' => 'airport_agent', 'airport_code' => 'BAD'])->assertUnprocessable();
        $this->post('/admin/users/'.$u->id.'/role', ['role' => 'finance'])->assertRedirect();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'user.role_changed')->count());
        $this->actingAs($u->fresh())->get('/staff')->assertOk()->assertSee('Khớp');
    }
}
