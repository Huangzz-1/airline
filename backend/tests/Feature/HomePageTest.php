<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Booking\BookingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_passengers_land_on_home_while_staff_keep_their_workspace(): void
    {
        $passenger = User::factory()->create(['password' => 'HomePassword2026!']);
        $this->post('/login', ['email' => $passenger->email, 'password' => 'HomePassword2026!'])->assertRedirect('/home');
        $this->get('/home')->assertOk()->assertSee($passenger->name);
        $this->post('/logout');
        $staff = User::factory()->create(['role' => 'admin', 'password' => 'HomePassword2026!']);
        $this->post('/login', ['email' => $staff->email, 'password' => 'HomePassword2026!'])->assertRedirect('/staff');
        $this->get('/home')->assertRedirect('/staff');
    }

    public function test_home_shows_only_the_owners_live_upcoming_bookings(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = app(BookingService::class);
        $private = $service->hold($other->id, 1, 'other-home-booking');
        $this->actingAs($owner)->get('/home')->assertOk()
            ->assertViewHas('upcoming', null)->assertViewHas('personal', ['trips' => 0, 'notifications' => 0])
            ->assertDontSee($private->reference);
        $own = $service->hold($owner->id, 1, 'own-home-booking');
        $this->get('/home')->assertOk()->assertSee($own->reference)->assertDontSee($private->reference)
            ->assertViewHas('personal', ['trips' => 1, 'notifications' => 0]);
        DB::table('bookings')->where('id', $own->id)->update(['expires_at' => now()->subMinute()]);
        $this->get('/home')->assertOk()->assertViewHas('upcoming', null)
            ->assertViewHas('personal', ['trips' => 0, 'notifications' => 0])->assertDontSee($own->reference);
    }

    public function test_previous_journeys_destination_does_not_override_home_after_login(): void
    {
        $passenger = User::factory()->create(['password' => 'HomePassword2026!']);
        $this->withSession(['url.intended' => 'http://localhost/journeys'])
            ->post('/login', ['email' => $passenger->email, 'password' => 'HomePassword2026!'])
            ->assertRedirect('/home')->assertSessionMissing('url.intended');
    }

    public function test_otp_verification_also_lands_on_home(): void
    {
        $passenger = User::factory()->unverified()->create();
        DB::table('email_verifications')->insert(['user_id' => $passenger->id, 'code_hash' => Hash::make('123456'), 'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($passenger)->withSession(['url.intended' => '/journeys'])
            ->post('/email/verify-otp', ['code' => '123456'])->assertRedirect('/home');
        $this->assertNotNull($passenger->fresh()->email_verified_at);
    }

    public function test_email_link_opened_while_logged_out_is_verified_then_lands_on_home(): void
    {
        $passenger = User::factory()->unverified()->create(['password' => 'HomePassword2026!']);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), ['id' => $passenger->id, 'hash' => sha1($passenger->email)]);
        $this->get($url)->assertRedirect('/login');
        $this->post('/login', ['email' => $passenger->email, 'password' => 'HomePassword2026!'])->assertRedirect($url);
        $this->get($url)->assertRedirect('/home');
        $this->assertNotNull($passenger->fresh()->email_verified_at);
    }

    public function test_guests_can_see_home_without_private_trip_data(): void
    {
        $this->get('/home')->assertOk()->assertSee('/images/aviation-home-hero.png')
            ->assertSee('Bạn muốn bay tới đâu?')->assertViewHas('upcoming', null)
            ->assertSee('Số liệu từ lịch bay và mạng đường bay hiện có của hệ thống.');
    }
}
