@extends('layouts.app')
@section('content')<div class="card-surface auth-panel"><h2>Thông tin không hợp lệ</h2><p role="alert">{{ $exception->getMessage() }}</p><a href="{{ auth()->check()&&auth()->user()->role==='airport_agent'?'/staff':'/journeys' }}" class="btn btn-primary">Quay lại</a></div>@endsection
