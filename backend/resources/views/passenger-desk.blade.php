@extends('layouts.app')
@section('content')
<div class="eyebrow">Dịch vụ hành khách · {{ auth()->user()->airport_code }}</div><h1>Phục vụ hành khách</h1>
<section class="card-surface mb-4"><form method="get" class="d-flex gap-3 flex-wrap"><div class="flex-grow-1"><label class="form-label" for="passenger-search">Mã đặt chỗ, họ tên hoặc email</label><input class="form-control" name="q" id="passenger-search" value="{{ request('q') }}" required maxlength="100" autocomplete="off"></div><button class="btn btn-primary align-self-end">Tìm hành khách</button></form></section>
@forelse($bookings as $b)<section class="card-surface mb-4"><div class="d-flex justify-content-between flex-wrap gap-3"><div><h2>{{ $b->passenger_name }} · {{ $b->reference }}</h2><p>{{ \App\Support\Ui::flight($b->flight) }}</p></div><span class="badge-state align-self-start">{{ \App\Support\Ui::label($b->status) }}</span></div>
@if($b->frozen)<p class="alert alert-info">Chuyến đang thay đổi tàu bay. Vui lòng chờ xác nhận trước khi làm thủ tục.</p>
@elseif($b->status==='CONFIRMED')<form method="post" action="/staff/passengers/{{ $b->id }}/checkin">@csrf<label class="form-label" for="seat-{{ $b->reference }}">Ghế còn trống</label><select class="form-select mb-3" name="seat" id="seat-{{ $b->reference }}" required><option value="">Chọn ghế</option>@for($i=0;$i<$b->capacity;$i++) @php($seat=(intdiv($i,6)+1).chr(65+$i%6))@if(!in_array($seat,$b->occupied))<option>{{ $seat }}</option>@endif @endfor</select><button class="btn btn-primary">Làm thủ tục cho hành khách</button></form>
@elseif(in_array($b->status,['CHECKED_IN','BOARDED']))<p>Ghế <strong>{{ $b->seat }}</strong></p><a class="btn btn-primary" href="/journeys/{{ $b->id }}/boarding-pass">In thẻ lên tàu</a>@endif
@if(in_array($b->status,['CONFIRMED','CHECKED_IN','BOARDED']))<a class="btn btn-outline-primary mt-2" href="/journeys/{{ $b->id }}/ticket">Xem vé điện tử</a>@endif</section>
@empty<section class="card-surface empty"><h2>{{ request('q')?'Không tìm thấy hành khách':'Tra cứu để bắt đầu phục vụ' }}</h2><p class="subtle">Nhập mã đặt chỗ, họ tên hoặc email. Chỉ hiển thị hành trình thuộc sân bay được phân công.</p></section>@endforelse
{{ $bookings->links('pagination::bootstrap-5') }}
@endsection
