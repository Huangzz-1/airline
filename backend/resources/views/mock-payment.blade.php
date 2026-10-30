@extends('layouts.app')
@section('content')
<section class="auth-panel card-surface"><div class="eyebrow">{{ $intent->method==='VNPAY'?'VNPay':'MoMo' }} · Thử nghiệm</div><h1>Thanh toán đặt chỗ</h1><p>Mã đặt chỗ <strong>{{ $intent->reference }}</strong></p><p class="price">{{ \App\Support\Ui::money($intent->amount) }}</p><p class="subtle">Kiểm thử thanh toán, không thu tiền thật. Chọn kết quả để tiếp tục hành trình.</p><form method="post" action="/payments/mock/{{ $intent->id }}">@csrf<div class="d-flex gap-2 flex-wrap"><button class="btn btn-primary" name="result" value="approve">Xác nhận thanh toán</button><button class="btn btn-outline-secondary" name="result" value="decline">Từ chối thanh toán</button></div></form></section>
@endsection
