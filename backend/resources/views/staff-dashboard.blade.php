@extends('layouts.app')
@section('content')
<div class="eyebrow">{{ \App\Support\Ui::role(auth()->user()->role) }} @if(auth()->user()->airport_code) · {{ auth()->user()->airport_code }} @endif</div>
<h1>Bàn làm việc</h1><p class="subtle">Xin chào {{ auth()->user()->name }}. Chọn công việc cần xử lý.</p>
<div class="kpi-grid">@foreach($kpis as $label=>$value)<section class="card-surface"><div class="subtle">{{ $label }}</div><div class="kpi-value">{{ $value }}</div></section>@endforeach</div>
<div class="action-grid">@foreach($tiles as $tile)<a class="card-surface workspace-tile" href="{{ $tile['url'] }}"><h2>{{ $tile['title'] }}</h2><p class="subtle">{{ $tile['description'] }}</p><span class="tile-link">Mở công việc →</span></a>@endforeach</div>
@endsection
