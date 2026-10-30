<?php

namespace App\Modules\Customer;

use App\Models\User;
use App\Notifications\VerifyEmail;
use Carbon\Carbon;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmailVerification
{
    public function issue(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $u = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            if ($u->hasVerifiedEmail()) {
                return false;
            }
            $old = DB::table('email_verifications')->where('user_id', $u->id)->lockForUpdate()->first();
            if ($old && now()->lt(Carbon::parse($old->updated_at)->addSeconds(60))) {
                return false;
            }
            do {
                $code = (string) random_int(100000, 999999);
            } while ($old && Hash::check($code, $old->code_hash));
            DB::table('email_verifications')->updateOrInsert(['user_id' => $u->id], ['code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
            $u->notify(new VerifyEmail($code));

            return true;
        }, 3);
    }

    public function verify(User $u, string $code): bool
    {
        $changed = false;
        $verified = DB::transaction(function () use ($u, $code, &$changed) {
            $user = User::where('id', $u->id)->lockForUpdate()->firstOrFail();
            if ($user->hasVerifiedEmail()) {
                return true;
            }
            $challenge = DB::table('email_verifications')->where('user_id', $u->id)->lockForUpdate()->first();
            if (! $challenge || $challenge->attempts >= 5 || now()->gte($challenge->expires_at)) {
                return false;
            }
            DB::table('email_verifications')->where('user_id', $u->id)->increment('attempts');
            if (! Hash::check($code, $challenge->code_hash)) {
                return false;
            }
            $user->markEmailAsVerified();
            $changed = true;
            DB::table('email_verifications')->where('user_id', $u->id)->delete();

            return true;
        }, 3);
        if ($changed) {
            $u->refresh();
            event(new Verified($u));
        }

        return $verified;
    }
}
