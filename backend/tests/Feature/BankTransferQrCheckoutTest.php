<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Booking\ReservationService;
use App\Modules\Finance\CheckoutService;
use App\Modules\Finance\OutboxRelay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\LegacyReservationFixture;
use Tests\TestCase;

class BankTransferQrCheckoutTest extends TestCase
{
    public function test_group_bank_qr_uses_snapshot_and_total_but_report_does_not_issue_tickets(): void
    {
        Http::preventStrayRequests();
        config(['payments.bank' => ['enabled' => true, 'mode' => 'live', 'name' => 'Test Bank', 'bin' => '970415', 'account_number' => '123456789012', 'account_name' => 'TEST RECEIVER']]);
        $user = User::factory()->create();
        $service = app(ReservationService::class);
        $reservation = LegacyReservationFixture::create($user, ['idempotency_key' => (string) Str::uuid(), 'trip_type' => 'ONE_WAY', 'passengers' => [['name' => 'Test Passenger One'], ['name' => 'Test Passenger Two']], 'segments' => [['flight_id' => 1, 'fare_class' => 'ECONOMY']]]);
        $session = $service->pay($user, $reservation->id, 'BANK', '127.0.0.1');
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, app(OutboxRelay::class)->drain());
        }
        $this->assertSame(1, DB::connection('finance')->table('checkout_intents')->where('reservation_id', $reservation->id)->count());
        $intent = DB::connection('finance')->table('checkout_intents')->find($session->intent_id);
        $this->assertSame((int) $reservation->total_amount, $intent->amount);
        $this->assertSame('970415', json_decode($intent->bank_details, true)['bin']);

        // Existing payment requests must keep the originally presented recipient.
        config(['payments.bank.account_number' => '999999999999', 'payments.bank.bin' => '970436']);
        $this->actingAs($user)->get('/reservations/'.$reservation->id)->assertOk()
            ->assertSee('data-bank-transfer-qr', false)->assertSee('<svg', false)
            ->assertSee('123456789012')->assertDontSee('999999999999')->assertSee('AS '.$reservation->reference)
            ->assertSee(number_format($reservation->total_amount, 0, ',', '.'));
        $first = DB::table('bookings')->where('reservation_id', $reservation->id)->first();
        $this->get('/journeys/'.$first->id)->assertOk()->assertDontSee('data-bank-transfer-qr', false);
        $this->post('/journeys/'.$first->id.'/transfer-report', ['reference' => 'CUSTOMERTEST123'])->assertRedirect();
        $this->assertSame(0, DB::table('tickets')->count());
        $this->assertSame(2, DB::table('bookings')->where('reservation_id', $reservation->id)->where('status', 'PAYMENT_PENDING')->count());
        $this->assertSame(0, DB::connection('finance')->table('payments')->count());
    }

    public function test_personal_momo_phone_does_not_enable_merchant_payments(): void
    {
        config(['payments.momo.enabled' => true, 'payments.momo.personal_phone' => '0900000000', 'payments.momo.partner_code' => '', 'payments.momo.access_key' => '', 'payments.momo.secret_key' => '']);
        $this->assertArrayNotHasKey('MOMO', app(CheckoutService::class)->methods());
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/connections')->assertOk()->assertSee('0900000000')
            ->assertSee('Số điện thoại này chưa được kết nối');
    }
}
