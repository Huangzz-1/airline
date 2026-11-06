@php
    $start=$direction[0]->flight;
    $end=$direction[count($direction)-1]->flight;
    $minutes=(int)\Carbon\Carbon::parse($start->departure)->diffInMinutes(\Carbon\Carbon::parse($end->arrival));
    $duration=intdiv($minutes,60).'h '.($minutes%60).'m';
@endphp
<section class="trip-preview-direction" data-preview-direction="{{ $directionKey }}" aria-label="{{ $label }}">
    <div class="trip-preview-direction-heading"><h3>{{ $label }}</h3><span>{{ $ui::date($start->departure,'d/m/Y') }}</span><span class="trip-preview-total-time">{{ $duration }}</span></div>
    <p class="trip-preview-stop-count">{{ count($direction)===1 ? 'Bay thẳng' : (count($direction)-1).' điểm dừng' }}</p>
    <ol class="trip-preview-timeline">
        @foreach($direction as $index=>$segment)
            @php($flight=$segment->flight)
            <li class="trip-preview-leg">
                <div class="trip-preview-airport"><time datetime="{{ \Carbon\Carbon::parse($flight->departure)->toIso8601String() }}">{{ $ui::date($flight->departure,'H:i') }}<small>{{ $ui::date($flight->departure,'d/m') }}</small></time><span class="trip-preview-dot" aria-hidden="true"></span><div><strong>{{ $flight->origin }}</strong> {{ $airports[$flight->origin]->name ?? '' }}<small>{{ $airports[$flight->origin]->city ?? '' }}</small></div></div>
                <p class="trip-preview-flight"><span aria-hidden="true">✈</span> {{ \App\Modules\Flights\Carrier::name($flight->carrier_code ?? 'AS') }} <strong>{{ $flight->number }}</strong></p>
                <div class="trip-preview-airport is-arrival"><time datetime="{{ \Carbon\Carbon::parse($flight->arrival)->toIso8601String() }}">{{ $ui::date($flight->arrival,'H:i') }}<small>{{ $ui::date($flight->arrival,'d/m') }}</small></time><span class="trip-preview-dot" aria-hidden="true"></span><div><strong>{{ $flight->destination }}</strong> {{ $airports[$flight->destination]->name ?? '' }}<small>{{ $airports[$flight->destination]->city ?? '' }}</small></div></div>
            </li>
            @if($index<count($direction)-1)
                @php($wait=(int)\Carbon\Carbon::parse($flight->arrival)->diffInMinutes(\Carbon\Carbon::parse($direction[$index+1]->flight->departure)))
                <li class="trip-preview-layover"><strong>Nối chuyến tại {{ $airports[$flight->destination]->city ?? $flight->destination }} ({{ $flight->destination }})</strong><span>Thời gian chờ: {{ intdiv($wait,60) }}h {{ $wait%60 }}m</span>@if(($flight->carrier_code ?? 'AS')!==($direction[$index+1]->flight->carrier_code ?? 'AS'))<span>Tự nối chuyến · Đổi hãng, kiểm tra hành lý và làm thủ tục lại.</span>@endif</li>
            @endif
        @endforeach
    </ol>
    <details class="trip-preview-more"><summary>Hiện thêm</summary><dl>@foreach($direction as $segment)<div><dt>{{ $segment->flight->number }}</dt><dd>{{ $segment->fare->name }} · {{ $ui::money($segment->fare->price) }}@if(isset($aircraft[$segment->flight->aircraft_id]))<br>{{ $aircraft[$segment->flight->aircraft_id]->type }}@endif @if($segment->flight->gate && $segment->flight->gate!=='TBD') · Cửa {{ $segment->flight->gate }}@endif@include('business.fare-summary',['rulesJson'=>$segment->fare->rules ?? null])</dd></div>@endforeach</dl></details>
</section>
