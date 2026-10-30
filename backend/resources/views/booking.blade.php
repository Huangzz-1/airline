@extends('layouts.app')
@section('content')
@php($ui=\App\Support\Ui::class)
@php($frozen=(bool)$inventory->frozen_change_id)
@php($departure=\Carbon\Carbon::parse($flight->departure))
@php($canCheckin=now()->gte($departure->copy()->subHours(24)) && now()->lt($departure->copy()->subMinutes(30)) && in_array($flight->status,['SCHEDULED','DELAYED','BOARDING']))
@php($canChange=now()->lt($departure->copy()->subMinutes(30)) && in_array($flight->status,['SCHEDULED','DELAYED','BOARDING']))
<div class="eyebrow">Mã đặt chỗ · {{ $booking->reference }}</div>
<h1>{{ $flight->origin }} → {{ $flight->destination }}</h1>
<div class="steps"><span>01 · Đặt chỗ</span><span>02 · Thanh toán</span><span>03 · Làm thủ tục</span><span>04 · Lên tàu bay</span></div>
@if($frozen)<p class="alert alert-info">Chuyến đang cập nhật tàu bay và chỗ ngồi. Vui lòng chờ hoàn tất trước khi thực hiện thao tác tiếp theo.</p>@endif
<div class="journey-grid"><section class="card-surface">
<div class="d-flex justify-content-between flex-wrap gap-2"><h2>✈ {{ $flight->number }}</h2><span class="badge-state status-{{ $booking->status }}">{{ $ui::label($booking->status) }}</span></div>
<p>{{ $ui::date($flight->departure) }} · Cửa {{ $flight->gate }}</p><hr>
<p>Hành khách: <strong>{{ auth()->user()->name }}</strong></p><p>Hạng vé: {{ $ui::label($booking->fare_class) }}</p>
<p>Tổng thanh toán: <strong class="price">{{ $ui::money($booking->amount) }}</strong></p>
@if($ticket)<p>Số vé: <strong>{{ $ticket->number }}</strong></p>@endif
@if(in_array($booking->status,['CONFIRMED','CHECKED_IN','BOARDED']))<a class="btn btn-outline-primary mb-3" href="/journeys/{{ $booking->id }}/ticket">Xem / In vé điện tử</a>@endif
@if($booking->status==='HELD' && $exchange && $exchange->target_booking_id===$booking->id && $exchange->status==='PENDING')
<p class="alert alert-info">Chỗ này thuộc yêu cầu đổi vé, đang chờ phê duyệt. Vé hiện tại vẫn được giữ nguyên. Hạn xác nhận: {{ $ui::date($booking->expires_at) }}.</p>
<form method="post" action="/journeys/{{ $booking->id }}/cancel">@csrf<button class="btn btn-outline-secondary" @disabled($frozen)>Rút yêu cầu đổi vé</button></form>
@elseif($booking->status==='HELD')
<p class="alert alert-warning">Vui lòng thanh toán trước {{ $ui::date($booking->expires_at,'H:i:s d/m/Y') }} để giữ chỗ.</p>
@if(!$frozen && now()->lt($booking->expires_at))<form method="post" action="/journeys/{{ $booking->id }}/pay">@csrf @if($paymentMethods)<label class="form-label" for="payment-method">Phương thức thanh toán</label><select class="form-select mb-3" id="payment-method" name="method" required>@foreach($paymentMethods as $method=>$label)<option value="{{ $method }}">{{ $label }}</option>@endforeach</select><button class="btn btn-primary w-100">Thanh toán {{ $ui::money($booking->amount) }}</button>@else<p class="alert alert-info">Thanh toán trực tuyến chưa sẵn sàng. Vui lòng liên hệ hỗ trợ để được hướng dẫn.</p>@endif</form>
@if($alternatives->isNotEmpty())<details class="mt-4"><summary>Đổi chuyến trước thanh toán</summary><form method="post" action="/workspace/actions/change_flight">@csrf<input type="hidden" name="booking_id" value="{{ $booking->id }}"><input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}"><label class="form-label mt-3" for="new_flight">Chuyến cùng đường bay</label><select class="form-select mb-3" name="flight_id" id="new_flight" required>@foreach($alternatives as $target)<option value="{{ $target->id }}">{{ $ui::flight($target) }}</option>@endforeach</select><p class="subtle">Áp dụng giá chuyến mới. Thời hạn giữ chỗ ban đầu vẫn giữ nguyên.</p><button class="btn btn-outline-primary">Đổi chuyến giữ chỗ</button></form></details>@endif
@endif
<form method="post" class="mt-3" action="/journeys/{{ $booking->id }}/cancel">@csrf<button class="btn btn-outline-secondary" @disabled($frozen)>Hủy giữ chỗ</button></form>
@elseif($booking->status==='PAYMENT_PENDING')@include('partials.checkout')<a class="btn btn-primary" href="/journeys/{{ $booking->id }}">Cập nhật trạng thái</a>
@elseif($booking->status==='CONFIRMED')
@if($canCheckin && !$frozen)<p class="alert alert-success">Vé đã xuất. Bạn có thể làm thủ tục và chọn ghế ngay.</p><form method="post" action="/journeys/{{ $booking->id }}/checkin">@csrf<h3>Chọn ghế</h3><p class="subtle">Ghế màu xám đã có hành khách. Chọn một ghế còn trống.</p><div class="seat-grid">@for($i=0;$i<$inventory->capacity;$i++) @php($seat=(intdiv($i,6)+1).chr(65+$i%6))<label class="seat-option"><input type="radio" name="seat" value="{{ $seat }}" required @disabled(in_array($seat,$occupied))><span>{{ $seat }}</span></label>@endfor</div><button class="btn btn-primary w-100">Xác nhận check-in</button></form>
@elseif(now()->lt($departure->copy()->subHours(24)))<p class="alert alert-info">Làm thủ tục trực tuyến mở lúc {{ $ui::date($departure->copy()->subHours(24)) }} và đóng trước giờ bay 30 phút.</p>
@elseif(!$frozen)<p class="alert alert-info">Làm thủ tục trực tuyến đã đóng hoặc chuyến chưa sẵn sàng. Vui lòng liên hệ nhân viên sân bay.</p>@endif
@elseif($booking->status==='EXCHANGE_PENDING')<p class="alert alert-info">Yêu cầu đổi vé đang được xử lý. Bạn sẽ nhận thông báo khi có kết quả.</p>
@elseif($booking->status==='REFUND_PENDING')<p class="alert alert-info">Yêu cầu hoàn tiền đang được xử lý. Thẻ lên tàu cũ đã hết hiệu lực.</p>
@elseif($booking->status==='REFUNDED')<p class="alert alert-success">Yêu cầu hoàn tiền đã hoàn tất. Vé không còn hiệu lực.</p>
@elseif(in_array($booking->status,['CANCELLED','EXPIRED']))<p class="alert alert-warning">Đặt chỗ đã kết thúc.</p><a href="/" class="btn btn-primary">Tìm chuyến bay mới</a>
@else<p class="alert alert-success">{{ $booking->status==='BOARDED'?'Đã xác nhận lên tàu bay. Chúc bạn có chuyến bay tốt đẹp.':'Đã làm thủ tục. Vui lòng dùng thẻ lên tàu bên cạnh và đến cửa đúng giờ.' }}</p>@endif
<a class="d-block mt-4" href="/workspace/customer">Liên hệ hỗ trợ</a>
</section>
<aside class="card-surface boarding-card">
@if($booking->status==='REFUND_PENDING' && $booking->payment_method && !$frozen) @include('partials.refund-destination') @endif
@if($pass && !$frozen && !$pass->revoked_at)<div class="eyebrow">Thẻ lên tàu bay</div><h2>{{ $flight->number }} · Ghế {{ $booking->seat }}</h2><div aria-label="Mã QR thẻ lên tàu">{!! $qr !!}</div><p class="subtle">Cửa {{ $flight->gate }} · Cửa đóng lúc {{ $ui::date($departure->copy()->subMinutes(10),'H:i') }}</p><a class="btn btn-primary mb-4" href="/journeys/{{ $booking->id }}/boarding-pass">In / Lưu thẻ lên tàu</a>
@else<div class="eyebrow">Thông tin hành trình</div><h2>Chuẩn bị cho chuyến bay</h2><p class="subtle mt-3">Thẻ lên tàu được cấp sau khi làm thủ tục thành công.</p><hr><p>Mang giấy tờ tùy thân phù hợp.</p><p>Kiểm tra giờ khởi hành và cửa lên tàu.</p>@endif
@if($baggage->isNotEmpty())<hr><h3>Hành lý của bạn</h3>@foreach($baggage as $bag)<p><a href="/baggage/{{ $bag->id }}">{{ $bag->tag }}</a> · {{ $ui::label($bag->status) }}</p>@endforeach @endif
@if($exchange)<p class="alert alert-info mt-3">Đổi vé: {{ $ui::label($exchange->status) }}.<br>Vé mới {{ $ui::money($exchange->target_amount) }} · Vé cũ {{ $ui::money($exchange->source_amount) }}.</p>@endif
@if(!$frozen && $canChange && in_array($booking->status,['CONFIRMED','CHECKED_IN']))
@if($alternatives->isNotEmpty())<details class="mb-4 text-start"><summary>Đề nghị đổi chuyến bay</summary><form method="post" action="/workspace/actions/exchange_request">@csrf<input type="hidden" name="booking_id" value="{{ $booking->id }}"><input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}"><label class="form-label mt-3" for="exchange_flight">Chuyến mới cùng đường bay</label><select class="form-select mb-3" id="exchange_flight" name="flight_id" required>@foreach($alternatives as $f)<option value="{{ $f->id }}">{{ $ui::flight($f) }} · {{ $ui::money($f->price) }}</option>@endforeach</select><label class="form-label" for="exchange_reason">Lý do đổi</label><textarea class="form-control mb-3" id="exchange_reason" name="reason" required minlength="5" maxlength="255"></textarea><p class="subtle">Yêu cầu cần phê duyệt. Chỉ hoàn vé cũ sau khi vé mới được thanh toán thành công; nếu không thành công, vé cũ vẫn có hiệu lực.</p><button class="btn btn-outline-primary">Gửi yêu cầu đổi vé</button></form></details>@endif
<details class="mb-4 text-start"><summary>Yêu cầu hoàn tiền</summary><form method="post" action="/workspace/actions/refund_request">@csrf<input type="hidden" name="booking_id" value="{{ $booking->id }}"><label class="form-label mt-3" for="refund_reason">Lý do hoàn vé</label><textarea class="form-control mb-3" name="reason" id="refund_reason" minlength="5" maxlength="255" required></textarea><p class="subtle">Hoàn toàn bộ giá vé sau phê duyệt. Gửi yêu cầu trước giờ khởi hành ít nhất 30 phút.</p><button class="btn btn-outline-primary">Gửi yêu cầu hoàn</button></form></details>
@if($pass && $booking->status==='CHECKED_IN')<details class="text-start"><summary>Đổi ghế / Cấp lại thẻ lên tàu</summary><form method="post" action="/workspace/actions/change_seat">@csrf<input type="hidden" name="booking_id" value="{{ $booking->id }}"><label class="form-label mt-3" for="change_seat">Ghế mới</label><select class="form-select mb-3" id="change_seat" name="seat" required>@for($i=0;$i<$inventory->capacity;$i++) @php($seat=(intdiv($i,6)+1).chr(65+$i%6))@if($seat===$booking->seat || !in_array($seat,$occupied))<option @selected($seat===$booking->seat)>{{ $seat }}</option>@endif @endfor</select><p class="subtle">Thẻ cũ sẽ hết hiệu lực. Giữ ghế hiện tại nếu bạn chỉ muốn cấp lại thẻ.</p><button class="btn btn-outline-primary">Xác nhận ghế và cấp thẻ mới</button></form></details>@endif
@endif
</aside></div>
@endsection
