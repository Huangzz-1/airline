<div class="cabin-map" aria-label="Sơ đồ cabin và ghế"><p class="subtle">Đầu tàu bay ↑ · Khoảng trống giữa các nhóm ghế là lối đi.</p>
@foreach($seatRows as $row)
    @if($loop->first || $seatRows[$loop->index-1]['cabin']!==$row['cabin'])<h4 class="cabin-title">{{ $row['name'] }}</h4>@endif
    <div class="cabin-row"><span class="cabin-row-number">{{ $row['number'] }}</span>
    @foreach($row['blocks'] as $block)<div class="cabin-block">@foreach($block as $seat)
        <label class="seat-option"><input type="radio" name="seat" value="{{ $seat }}" required @checked(old('seat')===$seat) @disabled(in_array($seat,$occupied,true) || !in_array($seat,$allowedSeats,true))><span>{{ $seat }}</span></label>
    @endforeach</div>@endforeach
    </div>
@endforeach
</div>
