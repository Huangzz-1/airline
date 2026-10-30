<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Shared\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BootstrapLocal extends Command
{
    protected $signature = 'airline:bootstrap-local';

    protected $description = 'Create the first local administrator from private environment settings';

    public function handle(): int
    {
        if (! app()->environment('local') || ! config('airline.bootstrap_admin')) {
            $this->info('Local bootstrap disabled.');

            return self::SUCCESS;
        }
        $email = config('airline.admin_email');
        $password = config('airline.admin_password');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! is_string($password) || strlen($password) < 16) {
            $this->error('Set local administrator credentials in backend/.env.');

            return self::FAILURE;
        }
        if (User::where('role', 'admin')->exists()) {
            $this->info('Administrator already exists; credentials and accounts preserved.');

            return self::SUCCESS;
        }
        if (User::where('email', $email)->exists()) {
            $this->error('Bootstrap email already belongs to another account; no roles changed.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($email, $password) {
            $u = User::create(['email' => $email, 'name' => 'Quản trị hệ thống', 'password' => $password]);
            $u->forceFill(['role' => 'admin', 'email_verified_at' => now(), 'must_change_password' => true])->save();
            Audit::record('user.local_bootstrap', (string) $u->id, null, 'admin', $u->id);
        });
        $this->info('Local administrator created. Credentials remain in backend/.env; change the temporary password on first login.');

        return self::SUCCESS;
    }
}
