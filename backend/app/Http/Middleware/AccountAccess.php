<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class AccountAccess
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();
        if ($user?->disabled_at) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() ? response()->json(['message' => 'Tài khoản đã tạm khóa. Vui lòng liên hệ quản trị viên.'], 403) : redirect('/login')->withErrors(['email' => 'Tài khoản đã tạm khóa. Vui lòng liên hệ quản trị viên.']);
        }
        if ($user?->must_change_password && ! $request->is('profile', 'profile/password', 'logout')) {
            return $request->expectsJson() ? response()->json(['message' => 'Vui lòng đổi mật khẩu trước khi tiếp tục.'], 403) : redirect('/profile')->with('message', 'Vui lòng đổi mật khẩu tạm thời để bắt đầu sử dụng tài khoản.');
        }

        return $next($request);
    }
}
