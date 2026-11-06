@extends('layouts.app')
@section('title',$title.' · Airline Smart')
@section('content')
@php($ui=\App\Support\Ui::class)
@php($biz=\App\Support\BusinessUi::class)
@include('business.heading',['description'=>'Đối chiếu thông tin cũ/mới trước khi duyệt sửa vé đã xuất. Quyết định được lưu cùng người duyệt và lý do.'])
<section class="card-surface business-card"><form method="get" class="business-inline"><label>Trạng thái<select class="form-select" name="status">@foreach(['PENDING'=>'Chờ duyệt','APPROVED'=>'Đã duyệt','REJECTED'=>'Từ chối','APPLIED'=>'Đã cập nhật trước thanh toán'] as $code=>$label)<option value="{{ $code }}" @selected(request('status','PENDING')===$code)>{{ $label }}</option>@endforeach</select></label><button class="btn btn-outline-primary">Lọc yêu cầu</button></form><div class="table-responsive mt-3"><table class="table"><thead><tr><th>Mã đặt chỗ</th><th>Thời gian</th><th>Lý do</th><th>Trạng thái</th><th></th></tr></thead><tbody>@forelse($rows as $row)<tr><td>{{ $reservations[$row->reservation_id] ?? '—' }}</td><td>{{ $ui::date($row->created_at) }}</td><td>{{ $row->reason }}</td><td>{{ $biz::status($row->status) }}</td><td><a href="/workspace/passenger-revisions/{{ $row->id }}">Đối chiếu & duyệt</a></td></tr>@empty<tr><td colspan="5">Chưa có yêu cầu ở trạng thái này.</td></tr>@endforelse</tbody></table></div>{{ $rows->links() }}</section>
@endsection
