@extends('layouts.app')
@section('title', $moduleTitle.' · Airline Smart')
@section('content')
<div class="module-dashboard" data-module="{{ $module }}">
    <header class="module-hero">
        <div>
            <span class="eyebrow">Bảng điều hành</span>
            <h1>{{ $moduleTitle }}</h1>
            <p class="subtle mb-0">Thông tin được hiển thị theo quyền truy cập và khu vực phụ trách của bạn.</p>
        </div>
        <div class="module-hero-actions">
            <span class="module-scope">{{ auth()->user()->airport_code ?: 'Toàn hệ thống' }}</span>
            <button class="btn btn-primary" type="button" data-module-refresh>Làm mới dữ liệu</button>
        </div>
    </header>

    <nav class="module-tabs" aria-label="Các module chính">
        <a class="{{ $module === 'm1' ? 'active' : '' }}" href="/modules/m1">Quản lý cốt lõi</a>
        <a class="{{ $module === 'm2' ? 'active' : '' }}" href="/modules/m2">Hành khách & hành lý</a>
        <a class="{{ $module === 'm3' ? 'active' : '' }}" href="/modules/m3">Phân tích & thiết bị</a>
    </nav>

    <section class="module-kpis" aria-label="Chỉ số module">
        @foreach($kpis as $kpi)
            <article class="module-kpi module-kpi-{{ $kpi['tone'] }} card-surface">
                <span>{{ $kpi['label'] }}</span>
                <strong>{{ number_format((int) $kpi['value']) }}</strong>
                <small>Cập nhật theo dữ liệu hiện tại</small>
            </article>
        @endforeach
    </section>

    @if(session('message'))
        <div class="alert alert-success module-alert" role="status">{{ session('message') }}</div>
    @endif
    <div class="module-feedback" data-module-feedback role="status" aria-live="polite"></div>

    @yield('module-body')
</div>
@endsection
@push('styles')
<link rel="stylesheet" href="/modules.css">
@endpush
@push('scripts')
<script src="/modules.js" defer></script>
@endpush
