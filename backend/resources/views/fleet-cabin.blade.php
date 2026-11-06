@extends('layouts.app')
@section('content')
<div class="eyebrow">Đội tàu bay · Cấu hình chỗ ngồi</div><h1>Sơ đồ cabin theo tàu bay</h1>
<p class="subtle">Cấu hình cabin áp dụng cho các chuyến bay mới được công bố. Mỗi chuyến giữ một bản sơ đồ riêng; vé và ghế đã có được bảo toàn theo sơ đồ của chuyến.</p>
<section class="card-surface mb-4"><form method="get" action="/workspace/fleet-cabin"><div class="leg-grid">
    <div><label class="form-label" for="cabin-aircraft">Tàu bay</label><select class="form-select" name="aircraft_id" id="cabin-aircraft" required>@foreach($planes as $p)<option value="{{ $p->id }}" @selected($plane?->id===$p->id)>{{ $p->registration }} · {{ $p->type }} · {{ $p->capacity }} ghế</option>@endforeach</select></div>
    <div><label class="form-label" for="cabin-preset">Sơ đồ muốn xem</label><select class="form-select" name="layout_preset" id="cabin-preset"><option value="">Cấu hình đang lưu</option>@foreach($presets as $key=>$label)<option value="{{ $key }}" @selected(request('layout_preset')===$key)>{{ $label }}</option>@endforeach</select></div>
    <button class="btn btn-primary align-self-end" @disabled(!$plane)>Xem sơ đồ</button>
</div></form></section>
@if($plane)<div class="journey-grid"><section class="card-surface"><h2>{{ $plane->registration }} · {{ $plane->type }}</h2><p>{{ $plane->capacity }} ghế · {{ $preview ? 'Bản xem trước' : 'Cấu hình đang lưu' }}</p><div class="cabin-map"><p class="subtle">Đầu tàu bay ↑</p>
@foreach($seatRows as $row)
    @if($loop->first || $seatRows[$loop->index-1]['cabin']!==$row['cabin'])<h4 class="cabin-title">{{ $row['name'] }}</h4>@endif
    <div class="cabin-row"><span class="cabin-row-number">{{ $row['number'] }}</span>@foreach($row['blocks'] as $block)<div class="cabin-block">@foreach($block as $seat)<span class="seat-preview">{{ $seat }}</span>@endforeach</div>@endforeach</div>
@endforeach
</div></section><aside class="card-surface align-self-start"><h2>Đề nghị thay cấu hình</h2><p>Người quản lý đội tàu khác cần phê duyệt. Tàu còn lịch công bố hiệu lực phải được điều phối trước khi đổi cấu hình.</p><form method="post" action="/workspace/fleet-cabin">@csrf<input type="hidden" name="aircraft_id" value="{{ $plane->id }}"><label class="form-label" for="apply-preset">Sơ đồ cabin</label><select class="form-select mb-3" id="apply-preset" name="layout_preset" required>@foreach($presets as $key=>$label)<option value="{{ $key }}" @selected(request('layout_preset')===$key)>{{ $label }}</option>@endforeach</select><label class="form-label" for="cabin-reason">Lý do điều chỉnh</label><textarea class="form-control mb-3" id="cabin-reason" name="reason" required minlength="5" maxlength="255">{{ old('reason') }}</textarea><button class="btn btn-primary w-100">Gửi đề nghị phê duyệt</button></form><a class="d-block mt-3" href="/workspace/fleet">← Hồ sơ đội tàu</a></aside></div>@else<p class="card-surface">Hãy thêm tàu bay trong danh mục đội tàu trước.</p>@endif
@endsection
