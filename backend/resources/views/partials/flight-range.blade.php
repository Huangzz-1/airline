@php
    $bounds=$facets['ranges'][$rangeKey];
    $domainMin=$bounds['min'];
    $domainMax=$bounds['max'];
    $requestedLow=$filters[$rangeKey.'_min'] ?? $domainMin;
    $requestedHigh=$filters[$rangeKey.'_max'] ?? $domainMax;
    $low=max($domainMin,min($domainMax,$requestedLow));
    $high=max($domainMin,min($domainMax,$requestedHigh));
    $outside=$low!=$requestedLow || $high!=$requestedHigh;
    $formatRange=fn($n)=>$rangeKey==='price' ? number_format($n,0,',','.').' VND' : ($n>=60 ? intdiv($n,60).'h '.($n%60).'m' : $n.'m');
    $fixed=$domainMin===$domainMax;
@endphp
<fieldset class="flight-range-filter" data-flight-range="{{ $rangeKey }}" data-range-unit="{{ $rangeKey==='price' ? 'money' : 'minutes' }}">
    <legend class="visually-hidden">{{ $rangeLabel }}</legend>
    <div class="flight-range-heading"><span>{{ $rangeCaption ?? $rangeLabel }}</span><output data-range-output aria-live="off">{{ $formatRange($low) }} – {{ $formatRange($high) }}</output></div>
    <div class="flight-dual-range {{ $fixed ? 'is-fixed' : '' }}"><div class="flight-range-track" aria-hidden="true"><span data-range-fill></span><span class="flight-range-handle" data-range-handle="min"></span><span class="flight-range-handle" data-range-handle="max"></span></div>
        <label class="visually-hidden" for="{{ $rangeKey }}-min">{{ $rangeLabel }} tối thiểu</label><input id="{{ $rangeKey }}-min" class="flight-range-input range-lower" type="range" name="{{ $rangeKey }}_min" min="{{ $domainMin }}" max="{{ $domainMax }}" step="1" value="{{ $low }}" form="flight-search-form" data-range-bound="min" aria-valuetext="{{ $formatRange($low) }}" @disabled($fixed)>
        <label class="visually-hidden" for="{{ $rangeKey }}-max">{{ $rangeLabel }} tối đa</label><input id="{{ $rangeKey }}-max" class="flight-range-input range-upper" type="range" name="{{ $rangeKey }}_max" min="{{ $domainMin }}" max="{{ $domainMax }}" step="1" value="{{ $high }}" form="flight-search-form" data-range-bound="max" aria-valuetext="{{ $formatRange($high) }}" @disabled($fixed)>
    </div>
    <div class="flight-range-endpoints" aria-hidden="true"><span>{{ $formatRange($domainMin) }}</span><span>{{ $formatRange($domainMax) }}</span></div>
    @if($outside)<p class="flight-range-hint">Khoảng lọc nằm ngoài dữ liệu hiện có; hãy chọn lại.</p>@endif
    @if($rangeKey==='layover')<p class="flight-range-hint">Tổng thời gian chờ; bay thẳng là 0 phút.</p>@endif
</fieldset>
