<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MockPaymentController;
use App\Http\Controllers\PassengerDeskController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\TravelDocumentController;
use App\Http\Controllers\WorkspaceController;
use App\Modules\Customer\EmailVerification;
use App\Modules\Shared\Access;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', [PortalController::class, 'flights']);
Route::get('/health/ready', [PortalController::class, 'health'])->middleware('throttle:60,1');
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth', ['mode' => 'login'])->name('login');
    Route::view('/register', 'auth', ['mode' => 'register']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::view('/forgot-password', 'auth', ['mode' => 'forgot'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1');
    Route::get('/reset-password/{token}', fn (Request $r, string $token) => view('auth', ['mode' => 'reset', 'token' => $token, 'email' => $r->query('email')]))->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::view('/email/verify', 'verify')->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $r) {
        $r->fulfill();
        DB::table('email_verifications')->where('user_id', $r->user()->id)->delete();

        return redirect('/journeys')->with('message', 'Đã xác minh email.');
    })->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', function (Request $r) {
        $sent = app(EmailVerification::class)->issue($r->user());

        return back()->with('message', $sent ? 'Đã gửi mã và liên kết xác minh.' : 'Vui lòng chờ ít nhất 60 giây trước khi gửi lại mã.');
    })->middleware('throttle:3,1');
    Route::post('/email/verify-otp', function (Request $r) {
        $d = $r->validate(['code' => 'required|string|regex:/^[0-9]{6}$/']);
        if (! app(EmailVerification::class)->verify($r->user(), $d['code'])) {
            return back()->withErrors(['code' => 'Mã không đúng, đã hết hạn hoặc vượt số lần thử. Vui lòng kiểm tra email hoặc yêu cầu mã mới.']);
        }
        $r->session()->regenerate();

        return redirect('/journeys')->with('message', 'Đã xác minh email.');
    })->middleware('throttle:6,1');
    Route::view('/profile', 'profile');
    Route::post('/profile', [AuthController::class, 'profile']);
    Route::post('/profile/password', [AuthController::class, 'password'])->middleware('throttle:5,1');
    Route::middleware(['role:passenger', 'verified'])->group(function () {
        Route::get('/journeys', [PortalController::class, 'journeys']);
        Route::post('/bookings', [PortalController::class, 'hold'])->middleware('throttle:20,1');
        Route::get('/journeys/{id}', [PortalController::class, 'show']);
        Route::post('/journeys/{id}/pay', [PortalController::class, 'pay'])->middleware('throttle:10,1');
        Route::post('/journeys/{id}/cancel', [PortalController::class, 'cancel']);
        Route::post('/journeys/{id}/checkin', [PortalController::class, 'checkin']);
    });
    Route::get('/staff', [PortalController::class, 'staff'])->middleware('role:'.implode(',', array_diff(Access::ROLES, ['passenger'])));
    Route::post('/staff/boarding', [PortalController::class, 'board'])->middleware(['role:airport_agent', 'throttle:60,1']);
    Route::post('/admin/users/{id}/role', [PortalController::class, 'role'])->middleware('role:admin');
});
// First-party REST endpoints deliberately retain the web session and CSRF middleware.
Route::prefix('api/v1')->middleware('throttle:60,1')->group(function () {
    Route::get('/flights', [PortalController::class, 'flights']);
    Route::middleware(['auth', 'verified', 'role:passenger'])->group(function () {
        Route::get('/bookings', [PortalController::class, 'journeys']);
        Route::post('/bookings', [PortalController::class, 'hold']);
        Route::get('/bookings/{id}', [PortalController::class, 'show']);
        Route::post('/bookings/{id}/pay', [PortalController::class, 'pay']);
        Route::post('/bookings/{id}/cancel', [PortalController::class, 'cancel']);
        Route::post('/bookings/{id}/checkin', [PortalController::class, 'checkin']);
    });
    Route::post('/boarding', [PortalController::class, 'board'])->middleware(['auth', 'role:airport_agent']);
});

Route::middleware(['auth', 'verified', 'throttle:90,1'])->group(function () {
    Route::get('/workspace/{section}', [WorkspaceController::class, 'index']);
    Route::post('/workspace/actions/{action}', [WorkspaceController::class, 'dispatch']);
    Route::get('/baggage/{id}', [WorkspaceController::class, 'trace'])->whereNumber('id');
    Route::get('/workspace/reports/{id}/export', [WorkspaceController::class, 'export'])->whereNumber('id');
    Route::get('/api/v1/workspace/{section}', [WorkspaceController::class, 'index']);
    Route::post('/api/v1/actions/{action}', [WorkspaceController::class, 'dispatch']);
    Route::get('/api/v1/baggage/{id}', [WorkspaceController::class, 'trace'])->whereNumber('id');
});

Route::middleware(['auth', 'verified', 'throttle:20,1'])->group(function () {
    Route::post('/workspace/master/import', [WorkspaceController::class, 'importMaster']);
    Route::get('/workspace/master/export/{entity}', [WorkspaceController::class, 'exportMaster']);
});

Route::middleware(['auth', 'verified', 'throttle:60,1'])->group(function () {
    Route::post('/journeys/{id}/transfer-report', [PaymentController::class, 'report'])->middleware('role:passenger')->whereUuid('id');
    Route::post('/journeys/{id}/refund-destination', [PaymentController::class, 'destination'])->middleware('role:passenger')->whereUuid('id');
    Route::post('/staff/payments/confirm-refund', [PaymentController::class, 'settleRefund'])->middleware('role:finance');
    Route::post('/staff/payments/confirm-transfer', [PaymentController::class, 'decide'])->middleware('role:finance');
    Route::get('/payments/mock/{id}', [MockPaymentController::class, 'show'])->middleware('role:passenger')->whereUuid('id');
    Route::post('/payments/mock/{id}', [MockPaymentController::class, 'pay'])->middleware('role:passenger')->whereUuid('id');
    Route::get('/payments/{provider}/return', [PaymentController::class, 'returned'])->middleware('role:passenger')->whereIn('provider', ['vnpay', 'momo']);
    foreach (['airports' => 'airport', 'routes' => 'route', 'aircraft' => 'aircraft', 'crew' => 'crew', 'schedules' => 'schedule', 'maintenance-orders' => 'work_order', 'resource-allocations' => 'allocate', 'ground-tasks' => 'ground_task', 'disruptions' => 'disruption', 'baggage-tags' => 'bag_tag', 'baggage-events' => 'bag_scan', 'support-cases' => 'support', 'telemetry' => 'telemetry', 'devices' => 'device', 'fare-classes' => 'fare_class'] as $resource => $action) {
        Route::post('/api/v1/'.$resource, [WorkspaceController::class, 'dispatch'])->defaults('action', $action);
    }
});

Route::get('/payments/vnpay/ipn', [PaymentController::class, 'vnpay'])->middleware('throttle:120,1');
Route::post('/payments/momo/ipn', [PaymentController::class, 'momo'])->middleware('throttle:120,1');

Route::middleware(['auth', 'verified', 'throttle:60,1'])->group(function () {
    Route::get('/support/{id}', [WorkspaceController::class, 'conversation'])->whereNumber('id');
    Route::get('/api/v1/support-cases/{id}', [WorkspaceController::class, 'conversation'])->whereNumber('id');
});

Route::middleware(['auth', 'verified', 'throttle:60,1'])->group(function () {
    Route::get('/workspace/recovery/{id}', [WorkspaceController::class, 'recovery'])->whereUuid('id');
    Route::get('/api/v1/flight-changes/{id}', [WorkspaceController::class, 'recovery'])->whereUuid('id');
    Route::post('/api/v1/flight-changes', [WorkspaceController::class, 'dispatch'])->defaults('action', 'resource_propose');
});

Route::middleware(['auth', 'verified', 'throttle:60,1'])->group(function () {
    Route::get('/staff/passengers', [PassengerDeskController::class, 'index'])->middleware('role:airport_agent');
    Route::post('/staff/passengers/{id}/checkin', [PassengerDeskController::class, 'checkin'])->middleware('role:airport_agent')->whereUuid('id');
    Route::get('/journeys/{id}/ticket', [TravelDocumentController::class, 'show'])->whereUuid('id');
    Route::get('/journeys/{id}/boarding-pass', [TravelDocumentController::class, 'show'])->whereUuid('id')->defaults('kind', 'boarding-pass');
    Route::post('/admin/users', [AccountController::class, 'create'])->middleware('role:admin');
    Route::get('/admin/connections', [AccountController::class, 'connections'])->middleware('role:admin');
    Route::post('/admin/users/{id}/access', [AccountController::class, 'access'])->middleware('role:admin')->whereNumber('id');
});
