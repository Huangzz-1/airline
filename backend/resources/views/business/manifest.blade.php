@extends('layouts.app')
@section('title','Danh sách lên tàu · Airline Smart')
@section('content')
@php($ui=\App\Support\Ui::class)
@php($biz=\App\Support\BusinessUi::class)
@include('business.heading',['title'=>'Danh sách lên tàu · '.$manifest->data['flight']['number']])
<section class="card-surface business-card"><p>Phiên bản {{ $manifest->version }} · Chốt {{ $ui::date($manifest->created_at) }} · {{ $manifest->data['flight']['origin'] }} → {{ $manifest->data['flight']['destination'] }}</p>@if(!$manifest->current)<p class="alert alert-warning">Dữ liệu hiện tại đã thay đổi so với bản chốt này. Vui lòng chốt phiên bản mới trước khi sử dụng cho chuyến hiện tại.</p>@endif<a class="btn btn-outline-primary mb-3" href="?export=csv">Xuất CSV danh sách này</a>@include('business.manifest-table',['entries'=>$manifest->data['rows']])<a href="/workspace/boarding-control?flight={{ $manifest->flight_id }}">Quay lại quản lý chuyến</a></section>
@endsection
