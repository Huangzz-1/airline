@extends('layouts.app')
@section('title', 'Trang chủ · Airline Smart')
@section('content')
@php($ui=\App\Support\Ui::class)
<div class="airline-home">
    @auth @if(!auth()->user()->hasVerifiedEmail())<div class="home-verification"><span>Xác minh email để hoàn tất đặt vé và quản lý chuyến đi.</span><a href="/email/verify">Xác minh tài khoản →</a></div>@endif @endauth
    <section class="home-hero" aria-label="Chào mừng đến Airline Smart">
        <img class="home-hero-image" src="/images/aviation-home-hero.png" width="1881" height="836" alt="Máy bay bên nhà ga sân bay trong ánh bình minh" fetchpriority="high" decoding="async">
        <div class="home-hero-shade"></div>
        <div class="home-hero-content">
            <div class="home-overline">AIRLINE SMART · CÙNG BẠN CẤT CÁNH</div>
            @auth <p class="home-greeting">Chào {{ auth()->user()->name }},</p>@else<p class="home-greeting">Chào mừng bạn đến với Airline Smart</p>@endauth
            <h1>Một hành trình mới.<br>Thêm nhiều trải nghiệm.</h1>
            <p class="home-hero-description">Tìm chuyến bay, chọn lịch trình và quản lý vé của bạn trong một trải nghiệm liền mạch.</p>
            <div class="home-hero-actions"><a class="btn home-primary" href="#home-booking">Khám phá chuyến bay <span aria-hidden="true">↗</span></a><a class="home-hero-secondary" href="#home-guide">Tìm hiểu cách đặt vé →</a></div>
        </div>
        <div class="home-hero-caption"><span class="home-caption-dot"></span> Sẵn sàng cho điểm đến tiếp theo</div>
    </section>

    <section class="home-booking-card" id="home-booking" aria-labelledby="home-booking-title">
        <div class="home-booking-heading"><div><span class="home-section-kicker">BẮT ĐẦU CHUYẾN ĐI</span><h2 id="home-booking-title">Bạn muốn bay tới đâu?</h2></div><span class="home-search-note">Một chiều hoặc khứ hồi</span></div>
        <form method="get" action="/" data-home-search data-airport-search data-route-network="{{ json_encode($destinationNetwork) }}" data-default-date="{{ now('Asia/Ho_Chi_Minh')->addDay()->toDateString() }}">
            <div class="home-search-settings"><div><label for="home-trip-type">Loại chuyến đi</label><select class="form-select" id="home-trip-type" name="trip_type"><option value="ONE_WAY">Một chiều</option><option value="ROUND_TRIP">Khứ hồi</option></select></div><span class="single-passenger-note">1 hành khách</span><input type="hidden" name="passenger_count" value="1"></div>
            <div class="home-search-fields"><div><label for="home-origin">Điểm khởi hành</label><select class="form-select" id="home-origin" name="origin" data-airport-origin><option value="">Chọn điểm đi</option>@foreach($airports as $airport)<option value="{{ $airport->code }}">{{ $airport->city }} ({{ $airport->code }})</option>@endforeach</select></div><div><label for="home-destination">Điểm đến</label><select class="form-select" id="home-destination" name="destination" data-airport-destination disabled><option value="">Chọn điểm đi trước</option></select></div><div><label for="home-departure">Ngày khởi hành</label><input class="form-control" id="home-departure" name="date" type="date" min="{{ now('Asia/Ho_Chi_Minh')->toDateString() }}"></div><div data-home-return hidden><label for="home-return">Ngày về</label><input class="form-control" id="home-return" name="return_date" type="date" min="{{ now('Asia/Ho_Chi_Minh')->toDateString() }}" disabled></div><button type="submit" class="btn home-primary">Tìm chuyến bay <span aria-hidden="true">→</span></button></div>
        </form>
    </section>

    <section class="home-shortcuts" aria-label="Truy cập nhanh">
        <a href="{{ auth()->check() ? '/journeys' : '/login' }}"><span class="home-shortcut-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M8 5v14m4-9h5m-5 4h3"/></svg></span><div><h2>Hành trình của tôi</h2><p>{{ $personal['trips'] ? $personal['trips'].' chuyến đi sắp tới cần theo dõi' : 'Xem vé, lịch trình và trạng thái đặt chỗ' }}</p></div><span aria-hidden="true">↗</span></a>
        <a href="{{ auth()->check() ? '/workspace/notifications' : '/login' }}"><span class="home-shortcut-icon home-icon-blue" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></span><div><h2>Thông báo</h2><p>{{ $personal['notifications'] ? $personal['notifications'].' thông báo chưa đọc' : 'Theo dõi cập nhật về chuyến bay của bạn' }}</p></div><span aria-hidden="true">↗</span></a>
        <a href="{{ auth()->check() ? '/workspace/customer' : '/login' }}"><span class="home-shortcut-icon home-icon-orange" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13v-3a8 8 0 0 1 16 0v3m-16-2h3v7H4a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2Zm16 0h-3v7h3a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2Zm0 7v1a2 2 0 0 1-2 2h-5"/></svg></span><div><h2>Hỗ trợ hành khách</h2><p>Gửi yêu cầu và theo dõi phản hồi hỗ trợ</p></div><span aria-hidden="true">↗</span></a>
    </section>

    @if($upcoming)
    <section class="home-upcoming" aria-labelledby="home-upcoming-title"><div class="home-section-title"><div><span class="home-section-kicker">DÀNH RIÊNG CHO BẠN</span><h2 id="home-upcoming-title">Chuyến bay sắp tới</h2></div><a href="/journeys">Xem tất cả →</a></div><article class="home-upcoming-card"><div class="home-upcoming-date"><strong>{{ $ui::date($upcoming['flight']->departure,'d') }}</strong><span>Tháng {{ $ui::date($upcoming['flight']->departure,'m') }}</span></div><div class="home-upcoming-route"><strong>{{ $upcoming['flight']->origin }} <span aria-hidden="true">→</span> {{ $upcoming['flight']->destination }}</strong><p>{{ $upcoming['flight']->number }} · {{ $ui::date($upcoming['flight']->departure) }} · {{ $upcoming['reference'] }}</p></div><span class="badge-state">{{ $ui::label($upcoming['booking']->status) }}</span>@if($upcoming['round_trip'])<span class="round-trip-badge">Khứ hồi</span>@endif<a class="btn btn-outline-primary" href="{{ $upcoming['url'] }}">Xem chuyến đi</a></article></section>
    @endif

    <section class="home-about" aria-labelledby="home-about-title">
        <div class="home-about-copy"><span class="home-section-kicker">VỀ AIRLINE SMART</span><h2 id="home-about-title">Từ lúc tìm vé<br> đến khi lên máy bay.</h2><p>Airline Smart kết nối các bước đặt vé và phục vụ hành khách. Bạn có thể chọn chuyến bay thẳng hoặc nối chuyến và theo dõi mọi thông tin trong tài khoản.</p><a href="/" class="home-text-link">Khám phá lịch bay →</a></div>
        <div class="home-network-stats"><div><strong>{{ number_format($stats['airports']) }}</strong><span>Sân bay trong mạng bay</span></div><div><strong>{{ number_format($stats['routes']) }}</strong><span>Đường bay đang hoạt động</span></div><div><strong>{{ number_format($stats['flights']) }}</strong><span>Chuyến bay sắp khởi hành</span></div><p>Số liệu từ lịch bay và mạng đường bay hiện có của hệ thống.</p></div>
    </section>

    <section id="home-guide" class="home-guide" aria-labelledby="home-guide-title"><div class="home-section-title"><div><span class="home-section-kicker">ĐƠN GIẢN TRONG TỪNG BƯỚC</span><h2 id="home-guide-title">Bắt đầu hành trình của bạn</h2></div><p>Ba bước để sẵn sàng cho chuyến đi.</p></div><div class="home-guide-grid">
        <article><span class="home-step-number">01</span><h3>Tìm và chọn chuyến</h3><p>Chọn một chiều hoặc khứ hồi. Lọc theo giá, giờ bay và số điểm dừng để tìm lịch trình phù hợp.</p><span class="home-feature-tag">Bay thẳng · 1–2 điểm dừng</span></article>
        <article><span class="home-step-number">02</span><h3>Hoàn tất thông tin</h3><p>Nhập thông tin hành khách, liên hệ và kiểm tra lịch trình, tổng giá trước khi tiếp tục.</p><span class="home-feature-tag">Một hành khách / mã đặt chỗ</span></article>
        <article><span class="home-step-number">03</span><h3>Thanh toán và quản lý vé</h3><p>Hoàn tất thanh toán, theo dõi vé trong tài khoản và chọn ghế khi làm thủ tục mở.</p><span class="home-feature-tag">Vé và QR riêng cho từng người</span></article>
    </div></section>

    <section class="home-help" aria-labelledby="home-help-title"><div><span class="home-section-kicker">TRƯỚC KHI CẤT CÁNH</span><h2 id="home-help-title">Thông tin bạn cần biết</h2><p>Chuẩn bị một chút để chuyến đi thuận tiện hơn.</p><a href="{{ auth()->check() ? '/workspace/customer' : '/login' }}" class="home-text-link">Cần hỗ trợ thêm? →</a></div><div class="home-faq"><details><summary>Tôi có thể đặt chuyến khứ hồi không?</summary><p>Có. Chọn Khứ hồi và ngày đi/ngày về. Bạn sẽ chọn lượt đi, lượt về rồi nhập thông tin hành khách một lần cho cả hai lượt.</p></details><details><summary>Khi nào tôi có thể chọn ghế?</summary><p>Sau khi vé được thanh toán và khi thời gian check-in mở, vào chi tiết vé trong Hành trình của tôi để chọn ghế trên sơ đồ cabin.</p></details><details><summary>Tôi theo dõi vé và yêu cầu đổi/hoàn ở đâu?</summary><p>Mở Hành trình của tôi để xem mã đặt chỗ và các vé thành phần. Yêu cầu đổi hoặc hoàn được xử lý theo điều kiện vé và quy trình xác nhận của hệ thống.</p></details></div></section>

    <section class="home-final-cta"><div><span class="home-section-kicker">ĐIỂM ĐẾN ĐANG CHỜ</span><h2>Sẵn sàng cho chuyến đi tiếp theo?</h2></div><a class="btn home-primary" href="/">Đặt chuyến đi <span aria-hidden="true">↗</span></a></section>
</div>
@endsection
