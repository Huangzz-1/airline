<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Finance\CheckoutService;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function connections(Request $r)
    {
        Access::role($r->user(), 'admin');
        $methods = app(CheckoutService::class)->methods();
        $configured = ['Gửi email SMTP' => config('mail.default') === 'smtp' && (bool) config('mail.mailers.smtp.host'), 'VNPay' => isset($methods['VNPAY']), 'MoMo' => isset($methods['MOMO']), 'Chuyển khoản ngân hàng' => isset($methods['BANK'])];

        return view('connections', compact('configured'));
    }

    public function create(Request $r)
    {
        Access::role($r->user(), 'admin');
        $d = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:190|unique:users', 'password' => ['required', 'confirmed', Password::min(10)], 'role' => ['required', Rule::in(array_diff(Access::ROLES, ['passenger']))], 'airport_code' => 'nullable|string|size:3']);
        if (in_array($d['role'], Access::SCOPED)) {
            abort_unless(DB::connection('ops')->table('airports')->where('code', $d['airport_code'] ?? '')->exists(), 422, 'Vui lòng chọn sân bay được phân công.');
        }
        $user = DB::transaction(function () use ($d, $r) {
            $u = User::create(['name' => $d['name'], 'email' => strtolower($d['email']), 'password' => $d['password']]);
            $u->forceFill(['role' => $d['role'], 'airport_code' => in_array($d['role'], Access::SCOPED) ? $d['airport_code'] : null, 'email_verified_at' => now(), 'must_change_password' => true])->save();
            Audit::record('user.created', (string) $u->id, null, $d['role'], $r->user()->id);

            return $u;
        });

        return $r->expectsJson() ? response()->json($user, 201) : back()->with('message', 'Đã tạo tài khoản nhân viên. Nhân viên cần đổi mật khẩu khi đăng nhập lần đầu.');
    }

    public function access(Request $r, int $id)
    {
        Access::role($r->user(), 'admin');
        $d = $r->validate(['active' => 'required|boolean']);
        abort_if($id === $r->user()->id, 409, 'Không thể khóa tài khoản đang sử dụng.');
        DB::transaction(function () use ($r, $id, $d) {
            $admins = User::where('role', 'admin')->whereNull('disabled_at')->orderBy('id')->lockForUpdate()->get();
            abort_unless($admins->contains('id', $r->user()->id), 403);
            $u = User::lockForUpdate()->findOrFail($id);
            abort_if(! $d['active'] && $u->role === 'admin' && ! $u->disabled_at && $admins->count() < 2, 409, 'Cần giữ ít nhất một quản trị viên hoạt động.');
            $u->forceFill(['disabled_at' => $d['active'] ? null : now()])->save();
            Audit::record('user.access_changed', (string) $id, null, $d['active'] ? 'ACTIVE' : 'DISABLED', $r->user()->id);
        });

        return $r->expectsJson() ? response()->json(['active' => (bool) $d['active']]) : back()->with('message', $d['active'] ? 'Đã mở lại tài khoản.' : 'Đã tạm khóa tài khoản.');
    }
}
