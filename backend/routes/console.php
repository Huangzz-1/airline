<?php

use App\Models\User;
use App\Modules\Analytics\AnalyticsService;
use App\Modules\Booking\BookingService;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

Artisan::command('airline:relay', function () {
    $failed = app(OutboxRelay::class)->drain();
    $this->info('Outbox delivery errors: '.$failed);

    return $failed ? 1 : 0;
})->purpose('Deliver durable sandbox payment events once');
Artisan::command('airline:expire', function () {
    app(BookingService::class)->expire();
    $this->info('Expired holds released.');
});
Artisan::command('airline:work', function () {
    while (true) {
        try {
            app(BookingService::class)->expire();
            app(OutboxRelay::class)->drain();
        } catch (Throwable $e) {
            report($e);
        } sleep(2);
    }
});
Artisan::command('airline:user {email} {--role=admin} {--airport=HAN}', function () {
    $role = $this->option('role');
    if (! in_array($role, Access::ROLES)) {
        $this->error('Invalid role');

        return 1;
    }
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
        $this->error('Invalid email or already exists; no existing account was changed.');

        return 1;
    }
    $password = $this->secret('Mật khẩu (ít nhất 10 ký tự)');
    if (strlen($password ?? '') < 10) {
        $this->error('Password too short');

        return 1;
    }
    $airport = in_array($role, Access::SCOPED) ? $this->option('airport') : null;
    if ($airport && ! DB::connection('ops')->table('airports')->where('code', $airport)->exists()) {
        $this->error('Invalid airport');

        return 1;
    }
    DB::transaction(function () use ($email, $password, $role, $airport) {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => Hash::make($password)]);
        $u->forceFill(['role' => $role, 'airport_code' => $airport, 'email_verified_at' => now()])->save();
        Audit::record('user.provisioned', (string) $u->id, null, $role);
    });
    $this->info('Account created; password not logged.');
});

Artisan::command('airline:iot-simulate {user_id} {device_id} {--scenario=normal}', function () {
    $u = User::findOrFail($this->argument('user_id'));
    $device = DB::connection('ops')->table('devices')->find($this->argument('device_id')) ?? throw new RuntimeException('Unknown device');
    Access::role($u, 'integration_admin');
    Access::airport($u, $device->airport_code);
    $scenario = $this->option('scenario');
    if (! in_array($scenario, ['normal', 'duplicate', 'late', 'alert', 'disconnect'])) {
        $this->error('Invalid scenario');

        return 1;
    }
    if ($scenario === 'disconnect') {
        $this->info('Simulation: disconnected, no telemetry sent; next run can retry with a new scenario.');

        return 0;
    }
    $svc = app(AnalyticsService::class);
    $sequence = $device->last_sequence + ($scenario === 'late' ? 2 : 1);
    $data = ['event_id' => (string) Str::uuid(), 'device_id' => $device->id, 'sequence' => $sequence, 'value' => $scenario === 'alert' ? $device->threshold + 5 : $device->threshold - 5, 'event_time' => now()->toIso8601String()];
    $svc->telemetry($u, $data);
    if ($scenario === 'duplicate') {
        $svc->telemetry($u, $data);
    }
    if ($scenario === 'late') {
        $data['event_id'] = (string) Str::uuid();
        $data['sequence'] = $sequence - 1;
        $data['event_time'] = now()->subMinutes(10)->toIso8601String();
        $svc->telemetry($u, $data);
    }
    $this->info('Simulator completed: '.$scenario.'. No physical equipment controlled.');
});
