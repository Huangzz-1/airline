<?php

namespace App\Console\Commands;

use Database\Seeders\AirportDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SeedDemoData extends Command
{
    protected $signature = 'airline:demo-data';

    protected $description = 'Create a complete local airport test dataset without resetting existing data';

    public function handle(): int
    {
        if (! app()->environment('local') || config('payments.mode') !== 'mock' || config('payments.bank.mode') !== 'mock') {
            $this->error('Demo data requires APP_ENV=local and mock payment/bank modes.');

            return self::FAILURE;
        }
        if (! in_array(config('mail.mailers.smtp.host'), ['mailpit', '127.0.0.1', 'localhost'], true)) {
            $this->error('Use the local Mailpit SMTP server before creating test accounts.');

            return self::FAILURE;
        }
        if (strlen(config('airline.demo_password') ?? '') < 16) {
            $this->error('Set LOCAL_DEMO_PASSWORD in backend/.env to a test password of at least 16 characters.');

            return self::FAILURE;
        }
        $lock = Cache::lock('airline-full-demo-seed', 1800);
        if (! $lock->get()) {
            $this->error('Another demo seed is running.');

            return self::FAILURE;
        }
        try {
            (new AirportDemoSeeder)->generate(fn ($message) => $this->line($message));
            $this->info('Report: '.storage_path('app/private/demo/report.md'));
            $this->info('Passwords: LOCAL_DEMO_PASSWORD in backend/.env. Existing accounts were preserved.');
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
