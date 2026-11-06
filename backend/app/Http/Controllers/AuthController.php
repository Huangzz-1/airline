<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Shared\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(Request $r)
    {
        // Email addresses are identifiers throughout the system. Normalize before
        // validation so a database with case-sensitive collation cannot create
        // two accounts that authenticate as the same address.
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $data = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:190|unique:users',
            'password' => ['required', 'confirmed', PasswordRule::min(10)], 'consent' => 'accepted']);
        $user = DB::transaction(function () use ($data) {
            $u = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $u->forceFill(['role' => 'passenger', 'consented_at' => now()])->save();
            Audit::record('auth.register', (string) $u->id, null, 'passenger', $u->id);

            return $u;
        });
        event(new Registered($user));
        Auth::login($user);
        $r->session()->regenerate();

        return $r->expectsJson() ? response()->json($user, 201) : redirect('/email/verify');
    }

    public function login(Request $r)
    {
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $credentials = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($credentials)) {
            return back()->withErrors(['email' => 'Email hoặc mật khẩu không đúng.'])->onlyInput('email');
        }
        if ($r->user()->disabled_at) {
            Auth::logout();

            return back()->withErrors(['email' => 'Email hoặc mật khẩu không đúng, hoặc tài khoản đã bị khóa.'])->onlyInput('email');
        }
        $r->session()->regenerate();
        Audit::record('auth.login', (string) $r->user()->id, null, 'success', $r->user()->id);

        return $r->expectsJson() ? response()->json($r->user()) : self::authenticatedLanding($r);
    }

    public static function authenticatedLanding(Request $r): RedirectResponse
    {
        $intended = $r->session()->pull('url.intended', '');
        if ($r->user()->role !== 'passenger') {
            return redirect('/staff');
        }
        $path = parse_url($intended, PHP_URL_PATH) ?: '';
        if (! $r->user()->hasVerifiedEmail() && preg_match('~^/email/verify/\d+/[a-f0-9]{40}$~D', $path)) {
            $query = parse_url($intended, PHP_URL_QUERY);

            return redirect($path.($query ? '?'.$query : ''));
        }
        // Resume only an actual booking draft; an old protected-page visit must not replace home.
        if ($r->session()->has('trip_draft') && $path === '/trip/passengers') {
            $query = parse_url($intended, PHP_URL_QUERY);

            return redirect('/trip/passengers'.($query ? '?'.$query : ''));
        }

        return redirect('/home');
    }

    public function logout(Request $r)
    {
        Audit::record('auth.logout', (string) $r->user()->id, 'session', null, $r->user()->id);
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/');
    }

    public function forgot(Request $r)
    {
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email'));

        return back()->with('message', 'Nếu email tồn tại, hướng dẫn đặt lại mật khẩu đã được gửi.');
    }

    public function reset(Request $r)
    {
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $r->validate(['email' => 'required|email', 'token' => 'required', 'password' => ['required', 'confirmed', PasswordRule::min(10)]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function (User $u, string $password) {
            $u->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60), 'must_change_password' => false])->save();
            event(new PasswordReset($u));
        });

        return $status === Password::PASSWORD_RESET ? redirect('/login')->with('message', 'Đã đặt lại mật khẩu.') : back()->withErrors(['email' => 'Liên kết không hợp lệ hoặc hết hạn.']);
    }

    public function profile(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100']);
        $r->user()->update($d);
        Audit::record('profile.updated', (string) $r->user()->id, null, 'updated', $r->user()->id);

        return back()->with('message', 'Đã lưu hồ sơ.');
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', PasswordRule::min(10)]]);
        $r->user()->forceFill(['password' => Hash::make($d['password']), 'remember_token' => Str::random(60), 'must_change_password' => false])->save();
        $r->session()->regenerate();

        return back()->with('message', 'Đã đổi mật khẩu.');
    }
}
