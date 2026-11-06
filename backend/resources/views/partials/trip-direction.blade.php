@php
    $ui=\App\Support\Ui::class;
    $firstLeg=$direction[0]->flight;
    $lastLeg=$direction[count($direction)-1]->flight;
    $duration=(int)\Carbon\Carbon::parse($firstLeg->departure)->diffInMinutes(\Carbon\Carbon::parse($lastLeg->arrival));
@endphp
<div class="trip-direction-card">
    <span class="trip-direction-label">{{ $label }}</span>
    <div class="trip-direction-route"><div><strong>{{ $firstLeg->origin }}</strong><span>{{ $ui::date($firstLeg->departure,'d/m/Y') }}</span><b>{{ $ui::date($firstLeg->departure,'H:i') }}</b></div><div class="trip-direction-duration">{{ intdiv($duration,60) }}h {{ $duration%60 }}m<span>─ {{ count($direction)>1 ? (count($direction)-1).' điểm dừng' : 'Bay thẳng' }} ─</span></div><div><strong>{{ $lastLeg->destination }}</strong><span>{{ $ui::date($lastLeg->arrival,'d/m/Y') }}</span><b>{{ $ui::date($lastLeg->arrival,'H:i') }}</b></div></div>
    <p class="trip-direction-carrier">✈ {{ collect($direction)->map(fn($s)=>\App\Modules\Flights\Carrier::name($s->flight->carrier_code ?? 'AS'))->unique()->implode(' + ') }} · {{ $direction[0]->fare->name }}</p>
    @if(empty($compact))<details class="trip-direction-more"><summary>Chi tiết lịch trình</summary>@foreach($direction as $index=>$segment)<div><strong>{{ $segment->flight->number }} · {{ $segment->flight->origin }} → {{ $segment->flight->destination }}</strong><span>{{ $ui::date($segment->flight->departure,'H:i d/m') }} – {{ $ui::date($segment->flight->arrival,'H:i d/m') }}</span></div>@if($index<count($direction)-1)<p class="trip-layover">{{ (int)\Carbon\Carbon::parse($segment->flight->arrival)->diffInMinutes(\Carbon\Carbon::parse($direction[$index+1]->flight->departure)) }} phút nối chuyến tại {{ $segment->flight->destination }}</p>@endif @endforeach</details>@endif
</div>
