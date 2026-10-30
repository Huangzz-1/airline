@extends('layouts.app')
@section('content')<section class="card-surface auth-panel"><div class="eyebrow">Không thể thực hiện thao tác</div><h2>Trạng thái đã thay đổi</h2><p role="alert">{{ $exception->getMessage() }}</p><a href="{{ auth()->check()&&auth()->user()->role==='airport_agent'?'/staff':'/journeys' }}" class="btn btn-primary">Quay lại và kiểm tra</a></section>@endsection
