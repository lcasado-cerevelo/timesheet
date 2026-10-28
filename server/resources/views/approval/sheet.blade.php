@extends('approval.layout')
@section('title', __('Hoja de :name', ['name' => $summary['worker']]))
@section('content')
<div class="card">
<h1>{{ $summary['worker'] }}</h1>
<p>{!! __('Semana que termina el <strong>:week</strong> · Total <strong>:hours h</strong>', ['week' => e($summary['week_ending']), 'hours' => number_format($summary['total_hours'], 2)]) !!}</p>
@if($summary['late'])<div class="msg warn">{{ __('Esta hoja se envió después de la fecha límite.') }}</div>@endif
@foreach($summary['warnings'] as $w)<div class="msg warn">{{ $w['message'] }}</div>@endforeach
@php($sent = collect($signatures ?? [])->firstWhere('action', 'sheet.submit'))
@if($sent)<p class="muted">{{ __('Enviada y firmada digitalmente por :name el :date.', ['name' => $sent['by'], 'date' => substr($sent['at'], 0, 10)]) }}</p>@endif
@if(!empty($summary['submission_note']))<p><strong>{{ __('Nota del envío') }}:</strong> {{ $summary['submission_note'] }}</p>@endif
<h2>{{ __('Por proyecto') }}</h2>
<table>@foreach($summary['projects'] as $p)<tr><td>{{ $p['project'] }}</td><td class="r">{{ number_format($p['hours'], 2) }} h</td></tr>@endforeach</table>
</div>
<div class="card">
<h2>{{ __('Detalle por día') }}</h2>
<table><tr><th>{{ __('Día') }}</th><th>{{ __('Proyecto') }}</th><th>{{ __('Tarea') }}</th><th>{{ __('Desde–hasta') }}</th><th class="r">{{ __('Horas') }}</th></tr>
@foreach($byDay as $day => $rows)@foreach($rows as $e)
<tr><td>{{ $day }}</td><td>{{ $e->project->code }}@if($e->service) · {{ $e->service->name }}@endif</td><td>{{ $e->task?->name ?? '' }}</td><td>{{ $e->start_time ? substr($e->start_time,0,5).'–'.substr((string)$e->end_time,0,5) : __('Día completo') }}</td><td class="r">{{ number_format((float)$e->hours, 2) }}</td></tr>
@if($e->note)<tr><td colspan="5" class="muted">{{ $e->note }}</td></tr>@endif
@endforeach @endforeach</table>
</div>
<div class="card">
<div class="row">
<form method="post" action="/a/{{ $plain }}/approve">@csrf<button class="btn" type="submit">{{ __('Aprobar') }}</button></form>
</div>
<hr style="border:0;border-top:1px solid #eee;margin:16px 0">
<form method="post" action="/a/{{ $plain }}/return">@csrf
<label>{{ __('Si hay algo que corregir, escribe qué (obligatorio para devolver)') }}<textarea name="comment" rows="3" required minlength="3">{{ old('comment') }}</textarea></label>
<p><button class="btn sec" type="submit">{{ __('Devolver para corregir') }}</button></p></form>
</div>
@endsection
