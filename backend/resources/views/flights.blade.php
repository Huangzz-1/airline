@extends('layouts.app')
@section('title', 'Đặt chuyến đi · Airline Smart')
@section('content')
<div class="flight-search-page">
    <div class="flight-search-heading"><div><div class="eyebrow">Khám phá hành trình tiếp theo</div><h1>Đặt chuyến đi</h1><p class="subtle">Một chiều hoặc khứ hồi. Chọn chuyến, điền thông tin rồi thanh toán.</p></div></div>
    <section class="search-toolbar card-surface" aria-label="Tìm chuyến bay">
        <form method="get" action="/" id="flight-search-form" data-flight-search data-airport-search data-default-date="{{ now('Asia/Ho_Chi_Minh')->addDay()->toDateString() }}" data-route-network="{{ json_encode($destinationNetwork) }}">
            <input type="hidden" name="sort" value="{{ $filters['sort'] }}"><input type="hidden" name="outbound" value="{{ $filters['outbound'] ?? '' }}">
            <div class="trip-search-settings"><div><label class="form-label" for="search-trip-type">Loại chuyến đi</label><select id="search-trip-type" name="trip_type" class="form-select"><option value="ONE_WAY" @selected(($filters['trip_type'] ?? 'ONE_WAY')==='ONE_WAY')>Một chiều</option><option value="ROUND_TRIP" @selected(($filters['trip_type'] ?? '')==='ROUND_TRIP')>Khứ hồi</option></select></div><span class="single-passenger-note">1 hành khách</span><input type="hidden" name="passenger_count" value="1"></div>
            <div><label class="form-label" for="origin">Điểm khởi hành</label><select class="form-select" id="origin" name="origin" data-airport-origin><option value="">Chọn điểm đi</option>@foreach($airports as $a)<option value="{{ $a->code }}" @selected(($filters['origin'] ?? '')===$a->code)>{{ $a->city }} ({{ $a->code }})</option>@endforeach</select></div>
            <div><label class="form-label" for="destination">Điểm đến</label><select class="form-select" id="destination" name="destination" data-airport-destination @disabled(empty($filters['origin']) || empty($destinationNetwork[$filters['origin']]))><option value="">{{ empty($filters['origin']) ? 'Chọn điểm đi trước' : (empty($destinationNetwork[$filters['origin']]) ? 'Chưa có đường bay khả dụng' : 'Chọn điểm đến') }}</option>@foreach($airports->whereIn('code', $destinationNetwork[$filters['origin'] ?? ''] ?? []) as $a)<option value="{{ $a->code }}" @selected(($filters['destination'] ?? '')===$a->code)>{{ $a->city }} ({{ $a->code }})</option>@endforeach</select></div>
            <div><label class="form-label" for="date" data-trip-date-label>{{ $outboundQuote ? 'Ngày về' : 'Ngày khởi hành' }}</label><input class="form-control" id="date" name="date" type="date" value="{{ $filters['date'] ?? '' }}" min="{{ now('Asia/Ho_Chi_Minh')->toDateString() }}"></div>
            <div data-return-date-field @if(($filters['trip_type'] ?? 'ONE_WAY')!=='ROUND_TRIP' || $outboundQuote) hidden @endif><label class="form-label" for="return-date">Ngày về</label><input class="form-control" id="return-date" name="return_date" type="date" value="{{ $filters['return_date'] ?? '' }}" min="{{ $filters['date'] ?? now('Asia/Ho_Chi_Minh')->toDateString() }}" @disabled(($filters['trip_type'] ?? 'ONE_WAY')!=='ROUND_TRIP' || $outboundQuote)></div>
            <button type="submit" class="btn btn-primary">Tìm chuyến bay →</button>
        </form>
    </section>
    <p class="search-feedback" data-flight-search-feedback role="status" hidden></p>
    <div data-flight-search-results aria-live="polite" aria-busy="false">@include('partials.flight-search-results')</div>
</div>
<dialog class="trip-preview-dialog" data-trip-preview aria-labelledby="trip-preview-title"><div data-trip-preview-content></div></dialog>
@endsection
