@extends('approval.layout')
@section('title', $title)
@section('content')
<div class="card"><h1>{{ $title }}</h1><p>{{ $message }}</p>
@isset($back)<p><a href="{{ $back }}">Volver a la lista</a></p>@endisset</div>
@endsection
