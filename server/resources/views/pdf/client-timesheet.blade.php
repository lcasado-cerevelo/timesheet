<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<style>
@page { margin: 36px 34px 46px 34px; }
body{font-family:'DejaVu Sans',sans-serif;font-size:10px;color:#222}
.head{border-bottom:3px solid {{ $brand['color_primary'] }};padding-bottom:8px;margin-bottom:12px}
.head img{max-height:44px;max-width:210px}
h1{font-size:19px;margin:0;color:{{ $brand['color_primary'] }}}
h2{font-size:12px;margin:14px 0 4px;color:{{ $brand['color_primary'] }}}
table{width:100%;border-collapse:collapse;table-layout:fixed}
th{background:{{ $brand['color_primary'] }};color:#fff;text-align:left;padding:5px 6px;font-size:9px}
td{padding:5px 6px;border-bottom:1px solid #ddd;vertical-align:top;word-wrap:break-word;overflow-wrap:break-word}
.r{text-align:right}.sub td{border-bottom:0;font-weight:bold;background:#f3f4f6}
.tot td{font-size:12px;font-weight:bold;border-top:2px solid {{ $brand['color_primary'] }};border-bottom:0}
.meta td{border:0;padding:1px 0}
.muted{color:#777}.warn{color:#b26a00}
.foot{margin-top:18px;color:#777;font-size:8.5px}
</style>
</head>
<body>
<div class="head">
<table class="meta"><tr>
<td style="width:55%">@if($logo)<img src="{{ $logo }}">@else<h1>{{ $brand['name'] }}</h1>@endif</td>
<td class="r"><h1>{{ __('Hoja de tiempo') }}</h1></td>
</tr></table>
</div>

<table class="meta">
<tr><td style="width:14%"><b>{{ __('Cliente') }}</b></td><td>{{ $d['customer'] }}</td></tr>
<tr><td><b>{{ __('Período') }}</b></td><td>{{ __(':from a :to', ['from' => $d['from'], 'to' => $d['to']]) }}</td></tr>
<tr><td><b>{{ __('Emitida') }}</b></td><td>{{ __(':date por :company', ['date' => $d['generated_on'], 'company' => $d['company']]) }}</td></tr>
</table>

@if(empty($d['projects']))
<p class="muted" style="margin-top:20px">{{ __('No hay horas para este cliente en el período.') }}</p>
@endif

@foreach($d['projects'] as $p)
<h2>{{ $p['code'] }} · {{ $p['name'] }}</h2>
<table>
<colgroup><col style="width:11%"><col style="width:17%">@if($p['has_times'])<col style="width:13%">@endif<col style="width:8%"><col>@if($d['show_amounts'])<col style="width:11%">@endif</colgroup>
<tr><th>{{ __('Fecha') }}</th><th>{{ __('Persona') }}</th>@if($p['has_times'])<th>{{ __('Horario') }}</th>@endif<th class="r">{{ __('Horas') }}</th><th>{{ __('Descripción') }}</th>@if($d['show_amounts'])<th class="r">{{ __('Importe') }}</th>@endif</tr>
@foreach($p['lines'] as $l)
<tr>
<td>{{ $l['date'] }}</td>
<td>{{ $l['worker'] }}</td>
@if($p['has_times'])<td>{{ $l['entered_as'] === 'times' ? $l['start'].'–'.$l['end'] : '' }}</td>@endif
<td class="r">{{ number_format($l['hours'], 2) }}</td>
<td>{!! nl2br(e($l['description'])) !!}@unless($l['approved']) <span class="warn">({{ __('sin aprobar') }})</span>@endunless</td>
@if($d['show_amounts'])<td class="r">${{ number_format($l['amount'], 2) }}</td>@endif
</tr>
@endforeach
<tr class="sub"><td colspan="{{ $p['has_times'] ? 3 : 2 }}">{{ __('Subtotal :code', ['code' => $p['code']]) }}@if(count($p['by_worker']) > 1) — @foreach($p['by_worker'] as $w){{ $w['worker'] }}: {{ number_format($w['hours'], 2) }} h{{ $loop->last ? '' : ' · ' }}@endforeach @endif</td>
<td class="r">{{ number_format($p['hours'], 2) }}</td><td></td>@if($d['show_amounts'])<td class="r">${{ number_format($p['amount'], 2) }}</td>@endif</tr>
</table>
@endforeach

@if(! empty($d['projects']))
<table style="margin-top:12px"><tr class="tot"><td style="width:50%">{{ __('TOTAL DE HORAS') }}</td><td class="r">{{ number_format($d['total_hours'], 2) }} h</td>@if($d['show_amounts'])<td class="r">${{ number_format($d['total_amount'], 2) }}</td>@endif</tr></table>
@endif

<p class="foot">
@if($d['has_pending'])<span class="warn">{{ __('Incluye horas aún sin aprobar, marcadas como tales.') }}</span><br>@else {{ __('Todas las horas listadas están aprobadas.') }}@if(count($d['approved_by'])) {{ __('Aprobadas por: :names.', ['names' => implode(', ', $d['approved_by'])]) }}@endif<br>@endif
@if(! empty($d['signatures']))<br>{{ __('Aprobada digitalmente por :names.', ['names' => implode(', ', array_map(fn ($x) => $x['by'].' ('.$x['at'].')', $d['signatures']))]) }}@endif
{{ $brand['email_footer'] }}
</p>
</body>
</html>
