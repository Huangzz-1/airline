@php
    $ui=\App\Support\Ui::class;
    $first=$quote['directions']['outbound'][0]->flight;
    $last=$quote['directions']['outbound'][count($quote['directions']['outbound'])-1]->flight;
    $cityFrom=$airports[$first->origin]->city ?? $first->origin;
    $cityTo=$airports[$last->destination]->city ?? $last->destination;
@endphp
<header class="trip-preview-header">
    <div><span class="trip-preview-kicker">Thông tin chuyến bay</span><h2 id="trip-preview-title">{{ $cityFrom }} <span aria-hidden="true">{{ $data['trip_type']==='ROUND_TRIP' ? '⇄' : '→' }}</span> {{ $cityTo }}</h2></div>
    @if($inModal)<button type="button" class="trip-preview-close" data-trip-preview-close aria-label="Đóng thông tin chuyến bay">×</button>@else<a href="/" class="trip-preview-back">← Tìm chuyến khác</a>@endif
</header>
<div class="trip-preview-directions {{ !empty($quote['directions']['inbound']) ? 'has-return' : '' }}">
    @foreach(['outbound'=>'Khởi hành','inbound'=>'Chuyến về'] as $key=>$label)
        @if(!empty($quote['directions'][$key]))
            @include('partials.trip-preview-direction', ['direction'=>$quote['directions'][$key], 'directionKey'=>$key, 'label'=>$label])
        @endif
    @endforeach
</div>
@if($chooseReturn)<p class="trip-preview-return-note">Bạn đang xem lượt đi. Tiếp tục chọn lượt về để xem lịch trình và tổng giá khứ hồi.</p>@endif
<footer class="trip-preview-footer">
    <div class="trip-preview-type">{{ $chooseReturn ? 'Lượt đi đã chọn' : ($data['trip_type']==='ROUND_TRIP' ? 'Khứ hồi' : 'Một chiều') }}<span>1 hành khách · {{ $chooseReturn ? 'Giá lượt đi' : 'Tổng giá vé' }}</span></div>
    <p class="trip-preview-price"><strong>{{ $ui::money($quote['total']) }}</strong><span>/khách</span></p>
    <a class="btn trip-preview-continue" data-trip-preview-continue href="{{ $continueUrl }}">{{ $chooseReturn ? 'Chọn lượt về' : 'Tiếp tục' }} <span aria-hidden="true">→</span></a>
</footer>
