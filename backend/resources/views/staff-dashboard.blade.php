@extends('layouts.app')
@section('title', 'Bàn làm việc · Airline Smart')
@section('content')
<div class="staff-dashboard">
    <section class="dashboard-hero">
        <div>
            <span class="eyebrow">{{ \App\Support\Ui::role(auth()->user()->role) }} @if(auth()->user()->airport_code) · {{ auth()->user()->airport_code }} @endif</span>
            <h1>Bàn làm việc</h1>
            <p class="subtle">Xin chào {{ auth()->user()->name }}. Đây là tổng quan các công việc cần xử lý trong ca hiện tại.</p>
        </div>
        <div class="dashboard-hero-meta">
            <span class="dashboard-live-dot"><i></i> Hệ thống đang hoạt động</span>
            <time datetime="{{ now('Asia/Ho_Chi_Minh')->toIso8601String() }}">{{ now('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') }}</time>
        </div>
    </section>

    <section class="kpi-grid dashboard-kpis" aria-label="Chỉ số vận hành">
        @foreach($kpis as $label => $value)
            <article class="card-surface dashboard-kpi">
                <div class="dashboard-kpi-heading"><span class="subtle">{{ $label }}</span><span class="dashboard-kpi-icon" aria-hidden="true">{{ $loop->index === 0 ? '↗' : ($loop->index === 1 ? '◷' : ($loop->index === 2 ? '✓' : '◎')) }}</span></div>
                <strong class="kpi-value">{{ $value }}</strong>
                <span class="dashboard-kpi-foot">Cập nhật theo dữ liệu hiện tại</span>
            </article>
        @endforeach
    </section>

    <div class="dashboard-section-heading">
        <div><span class="eyebrow">Không gian nghiệp vụ</span><h2>Công việc của bạn</h2></div>
        <span class="subtle">{{ count($tiles) }} khu vực được phân quyền</span>
    </div>
    <section class="dashboard-actions" aria-label="Các khu vực nghiệp vụ">
        @forelse($tiles as $tile)
            <a class="card-surface dashboard-action {{ $loop->first ? 'dashboard-action-featured' : '' }}" href="{{ $tile['url'] }}">
                <span class="dashboard-action-number">{{ str_pad((string)($loop->index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                <span class="dashboard-action-copy"><strong>{{ $tile['title'] }}</strong><span>{{ $tile['description'] }}</span></span>
                <span class="dashboard-action-arrow" aria-hidden="true">↗</span>
            </a>
        @empty
            <div class="card-surface empty"><h2>Chưa có khu vực được phân quyền</h2><p class="subtle">Liên hệ quản trị viên để được cấp quyền truy cập.</p></div>
        @endforelse
    </section>

    <section class="dashboard-guidance card-surface" aria-label="Hướng dẫn ca làm việc">
        <div class="dashboard-guidance-icon" aria-hidden="true">✦</div>
        <div><strong>Giữ mọi chuyến bay đúng nhịp</strong><p class="subtle mb-0">Kiểm tra các cảnh báo, danh sách chờ và thông báo mới trước khi xử lý tác vụ quan trọng.</p></div>
        <a class="btn btn-outline-primary" href="/workspace/notifications">Xem thông báo</a>
    </section>
</div>
@endsection
