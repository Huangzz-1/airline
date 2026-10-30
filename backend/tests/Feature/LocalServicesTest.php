<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Booking\BookingService;
use App\Modules\Customer\EmailVerification;
use App\Modules\Finance\CheckoutService;
use App\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalServicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.mode' => 'mock', 'payments.vnpay.enabled' => true, 'payments.vnpay.tmn_code' => 'LOCALTEST', 'payments.vnpay.hash_secret' => Str::random(48), 'payments.momo.enabled' => true, 'payments.momo.partner_code' => 'LOCALPARTNER', 'payments.momo.access_key' => Str::random(32), 'payments.momo.secret_key' => Str::random(48), 'payments.bank.mode' => 'mock']);
    }

    private function user(): User
    {
        return User::factory()->create(['role' => 'passenger']);
    }

    private function checkout(User $u, string $method): object
    {
        $b = app(BookingService::class)->hold($u->id, 1, (string) Str::uuid());
        $this->actingAs($u)->post('/journeys/'.$b->id.'/pay', ['method' => $method])->assertRedirect();

        return DB::table('checkout_sessions')->where('booking_id', $b->id)->first();
    }

    public function test_otp_limits_attempts_resend_invalidates_old_code_and_success_is_once(): void
    {
        Notification::fake();
        $u = User::factory()->unverified()->create();
        $svc = app(EmailVerification::class);
        $this->assertTrue($svc->issue($u));
        $code = Notification::sent($u, VerifyEmail::class)->last()->code;
        $row = DB::table('email_verifications')->where('user_id', $u->id)->first();
        $this->assertTrue(Hash::check($code, $row->code_hash));
        $this->assertNotSame($code, $row->code_hash);
        $this->assertFalse($svc->issue($u));
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($svc->verify($u, '000000'));
        }
        $this->assertFalse($svc->verify($u, $code));
        $this->assertNull($u->fresh()->email_verified_at);
        $this->travel(61)->seconds();
        $this->assertTrue($svc->issue($u));
        $new = Notification::sent($u, VerifyEmail::class)->last()->code;
        $this->assertFalse($svc->verify($u, $code));
        $this->assertTrue($svc->verify($u, $new));
        $this->assertNotNull($u->fresh()->email_verified_at);
        $this->assertSame(0, DB::table('email_verifications')->count());
        $this->assertTrue($svc->verify($u, $new));
        $this->travelBack();
    }

    public function test_expired_otp_cannot_verify_and_code_is_not_exposed_by_verification_page(): void
    {
        Notification::fake();
        $u = User::factory()->unverified()->create();
        $svc = app(EmailVerification::class);
        $svc->issue($u);
        $code = Notification::sent($u, VerifyEmail::class)->last()->code;
        $this->actingAs($u)->get('/email/verify')->assertOk()->assertSee('Mã xác minh')->assertDontSee($code);
        $this->travel(11)->minutes();
        $this->post('/email/verify-otp', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull($u->fresh()->email_verified_at);
        $this->travelBack();
    }

    public function test_local_vnpay_uses_owned_intent_server_amount_and_idempotent_signed_result(): void
    {
        $u = $this->user();
        $s = $this->checkout($u, 'VNPAY');
        $this->get('/payments/mock/'.$s->intent_id)->assertOk()->assertSee('Thử nghiệm');
        $this->actingAs($this->user())->get('/payments/mock/'.$s->intent_id)->assertNotFound();
        $this->actingAs($u)->post('/payments/mock/'.$s->intent_id, ['result' => 'approve', 'amount' => 1])->assertRedirect('/journeys/'.$s->booking_id);
        $this->post('/payments/mock/'.$s->intent_id, ['result' => 'approve'])->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $s->booking_id, 'status' => 'CONFIRMED']);
        $this->assertSame(1, DB::table('tickets')->count());
        $this->assertSame(1290000, DB::connection('finance')->table('payments')->value('amount'));
        $this->assertSame('mock', DB::connection('finance')->table('payments')->value('mode'));
        $this->post('/payments/mock/'.$s->intent_id, ['result' => 'decline'])->assertConflict();
    }

    public function test_local_momo_decline_compensates_and_success_issues_a_ticket(): void
    {
        $u = $this->user();
        $s = $this->checkout($u, 'MOMO');
        $this->post('/payments/mock/'.$s->intent_id, ['result' => 'decline'])->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $s->booking_id, 'status' => 'CANCELLED']);
        $this->assertSame(0, DB::table('tickets')->count());
        $next = $this->checkout($u, 'MOMO');
        $this->post('/payments/mock/'.$next->intent_id, ['result' => 'approve'])->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $next->booking_id, 'status' => 'CONFIRMED']);
        $this->assertSame(1, DB::table('tickets')->count());
        $this->assertSame(1, DB::connection('finance')->table('ledger_entries')->count());
    }

    public function test_production_cannot_open_mock_routes_or_offer_mock_payment_methods(): void
    {
        $u = $this->user();
        $s = $this->checkout($u, 'VNPAY');
        app()->instance('env', 'production');
        $this->get('/payments/mock/'.$s->intent_id)->assertNotFound();
        $token = Str::random(40);
        $this->withSession(['_token' => $token])->post('/payments/mock/'.$s->intent_id, ['result' => 'approve', '_token' => $token])->assertNotFound();
        $this->assertSame([], app(CheckoutService::class)->methods());
        $this->assertSame(0, DB::table('tickets')->count());
    }

    public function test_local_admin_bootstrap_preserves_existing_admin_and_cannot_run_in_production(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $hash = $admin->password;
        config(['airline.bootstrap_admin' => true, 'airline.admin_email' => 'local-admin@example.test', 'airline.admin_password' => Str::random(24)]);
        app()->instance('env', 'local');
        $this->artisan('airline:bootstrap-local')->assertSuccessful();
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertSame(1, User::where('role', 'admin')->count());
        app()->instance('env', 'production');
        $this->artisan('airline:bootstrap-local')->assertSuccessful();
        $this->assertSame(1, User::where('role', 'admin')->count());
    }
}
