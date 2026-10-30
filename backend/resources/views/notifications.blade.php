@extends('layouts.app')
@section('content')
<div class="eyebrow">Tài khoản cá nhân</div><h1>Thông báo</h1>
@forelse($notices as $notice)<article class="card-surface mb-3 {{ $notice->read_at?'':'notice-unread' }}"><div class="d-flex justify-content-between gap-3 flex-wrap"><h2>{{ $notice->title }}</h2><small class="subtle">{{ \App\Support\Ui::date($notice->created_at) }}</small></div><p>{{ $notice->body }}</p>
@if(!$notice->read_at)<form method="post" action="/workspace/actions/read_notice">@csrf<input type="hidden" name="notification_id" value="{{ $notice->id }}"><button class="btn btn-outline-primary">Đánh dấu đã đọc</button></form>@else<small class="subtle">Đã đọc</small>@endif</article>
@empty<section class="card-surface empty"><h2>Bạn chưa có thông báo mới</h2><p class="subtle">Các cập nhật về hành trình và yêu cầu của bạn sẽ xuất hiện tại đây.</p></section>@endforelse
{{ $notices->links('pagination::bootstrap-5') }}
@endsection
