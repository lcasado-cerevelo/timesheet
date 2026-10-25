@extends('approval.layout')
@section('title', 'Hoja de '.$summary['worker'])
@section('content')
<div class="card">
<h1>{{ $summary['worker'] }}</h1>
<p>Semana que termina el <strong>{{ $summary['week_ending'] }}</strong> · Total <strong>{{ number_format($summary['total_hours'], 2) }} h</strong></p>
@if($summary['late'])<div class="msg warn">Esta hoja se envió después de la fecha límite.</div>@endif
@foreach($summary['warnings'] as $w)<div class="msg warn">{{ $w['message'] }}</div>@endforeach
<h2>Por proyecto</h2>
<table>@foreach($summary['projects'] as $p)<tr><td>{{ $p['project'] }}</td><td class="r">{{ number_format($p['hours'], 2) }} h</td></tr>@endforeach</table>
</div>
<div class="card">
<h2>Detalle por día</h2>
<table><tr><th>Día</th><th>Proyecto</th><th>Desde–hasta</th><th class="r">Horas</th></tr>
@foreach($byDay as $day => $rows)@foreach($rows as $e)
<tr><td>{{ $day }}</td><td>{{ $e->project->code }}</td><td>{{ $e->start_time ? substr($e->start_time,0,5).'–'.substr((string)$e->end_time,0,5) : 'Día completo' }}</td><td class="r">{{ number_format((float)$e->hours, 2) }}</td></tr>
@if($e->note)<tr><td colspan="4" class="muted">{{ $e->note }}</td></tr>@endif
@endforeach @endforeach</table>
</div>
<div class="card">
<div class="row">
<form method="post" action="/a/{{ $plain }}/approve">@csrf<button class="btn" type="submit">Aprobar</button></form>
</div>
<hr style="border:0;border-top:1px solid #eee;margin:16px 0">
<form method="post" action="/a/{{ $plain }}/return">@csrf
<label>Si hay algo que corregir, escribe qué (obligatorio para devolver)<textarea name="comment" rows="3" required minlength="3">{{ old('comment') }}</textarea></label>
<p><button class="btn sec" type="submit">Devolver para corregir</button></p></form>
</div>
@endsection
