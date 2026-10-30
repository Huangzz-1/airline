<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Boarding\BoardingService;
use App\Modules\Booking\BookingService;
use App\Modules\Finance\OutboxRelay;
use App\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UsablePortalTest extends TestCase
{
    private function user(string $role = 'passenger', string $airport = 'HAN'): User
    {
        return User::factory()->create(['role' => $role, 'airport_code' => $airport]);
    }

    private function paid(User $u): object
    {
        $b = app(BookingService::class)->hold($u->id, 1, (string) Str::uuid());
        app(BookingService::class)->pay($b->id, $u->id, 'approve');
        app(OutboxRelay::class)->drain();

        return $b;
    }

    public function test_customer_documents_are_owned_and_only_valid_tickets_can_be_printed(): void
    {
        $u = $this->user();
        $b = $this->paid($u);
        $this->actingAs($u)->get('/journeys/'.$b->id.'/ticket')->assertOk()->assertSee($b->reference)->assertSee('Vé điện tử');
        $this->get('/journeys/'.$b->id.'/boarding-pass')->assertConflict();
        $this->actingAs($this->user())->get('/journeys/'.$b->id.'/ticket')->assertNotFound();
        $this->actingAs($this->user('airport_agent', 'SGN'))->get('/journeys/'.$b->id.'/ticket')->assertNotFound();
        $this->actingAs($this->user('finance'))->get('/journeys/'.$b->id.'/ticket')->assertNotFound();
        $pass = app(BoardingService::class)->checkin($b->id, $u->id, '1A');
        $this->actingAs($u)->get('/journeys/'.$b->id.'/boarding-pass')->assertOk()->assertSee('<svg', false)->assertSee('In / Lưu PDF')->assertDontSee($pass->token)->assertDontSee('Token mô phỏng');
        DB::table('boarding_passes')->where('booking_id', $b->id)->update(['revoked_at' => now()]);
        $this->get('/journeys/'.$b->id.'/boarding-pass')->assertConflict();
        DB::table('bookings')->where('id', $b->id)->update(['status' => 'REFUNDED']);
        $this->get('/journeys/'.$b->id.'/ticket')->assertConflict();
    }

    public function test_agent_search_and_checkin_obey_airport_scope_and_record_actual_actor(): void
    {
        $u = $this->user();
        $b = $this->paid($u);
        $agent = $this->user('airport_agent');
        $this->actingAs($this->user('airport_agent', 'SGN'))->get('/staff/passengers?q='.$b->reference)->assertOk()->assertDontSee($u->name)->assertSee('Không tìm thấy hành khách');
        $this->post('/staff/passengers/'.$b->id.'/checkin', ['seat' => '1A'])->assertNotFound();
        $this->actingAs($this->user())->get('/staff/passengers')->assertForbidden();
        $this->actingAs($agent)->get('/staff/passengers?q='.$b->reference)->assertOk()->assertSee($u->name)->assertSee($b->reference)->assertSee('Ghế còn trống');
        $this->post('/staff/passengers/'.$b->id.'/checkin', ['seat' => '1A'])->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $b->id, 'status' => 'CHECKED_IN', 'seat' => '1A']);
        $this->assertDatabaseHas('audit_logs', ['object_id' => $b->id, 'action' => 'boarding.checkin', 'actor_id' => $agent->id]);
        $this->get('/journeys/'.$b->id.'/boarding-pass')->assertOk()->assertSee($u->name);
    }

    public function test_customer_forms_use_owned_booking_choices_and_clean_business_content(): void
    {
        $u = $this->user();
        $b = $this->paid($u);
        $other = $this->paid($this->user());
        $page = $this->actingAs($u)->get('/workspace/customer')->assertOk()->assertSee($b->reference)->assertDontSee($other->reference)->assertDontSee('Booking UUID')->assertDontSee('M07/13');
        $this->get('/')->assertOk()->assertDontSee('MÔI TRƯỜNG MÔ PHỎNG');
        $this->get('/journeys/'.$b->id)->assertOk()->assertSee('Đã xuất vé')->assertSee('Xem / In vé điện tử')->assertDontSee('Token mô phỏng');
        $this->actingAs($this->user('operations'))->get('/workspace/scheduling')->assertOk()->assertSee('Chọn các chuyến')->assertDontSee('Danh sách ID cách nhau');
    }

    public function test_admin_provisions_employee_and_first_login_requires_password_change(): void
    {
        $payload = ['name' => 'Nhân viên mới', 'email' => 'employee@example.test', 'password' => 'TemporaryPassword2026!', 'password_confirmation' => 'TemporaryPassword2026!', 'role' => 'airport_agent', 'airport_code' => 'HAN'];
        $this->actingAs($this->user())->postJson('/admin/users', $payload)->assertForbidden();
        $admin = $this->user('admin');
        $this->actingAs($admin)->postJson('/admin/users', array_replace($payload, ['airport_code' => 'ZZZ']))->assertUnprocessable();
        $this->postJson('/admin/users', $payload)->assertCreated()->assertJsonMissingPath('password');
        $employee = User::where('email', $payload['email'])->firstOrFail();
        $this->assertTrue($employee->must_change_password);
        $this->assertTrue($employee->hasVerifiedEmail());
        $this->assertTrue(Hash::check($payload['password'], $employee->password));
        $this->actingAs($employee)->get('/staff')->assertRedirect('/profile');
        $this->getJson('/api/v1/workspace/baggage')->assertForbidden();
        $this->post('/profile/password', ['current_password' => $payload['password'], 'password' => 'PersonalPassword2026!', 'password_confirmation' => 'PersonalPassword2026!'])->assertRedirect();
        $this->assertFalse($employee->fresh()->must_change_password);
        $this->actingAs($employee->fresh())->get('/staff')->assertOk();
    }

    public function test_account_disable_revokes_access_and_can_be_reenabled_without_self_lockout(): void
    {
        $admin = $this->user('admin');
        $staff = $this->user('operations');
        $this->actingAs($admin)->postJson('/admin/users/'.$admin->id.'/access', ['active' => false])->assertConflict();
        $this->postJson('/admin/users/'.$staff->id.'/access', ['active' => false])->assertOk();
        $this->actingAs($staff->fresh())->get('/staff')->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs($admin)->postJson('/admin/users/'.$staff->id.'/access', ['active' => true])->assertOk();
        $this->actingAs($staff->fresh())->get('/staff')->assertOk()->assertSee('Bàn làm việc')->assertSee('Điều hành khai thác');
    }

    public function test_registration_queues_encrypted_email_without_waiting_for_smtp(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.invalid.example', 'queue.default' => 'sync']);
        $this->post('/register', ['name' => 'Khách đăng ký', 'email' => 'queued@example.test', 'password' => 'PersonalPassword2026!', 'password_confirmation' => 'PersonalPassword2026!', 'consent' => 1])->assertRedirect('/email/verify');
        $job = DB::table('jobs')->where('queue', 'mail')->first();
        $this->assertNotNull($job);
        $payload = json_decode($job->payload, true);
        $command = $payload['data']['command'];
        $this->assertStringNotContainsString('queued@example.test', $command);
        $this->assertStringContainsString(VerifyEmail::class, Crypt::decrypt($command));
        $this->assertNull(User::where('email', 'queued@example.test')->value('email_verified_at'));
    }

    public function test_notification_read_is_inline_and_owned(): void
    {
        $u = $this->user();
        $other = $this->user();
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'user_id' => $u->id, 'title' => 'Cập nhật hành trình', 'body' => 'Cửa lên tàu đã thay đổi.', 'created_at' => now()]);
        $this->actingAs($u)->get('/workspace/notifications')->assertOk()->assertSee('Đánh dấu đã đọc')->assertDontSee('ID thông báo');
        $this->actingAs($other)->postJson('/api/v1/actions/read_notice', ['notification_id' => $id])->assertNotFound();
        $this->actingAs($u)->post('/workspace/actions/read_notice', ['notification_id' => $id])->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }
}
