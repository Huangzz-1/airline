@extends('layouts.app')
@section('content')
<div class="eyebrow">Quản trị dịch vụ</div><h1>Email & thanh toán</h1><p class="subtle">Kiểm tra cấu hình dịch vụ trước khi mở cho khách hàng. Trạng thái cấu hình cần được đối chiếu bằng giao dịch kiểm tra với nhà cung cấp.</p><div class="action-grid">@foreach($configured as $label=>$ready)<section class="card-surface"><h2>{{ $label }}</h2><span class="badge-state">{{ $ready?'Đã có cấu hình':'Chưa cấu hình' }}</span><p class="subtle mt-3">{{ $ready?'Cần xác minh kết nối và kết quả giao dịch trong môi trường triển khai.':'Dịch vụ chưa được mở để sử dụng. Liên hệ người quản trị triển khai để thiết lập.' }}</p></section>@endforeach</div>
@endsection
