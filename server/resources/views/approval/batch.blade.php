@extends('approval.layout')
@section('title', __('Hojas pendientes'))
@section('content')
<div class="card"><h1>{{ __('Hojas pendientes de :name', ['name' => $approver->name]) }}</h1>
@if($rows->isEmpty())<p>{{ __('No tienes hojas pendientes.') }}</p>@else
<form method="post" action="/b/{{ $plain }}/approve-all" onsubmit="return confirm(@js(__('Aprobar todas las que no pasan del límite?')))">@csrf
@foreach($rows as $r)@if($r['bulk_ok'])<input type="hidden" name="items[]" value="{{ $r['sheet']->id }}:{{ $r['sheet']->version }}">@endif @endforeach
<button class="btn" type="submit">{{ __('Aprobar todas (:count)', ['count' => $rows->where('bulk_ok', true)->count()]) }}</button>
<p class="muted">{{ __('Las que pasan del límite de horas, o cambiaron después de verse, se revisan una por una.') }}</p></form>
@endif</div>
@foreach($rows as $r)@php $s = $r['summary']; $sheet = $r['sheet']; @endphp
<div class="card">
<h2>{{ __(':worker · semana :week · :hours h', ['worker' => $s['worker'], 'week' => $s['week_ending'], 'hours' => number_format($s['total_hours'], 2)]) }}</h2>
@if($s['late'])<div class="msg warn">{{ __('Enviada tarde.') }}</div>@endif
@foreach($s['warnings'] as $w)<div class="msg warn">{{ $w['message'] }}</div>@endforeach
@unless($r['bulk_ok'])<div class="msg warn">{{ __('Pasa del límite de horas: se aprueba sola, no en bloque.') }}</div>@endunless
<table>@foreach($s['projects'] as $p)<tr><td>{{ $p['project'] }}</td><td class="r">{{ number_format($p['hours'], 2) }} h</td></tr>@endforeach</table>
<div class="row" style="margin-top:12px">
<form method="post" action="/b/{{ $plain }}/sheet/{{ $sheet->id }}/approve">@csrf<input type="hidden" name="version" value="{{ $sheet->version }}"><button class="btn small" type="submit">{{ __('Aprobar') }}</button></form>
<form method="post" action="/b/{{ $plain }}/sheet/{{ $sheet->id }}/return" style="flex:1;min-width:240px">@csrf<input type="hidden" name="version" value="{{ $sheet->version }}">
<input type="text" name="comment" placeholder="{{ __('Qué corregir (obligatorio)') }}" required minlength="3"> <button class="btn sec small" type="submit" style="margin-top:6px">{{ __('Devolver') }}</button></form>
</div></div>
@endforeach
@endsection
