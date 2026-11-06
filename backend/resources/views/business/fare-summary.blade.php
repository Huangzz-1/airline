@if(!empty($legacy) && empty($rulesJson))<p class="subtle">Điều kiện vé cũ được xử lý theo hồ sơ đã có; chưa ghi nhận bảng hạn mức hành lý.</p>@else
@php($fareRules=\App\Modules\Booking\FareRules::decode($rulesJson ?? null))
<div class="business-fare-summary"><span>Ký gửi: {{ $fareRules['included_baggage_kg'] }} kg</span><span>{{ $fareRules['change_allowed'] ? 'Đổi vé: '.$ui::money($fareRules['change_fee']).', trước '.$fareRules['change_cutoff_minutes'].' phút' : 'Không áp dụng đổi vé' }}</span><span>{{ $fareRules['refund_allowed'] ? 'Hoàn vé: phí '.$ui::money($fareRules['refund_fee']).', trước '.$fareRules['refund_cutoff_minutes'].' phút' : 'Không áp dụng hoàn vé' }}</span></div>

@endif
