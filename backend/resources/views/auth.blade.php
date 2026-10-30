@extends('layouts.app')
@section('content')
<div class="auth-panel card-surface"><div class="eyebrow">Airline Smart</div><h2>{{ ['login'=>'Chào mừng trở lại','register'=>'Tạo tài khoản hành khách','forgot'=>'Quên mật khẩu','reset'=>'Đặt lại mật khẩu'][$mode] }}</h2><p class="subtle">Quản lý hành trình của bạn ở một nơi.</p>
<form method="post" action="{{ ['login'=>'/login','register'=>'/register','forgot'=>'/forgot-password','reset'=>'/reset-password'][$mode] }}">@csrf
@if($mode==='register')<label class="form-label" for="name">Họ và tên</label><input class="form-control" id="name" name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name">@endif
<label class="form-label" for="email">Email</label><input class="form-control" id="email" name="email" type="email" required value="{{ old('email',$email??'') }}" autocomplete="email">
@if($mode!=='forgot')<label class="form-label" for="password">Mật khẩu</label><input class="form-control" id="password" name="password" type="password" required @if($mode!=='login') minlength="10" @endif autocomplete="{{ $mode==='login'?'current-password':'new-password' }}">@endif
@if(in_array($mode,['register','reset']))<label class="form-label" for="password_confirmation">Xác nhận mật khẩu</label><input class="form-control" id="password_confirmation" name="password_confirmation" type="password" required minlength="10" autocomplete="new-password">@endif
@if($mode==='reset')<input type="hidden" name="token" value="{{ $token }}">@endif
@if($mode==='register')<label class="d-flex gap-2 mb-3"><input type="checkbox" name="consent" value="1" required><span>Tôi đồng ý xử lý thông tin để cung cấp dịch vụ đặt vé và chăm sóc khách hàng.</span></label>@endif
<button class="btn btn-primary w-100">{{ ['login'=>'Đăng nhập','register'=>'Đăng ký hành khách','forgot'=>'Gửi hướng dẫn','reset'=>'Lưu mật khẩu mới'][$mode] }}</button></form>
<div class="mt-3 d-flex justify-content-between"><a href="/register">Tạo tài khoản</a><a href="/forgot-password">Quên mật khẩu?</a></div></div>
@endsection
