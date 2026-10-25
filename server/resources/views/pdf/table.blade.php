<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
@page { margin: 34px 30px 44px 30px; }
body{font-family:'DejaVu Sans',sans-serif;font-size:9.5px;color:#222}
.head{border-bottom:3px solid {{ $brand['color_primary'] }};padding-bottom:8px;margin-bottom:10px}
.head img{max-height:44px;max-width:210px}
h1{font-size:18px;margin:0;color:{{ $brand['color_primary'] }}}
.sub{color:#666;margin:2px 0 10px}
table{width:100%;border-collapse:collapse}
th{background:{{ $brand['color_primary'] }};color:#fff;text-align:left;padding:5px 6px;font-size:9px}
td{padding:4px 6px;border-bottom:1px solid #ddd;vertical-align:top;word-wrap:break-word;overflow-wrap:break-word}
tr.alt td{background:#f6f7f9}
.r{text-align:right}
.tot td{font-weight:bold;border-top:2px solid {{ $brand['color_primary'] }};border-bottom:0;background:#fff}
.meta{border:0}.meta td{border:0;padding:0}
.foot{margin-top:14px;color:#888;font-size:8px}
.empty{color:#777;margin-top:14px}
</style>
</head>
<body>
<div class="head">
<table class="meta"><tr>
<td style="width:55%">@if($logo)<img src="{{ $logo }}">@else<h1>{{ $brand['name'] }}</h1>@endif</td>
<td class="r" style="vertical-align:bottom"><h1>{{ $t['title'] }}</h1></td>
</tr></table>
</div>
@if(! empty($t['subtitle']))<div class="sub">{{ $t['subtitle'] }}</div>@endif

@if(empty($t['rows']))
<p class="empty">No hay datos para mostrar.</p>
@else
<table>
<tr>@foreach($t['columns'] as $c)<th class="{{ in_array($c['t'], ['hours','money','int']) ? 'r' : '' }}">{{ $c['h'] }}</th>@endforeach</tr>
@foreach($t['rows'] as $n => $r)
<tr class="{{ $n % 2 ? 'alt' : '' }}">@foreach($t['columns'] as $i => $c)<td class="{{ in_array($c['t'], ['hours','money','int']) ? 'r' : '' }}">{!! nl2br(e($x->display($r[$i] ?? null, $c['t']))) !!}</td>@endforeach</tr>
@endforeach
@if(! empty($t['total']))
<tr class="tot">@foreach($t['columns'] as $i => $c)<td class="{{ in_array($c['t'], ['hours','money','int']) ? 'r' : '' }}">{{ $x->display($t['total'][$i] ?? null, $c['t']) }}</td>@endforeach</tr>
@endif
</table>
@endif
<p class="foot">{{ $brand['name'] }} · generado el {{ $when }}@if($brand['email_footer']) · {{ $brand['email_footer'] }}@endif</p>
</body>
</html>
