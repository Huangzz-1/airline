@php
    $isStaff = auth()->check() && auth()->user()->role !== 'passenger';
    $workspaceSections = $isStaff ? \App\Http\Controllers\WorkspaceController::sections() : [];
    $staffModules = [
        'm1' => ['Quản lý cốt lõi', ['admin', 'data_admin', 'operations', 'ops_manager', 'fleet_manager', 'maintenance', 'crew_scheduler', 'ground_agent', 'integration_admin']],
        'm2' => ['Hành khách & booking', ['admin', 'airport_agent', 'customer_service', 'operations']],
        'm3' => ['Phân tích AI & IoT', ['admin', 'analyst', 'data_admin', 'operations', 'ops_manager', 'maintenance', 'integration_admin']],
    ];
@endphp
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b1f3a">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'Airline Smart · Hành trình của bạn')</title>
    <link rel="preload" href="/app.css" as="style">
    <link rel="stylesheet" href="/bootstrap.min.css">
    <link rel="stylesheet" href="/app.css">
    @stack('styles')
</head>
<body class="app-body {{ $isStaff ? 'has-sidebar' : 'public-app' }}">
    <a class="skip-link" href="#main-content">Bỏ qua đến nội dung chính</a>
    @if($isStaff)
        <aside class="app-sidebar" id="app-sidebar" aria-label="Điều hướng nghiệp vụ">
            <div class="sidebar-brand">
                <a class="brand" href="/staff" aria-label="Airline Smart · Bàn làm việc"><span class="brand-mark" aria-hidden="true">✈</span><span>AIRLINE<span class="brand-light"> SMART</span></span></a>
                <button type="button" class="sidebar-close" data-sidebar-toggle aria-label="Đóng menu">×</button>
            </div>
            <div class="sidebar-context"><span class="sidebar-context-label">Không gian làm việc</span><strong>{{ auth()->user()->airport_code ?: 'Toàn hệ thống' }}</strong></div>
            <nav class="sidebar-nav">
                <a class="sidebar-link {{ request()->is('staff') ? 'active' : '' }}" href="/staff"><span class="sidebar-icon" aria-hidden="true">⌂</span><span>Tổng quan</span></a>
                @foreach($staffModules as $module => [$moduleLabel, $moduleRoles])
                    @php($moduleAllowed = \App\Modules\Shared\Access::allows(auth()->user(), ...$moduleRoles))
                    <a class="sidebar-link {{ request()->is('modules/'.$module) ? 'active' : '' }} {{ $moduleAllowed ? '' : 'is-locked' }}" href="/modules/{{ $module }}" @if(!$moduleAllowed) aria-label="{{ $moduleLabel }} — Không đủ thẩm quyền" @endif><span class="sidebar-icon" aria-hidden="true">•</span><span>{{ $moduleLabel }}</span>@if(!$moduleAllowed)<small aria-hidden="true">🔒</small>@endif</a>
                @endforeach
                @php($passengerDeskAllowed = \App\Modules\Shared\Access::allows(auth()->user(), 'airport_agent'))
                <a class="sidebar-link {{ request()->is('staff/passengers') ? 'active' : '' }} {{ $passengerDeskAllowed ? '' : 'is-locked' }}" href="/staff/passengers" @if(!$passengerDeskAllowed) aria-label="Phục vụ hành khách — Không đủ thẩm quyền" @endif><span class="sidebar-icon" aria-hidden="true">•</span><span>Phục vụ hành khách</span>@if(!$passengerDeskAllowed)<small aria-hidden="true">🔒</small>@endif</a>
                @foreach($workspaceSections as $key => [$label, $roles])
                    @php($allowed = \App\Modules\Shared\Access::allows(auth()->user(), ...$roles))
                    <a class="sidebar-link {{ request()->is('workspace/'.$key) ? 'active' : '' }} {{ $allowed ? '' : 'is-locked' }}" href="/workspace/{{ $key }}" @if(!$allowed) aria-label="{{ $label }} — Không đủ thẩm quyền" @endif><span class="sidebar-icon" aria-hidden="true">•</span><span>{{ $label }}</span>@if(!$allowed)<small aria-hidden="true">🔒</small>@endif</a>
                @endforeach
                @php($adminAllowed = auth()->user()->role === 'admin')
                <div class="sidebar-divider"></div>
                <a class="sidebar-link {{ request()->is('admin/users') ? 'active' : '' }} {{ $adminAllowed ? '' : 'is-locked' }}" href="/admin/users" aria-label="Quản lý tài khoản — {{ $adminAllowed ? 'Được phép' : 'Không đủ thẩm quyền' }}"><span class="sidebar-icon" aria-hidden="true">•</span><span>Quản lý tài khoản</span>@if(!$adminAllowed)<small aria-hidden="true">🔒</small>@endif</a>
                <a class="sidebar-link {{ request()->is('admin/connections') ? 'active' : '' }} {{ $adminAllowed ? '' : 'is-locked' }}" href="/admin/connections" aria-label="Email & thanh toán — {{ $adminAllowed ? 'Được phép' : 'Không đủ thẩm quyền' }}"><span class="sidebar-icon" aria-hidden="true">•</span><span>Email & thanh toán</span>@if(!$adminAllowed)<small aria-hidden="true">🔒</small>@endif</a>
            </nav>
            <div class="sidebar-footer">
                <a class="sidebar-user" href="/profile"><span class="avatar avatar-sm">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><span class="sidebar-user-copy"><strong>{{ auth()->user()->name }}</strong><small>{{ \App\Support\Ui::role(auth()->user()->role) }}</small></span><span aria-hidden="true">›</span></a>
                <form method="post" action="/logout" class="sidebar-logout">@csrf<button type="submit"><span aria-hidden="true">↪</span> Đăng xuất</button></form>
            </div>
        </aside>
        <div class="sidebar-backdrop" data-sidebar-toggle></div>
    @endif
    <div class="app-frame">
        <header class="site-header {{ $isStaff ? 'staff-header' : '' }}">
            <nav class="container nav-shell" aria-label="Điều hướng chính">
                @if($isStaff)<button type="button" class="sidebar-toggle" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="false" aria-label="Mở menu"><span></span><span></span><span></span></button>@endif
                <a class="brand" href="{{ $isStaff ? '/staff' : '/home' }}"><span class="brand-mark" aria-hidden="true">✈</span><span>AIRLINE<span class="brand-light"> SMART</span></span></a>
                <div class="nav-links">
                    <a href="/home" @if(request()->is('home')) aria-current="page" @endif>Trang chủ</a><a href="/" @if(request()->is('/')) aria-current="page" @endif>Đặt chuyến đi</a>
                    @auth
                        @if(auth()->user()->role === 'passenger')<a href="/journeys" @if(request()->is('journeys*')) aria-current="page" @endif>Hành trình của tôi</a>@else<a href="/staff" @if(request()->is('staff')) aria-current="page" @endif>Bàn làm việc</a>@endif
                        <a class="nav-icon-link" href="/workspace/notifications" aria-label="Thông báo">♧<span class="nav-icon-label">Thông báo</span></a>@if(auth()->user()->role === 'passenger')<a href="/workspace/customer">Hỗ trợ</a>@endif
                        <a class="nav-profile" href="/profile"><span class="avatar avatar-xs">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><span class="nav-profile-name">{{ auth()->user()->name }}</span></a>@if(!$isStaff)<form method="post" action="/logout">@csrf<button class="btn btn-sm btn-outline-light" type="submit">Đăng xuất</button></form>@endif
                    @else<a href="/login">Đăng nhập</a><a class="btn btn-sm btn-light" href="/register">Đăng ký</a>@endauth
                </div>
            </nav>
        </header>
        <main id="main-content" class="container page" tabindex="-1">
            @auth
                @if(!$isStaff)
                    <nav class="module-nav passenger-module-nav" aria-label="Điều hướng tài khoản"><a class="{{ request()->is('journeys*') ? 'active' : '' }}" href="/journeys">Hành trình của tôi</a><a class="{{ request()->is('modules/m2') ? 'active' : '' }}" href="/modules/m2">Booking & hành lý</a><a class="{{ request()->is('workspace/notifications') ? 'active' : '' }}" href="/workspace/notifications">Thông báo</a><a class="{{ request()->is('workspace/customer') ? 'active' : '' }}" href="/workspace/customer">Hỗ trợ</a><a class="{{ request()->is('profile') ? 'active' : '' }}" href="/profile">Hồ sơ</a></nav>
                @else
                    <nav class="module-nav contextual-nav" aria-label="Phân hệ nghiệp vụ"><a href="/staff" class="{{ request()->is('staff') ? 'active' : '' }}">Tổng quan</a><a href="/admin/users" class="{{ request()->is('admin/users') ? 'active' : '' }} {{ auth()->user()->role === 'admin' ? '' : 'is-locked' }}" aria-label="Quản lý tài khoản — {{ auth()->user()->role === 'admin' ? 'Được phép' : 'Không đủ thẩm quyền' }}">Quản lý tài khoản</a>@foreach($workspaceSections as $key => [$label, $roles])<a class="{{ request()->is('workspace/'.$key) ? 'active' : '' }}" href="/workspace/{{ $key }}">{{ $label }}</a>@endforeach</nav>
                @endif
            @endauth
            <div class="global-feedback" aria-live="polite">
                @if(session('message'))<div class="alert alert-success alert-dismissible" role="status"><span class="feedback-icon" aria-hidden="true">✓</span><span>{{ session('message') }}</span><button type="button" class="alert-close" data-dismiss-alert aria-label="Đóng thông báo">×</button></div>@endif
                @if($errors->any())<div class="alert alert-danger alert-dismissible" role="alert"><span class="feedback-icon" aria-hidden="true">!</span><span><strong>Vui lòng kiểm tra thông tin.</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></span><button type="button" class="alert-close" data-dismiss-alert aria-label="Đóng thông báo">×</button></div>@endif
            </div>
            @yield('content')
        </main>
        <footer class="container footer"><span><strong>✈ Airline Smart</strong><small>Nền tảng vận hành hàng không</small></span><span>Hành trình liền mạch · Thông tin minh bạch</span><span>Giờ Việt Nam · UTC+7</span></footer>
    </div>
    <div class="toast-region" aria-live="polite" aria-atomic="true"></div>
    <script src="/app.js" defer></script>
    @stack('scripts')
</body>
</html>
