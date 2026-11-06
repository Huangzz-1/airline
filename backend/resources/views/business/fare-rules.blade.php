@extends('layouts.app')
@section('title',$title.' · Airline Smart')
@section('content')
@php($ui=\App\Support\Ui::class)
@php($biz=\App\Support\BusinessUi::class)
@include('business.heading',['description'=>'Điều kiện mới áp dụng cho vé tạo sau khi lưu. Vé đã giữ/mua tiếp tục dùng điều kiện đã ghi nhận tại thời điểm đặt.'])
@forelse($rows as $fare)
@php($rules=\App\Modules\Booking\FareRules::decode($fare->rules))
<section class="card-surface business-card"><h2>{{ isset($flights[$fare->flight_id]) ? $ui::flight($flights[$fare->flight_id]) : 'Chuyến '.$fare->flight_id }}</h2><p><strong>{{ $fare->name }}</strong> · {{ $ui::money($fare->price) }}</p>
<form method="post" action="/workspace/business/fare_rules">@csrf<input type="hidden" name="fare_id" value="{{ $fare->id }}"><input type="hidden" name="version" value="{{ \App\Modules\Booking\FareRules::version($fare->rules) }}">
<div class="business-fields">
@foreach(['included_baggage_kg'=>['Hành lý ký gửi gồm trong vé (kg)',64],'extra_baggage_price_per_kg'=>['Giá mua thêm mỗi kg (VND)',1000000],'change_fee'=>['Phí đổi vé (VND)',100000000],'refund_fee'=>['Phí hoàn vé (VND)',100000000],'change_cutoff_minutes'=>['Hạn đổi trước giờ bay (phút)',10080],'refund_cutoff_minutes'=>['Hạn hoàn trước giờ bay (phút)',10080]] as $key=>[$label,$max])
<label>{{ $label }}<input class="form-control" type="number" name="{{ $key }}" min="{{ str_contains($key,'cutoff') ? 30 : 0 }}" max="{{ $max }}" value="{{ $rules[$key] }}" required></label>
@endforeach
@foreach(['change_allowed'=>'Cho phép đổi','refund_allowed'=>'Cho phép hoàn'] as $key=>$label)<label>{{ $label }}<select class="form-select" name="{{ $key }}"><option value="1" @selected($rules[$key])>Có</option><option value="0" @selected(!$rules[$key])>Không</option></select></label>@endforeach
</div><button class="btn btn-primary mt-3">Lưu điều kiện vé</button></form></section>
@empty<p class="alert alert-info">Chưa có hạng giá được tạo. Vào Giá vé & số chỗ để thiết lập hạng trước.</p>@endforelse
{{ $rows->links() }}
@endsection
