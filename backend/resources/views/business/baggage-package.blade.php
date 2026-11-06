@php
    $rules=\App\Modules\Booking\FareRules::decode($b->fare_rules ?? null);
    $base=$b->base_amount ?? ($b->amount-$b->baggage_amount-$b->change_fee_amount);
@endphp
@if(!$b->fare_rules)<p class="subtle">Vé cũ chưa ghi nhận định mức hành lý. Liên hệ hỗ trợ để kiểm tra.</p>@else
<div class="business-baggage"><p><strong>Hành lý {{ $b->reference }}</strong>: gồm {{ $rules['included_baggage_kg'] }} kg ký gửi, đã chọn thêm {{ $b->baggage_kg }} kg.</p>
@if($b->status==='HELD' && now()->lt($b->expires_at))<form method="post" action="/journeys/{{ $b->id }}/baggage" class="business-inline">@csrf<label>Mua thêm hành lý<select class="form-select" name="package">@foreach([0,5,10,15,20,25,30,35,40] as $kg)@if($rules['included_baggage_kg']+$kg<=64)<option value="{{ $kg }}:{{ $base+$b->change_fee_amount+$kg*$rules['extra_baggage_price_per_kg'] }}" @selected($b->baggage_kg===$kg)>{{ $kg ? '+'.$kg.' kg' : 'Không mua thêm' }} · {{ $ui::money($kg*$rules['extra_baggage_price_per_kg']) }}</option>@endif @endforeach</select></label><button class="btn btn-outline-primary">Cập nhật hành lý</button></form>@endif
<small class="subtle">Giá vé {{ $ui::money($base) }} · Hành lý thêm {{ $ui::money($b->baggage_amount) }}@if($b->change_fee_amount) · Phí đổi {{ $ui::money($b->change_fee_amount) }}@endif</small></div>

@endif
