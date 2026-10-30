@extends('layouts.app')
@section('content')
<div class="eyebrow">Bộ phận tài chính</div><h1>Giao dịch & đối soát</h1>
<div class="kpi-grid">@foreach($kpis as $label=>$value)<section class="card-surface"><span class="subtle">{{ $label }}</span><div class="kpi-value">{{ $value }}</div></section>@endforeach</div>
<a class="btn btn-primary mb-4" href="/workspace/finance">Xử lý đổi / hoàn vé & kỳ đối soát</a>
<section class="card-surface"><h2>Giao dịch gần đây</h2><div class="table-responsive"><table class="table"><thead><tr><th>Mã đặt chỗ</th><th>Số tiền</th><th>Thanh toán</th><th>Đối chiếu</th><th>Thời điểm</th></tr></thead><tbody>@forelse($payments as $p)<tr><td>{{ $p->reference }}</td><td>{{ \App\Support\Ui::money($p->amount) }}</td><td>{{ \App\Support\Ui::label($p->status) }}</td><td>{{ $p->status==='DECLINED'?'Bị từ chối':($p->matched?'Khớp':'Cần kiểm tra') }}</td><td>{{ \App\Support\Ui::date($p->created_at) }}</td></tr>@empty<tr><td colspan="5">Chưa có giao dịch.</td></tr>@endforelse</tbody></table></div>{{ $payments->links('pagination::bootstrap-5') }}</section>
@endsection
