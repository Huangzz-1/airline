@extends('layouts.app')
@section('title','Phương án chuyến bay thay thế · Airline Smart')
@section('content')
@php($ui=\App\Support\Ui::class)
@php($biz=\App\Support\BusinessUi::class)
@include('business.heading',['title'=>'Phương án cho vé '.$booking->reference,'description'=>'Bạn xem lại lịch bay và chi phí trước khi quyết định. Phương án chưa tự giữ chỗ hoặc thu tiền.'])
<div class="business-fields"><section class="card-surface business-card"><h2>Chuyến hiện tại</h2><p>{{ $ui::flight($source) }}</p><p>{{ $ui::label($booking->status) }}</p><p>{{ $offer->reason }}</p></section><section class="card-surface business-card"><h2>Chuyến thay thế được duyệt</h2><p>{{ $ui::flight($target) }}</p><p>{{ $fare->name }} · Giá vé cơ bản <strong>{{ $ui::money($offer->quoted_amount) }}</strong></p><p class="subtle">Phương án có hiệu lực đến {{ $ui::date($offer->expires_at) }}; giá và chỗ được kiểm tra lại khi tiếp tục.</p>
@if(in_array($booking->status,['CONFIRMED','CHECKED_IN']) && \App\Modules\Booking\FareRules::changeAllowed($booking,$source) && $fare->code===$booking->fare_class)<form method="post" action="/recovery-offers/{{ $offer->id }}/exchange">@csrf<input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="booking_id" value="{{ $booking->id }}"><input type="hidden" name="flight_id" value="{{ $target->id }}"><input type="hidden" name="reason" value="Đổi theo phương án đã duyệt {{ substr($offer->id,0,8) }}"><p>Phí đổi theo vé hiện tại: {{ $ui::money($sourceRules['change_fee']) }}. Hành lý mua thêm được tính theo hạng vé mới.</p><button class="btn btn-primary">Gửi yêu cầu đổi sang chuyến này</button></form>
@else<p>Vé cũ được xử lý theo trạng thái và hồ sơ hoàn hiện có. Bạn có thể đặt vé mới cho chuyến được đề xuất.</p><a class="btn btn-primary" href="/trip/select?{{ http_build_query(['flights'=>$target->id,'fare_class'=>$fare->code,'trip_type'=>'ONE_WAY','passenger_count'=>1]) }}">Xem chi tiết để đặt chuyến mới</a>@endif
<a class="d-block mt-3" href="/journeys/{{ $booking->id }}">Theo dõi vé hiện tại</a></section></div>
@endsection
