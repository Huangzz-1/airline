@extends('layouts.app')
@section('title', 'Mã đặt chỗ '.$reservation->reference.' · Airline Smart')
@section('content')
@php($ui=\App\Support\Ui::class)
@if($status==='HELD')<nav class="trip-booking-steps"><span>✓ Chọn chuyến</span><span>✓ Thông tin hành khách</span><strong aria-current="step">3 · Thanh toán</strong></nav>@endif
<div class="eyebrow">Mã đặt chỗ · {{ $reservation->reference }}</div><h1>{{ $status==='HELD' ? 'Thanh toán chuyến đi' : ($reservation->trip_type==='ROUND_TRIP' ? 'Chuyến đi khứ hồi' : 'Chuyến đi của bạn') }}</h1>@if($reservation->trip_type==='ROUND_TRIP')<span class="round-trip-badge mb-3">Khứ hồi</span>@endif
<p class="subtle">{{ $passengers->count() }} hành khách · {{ $segments->count() }} chặng · Thanh toán chung, vé và ghế riêng cho từng người.</p>
<p><a href="/reservations/{{ $reservation->id }}/details">Thông tin hành khách & lịch sử sửa</a></p>
<div class="journey-grid"><section>@foreach($segments as $segment)
@php($scheduled=$flights[$segment->flight_id] ?? null)
<section class="card-surface mb-4"><div class="eyebrow">{{ $reservation->trip_type==='ROUND_TRIP' && $segment->position>($reservation->outbound_count ?? 1) ? 'Lượt về' : 'Lượt đi' }} · Chuyến bay {{ $segment->position }}</div><h2>{{ $scheduled ? $ui::flight($scheduled) : 'Lịch trình đã cập nhật' }}</h2><p class="subtle">{{ $scheduled ? $ui::date($scheduled->departure) : '' }}</p>
    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Hành khách</th><th>Hạng vé / ghế</th><th>Trạng thái</th><th>Vé</th></tr></thead><tbody>
    @foreach($bookings->where('reservation_segment_id',$segment->id) as $b)<tr><td><strong>{{ $passengers[$b->passenger_id]->name }}</strong><div class="subtle">{{ $b->reference }}</div>@if($b->flight_id!==$segment->flight_id)<small>Chuyển sang {{ $ui::flight($flights[$b->flight_id]) }}</small>@endif</td><td>{{ $ui::label($b->fare_class) }}<div>Ghế {{ $b->seat ?? 'chưa chọn' }}</div></td><td><span class="badge-state">{{ $ui::label($b->status) }}</span></td><td><a href="/journeys/{{ $b->id }}">Chi tiết / Chọn ghế</a>@if(in_array($b->status,['CONFIRMED','CHECKED_IN','BOARDED']))<br><a href="/journeys/{{ $b->id }}/ticket">In vé</a>@endif</td></tr>@endforeach
    </tbody></table></div>
    @foreach($bookings->where('reservation_segment_id',$segment->id)->where('reservation_initial',true) as $b) @include('business.fare-summary',['rulesJson'=>$b->fare_rules,'legacy'=>true]) @if(in_array($b->status,['HELD','PAYMENT_PENDING','CONFIRMED','CHECKED_IN'])) @include('business.baggage-package',['b'=>$b]) @endif @endforeach
</section>@endforeach</section><aside class="card-surface align-self-start">
    <span class="badge-state">{{ $status==='PARTIAL' ? 'Một số vé đã thay đổi trạng thái' : $ui::label($status) }}</span><h2 class="mt-3">Tổng tiền vé và dịch vụ</h2><p class="price">{{ $ui::money($reservation->total_amount) }}</p>
    @if($status==='HELD')
    <p class="alert alert-warning">Thanh toán trước {{ $ui::date($reservation->expires_at,'H:i:s d/m/Y') }} để giữ đủ chỗ trên tất cả các chặng.</p>
    <form method="post" action="/reservations/{{ $reservation->id }}/pay">@csrf<label class="form-label" for="group-payment">Phương thức thanh toán</label><select class="form-select mb-3" id="group-payment" name="method" required>@foreach($paymentMethods as $method=>$label)<option value="{{ $method }}">{{ $label }}</option>@endforeach</select><button class="btn btn-primary w-100" @disabled(!$paymentMethods)>Thanh toán toàn bộ hành trình</button></form>
    <form class="mt-3" method="post" action="/reservations/{{ $reservation->id }}/cancel">@csrf<button class="btn btn-outline-secondary w-100">Hủy toàn bộ giữ chỗ</button></form>
    @elseif($status==='PAYMENT_PENDING')
    @php($booking=(object)['id'=>$bookings->where('reservation_initial',true)->first()->id,'amount'=>$reservation->total_amount])
    @include('partials.checkout')<a class="btn btn-outline-primary mt-3" href="/reservations/{{ $reservation->id }}">Cập nhật trạng thái</a>
    @elseif(in_array($status,['EXPIRED','CANCELLED']))
    <p>Giữ chỗ đã kết thúc. Bạn có thể chọn lại các chuyến đang mở bán.</p><a class="btn btn-primary" href="/">Đặt chuyến đi mới</a>
    @elseif($canCancel)
    <p class="alert alert-warning">Một chặng đã hết hiệu lực. Hãy hủy các chỗ còn giữ và chọn lại hành trình.</p><form method="post" action="/reservations/{{ $reservation->id }}/cancel">@csrf<button class="btn btn-outline-secondary w-100">Hủy các chỗ chưa thanh toán</button></form>
    @else
    <p>Mở từng vé để chọn ghế, làm thủ tục, in thẻ lên tàu hoặc gửi yêu cầu đổi/hoàn.</p><p class="subtle">Đổi hoặc hoàn sau thanh toán được theo dõi riêng theo từng hành khách và chặng; tổng tiền đặt ban đầu được giữ làm thông tin đối chiếu.</p>
    @endif
    <hr>@php($tripContact=\App\Modules\Booking\TripDetails::decode($reservation->contact_details))@if($tripContact)<p class="subtle">Liên hệ: {{ $tripContact['name'] }} · {{ $tripContact['phone'] }}<br>Email nhận thông báo: {{ $tripContact['email'] }}.</p>@else<p class="subtle">Thông báo gửi về email của tài khoản đặt vé: {{ auth()->user()->email }}.</p>@endif<a href="/journeys">← Hành trình của tôi</a>
</aside></div>
@endsection
