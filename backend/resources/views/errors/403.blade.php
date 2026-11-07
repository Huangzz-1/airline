@extends('layouts.app')
@section('title', 'Không đủ thẩm quyền')
@section('content')
<div class="card-surface auth-panel" role="alert">
    <div class="eyebrow">Truy cập bị từ chối</div>
    <h1>Không đủ thẩm quyền</h1>
    <p>{{ $exception->getMessage() ?: 'Tài khoản của bạn chưa được cấp quyền cho chức năng này.' }}</p>
    <a href="{{ auth()->check() && auth()->user()->role !== 'passenger' ? '/staff' : '/' }}" class="btn btn-primary">Quay lại</a>
</div>
@endsection
