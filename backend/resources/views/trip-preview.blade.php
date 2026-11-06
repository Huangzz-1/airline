@extends('layouts.app')
@section('title', 'Thông tin chuyến bay · Airline Smart')
@section('content')
<div class="trip-preview-page">@include('partials.trip-preview', ['inModal'=>false])</div>
@endsection
