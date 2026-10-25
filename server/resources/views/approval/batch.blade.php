@extends('approval.layout')
@section('title', 'Hojas pendientes')
@section('content')
<div class="card"><h1>Hojas pendientes de {{ $approver->name }}</h1>
@if($rows->isEmpty())<p>No tienes hojas pendientes.</p>@else
<form method="post" action="/b/{{ $plain }}/approve-all" onsubmit="return confirm('Aprobar todas las que no pasan del límite?')">@csrf
@foreach($rows as $r)@if($r['bulk_ok'])<input type="hidden" name="items[]" value="{{ $r['sheet']->id }}:{{ $r['sheet']->version }}">@endif @endforeach
<button class="btn" type="submit">Aprobar todas ({{ $rows->where('bulk_ok', true)->count() }})</button>
<p class="muted">Las que pasan del límite de horas, o cambiaron después de verse, se revisan una por una.</p></form>
@endif</div>
@foreach($rows as $r)@php $s = $r['summary']; $sheet = $r['sheet']; @endphp
<div class="card">
<h2>{{ $s['worker'] }} · semana {{ $s['week_ending'] }} · {{ number_format($s['total_hours'], 2) }} h</h2>
@if($s['late'])<div class="msg warn">Enviada tarde.</div>@endif
@foreach($s['warnings'] as $w)<div class="msg warn">{{ $w['message'] }}</div>@endforeach
@unless($r['bulk_ok'])<div class="msg warn">Pasa del límite de horas: se aprueba sola, no en bloque.</div>@endunless
<table>@foreach($s['projects'] as $p)<tr><td>{{ $p['project'] }}</td><td class="r">{{ number_format($p['hours'], 2) }} h</td></tr>@endforeach</table>
<div class="row" style="margin-top:12px">
<form method="post" action="/b/{{ $plain }}/sheet/{{ $sheet->id }}/approve">@csrf<input type="hidden" name="version" value="{{ $sheet->version }}"><button class="btn small" type="submit">Aprobar</button></form>
<form method="post" action="/b/{{ $plain }}/sheet/{{ $sheet->id }}/return" style="flex:1;min-width:240px">@csrf<input type="hidden" name="version" value="{{ $sheet->version }}">
<input type="text" name="comment" placeholder="Qué corregir (obligatorio)" required minlength="3"> <button class="btn sec small" type="submit" style="margin-top:6px">Devolver</button></form>
</div></div>
@endforeach
@endsection
