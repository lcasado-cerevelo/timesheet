<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<style>
body{font-family:'DejaVu Sans',sans-serif;font-size:11px;color:#222}
.head{border-bottom:3px solid {{ $brand['color_primary'] }};padding-bottom:10px;margin-bottom:16px}
.head img{max-height:48px;max-width:220px}
h1{font-size:20px;margin:0;color:{{ $brand['color_primary'] }}}
table{width:100%;border-collapse:collapse}
th{background:{{ $brand['color_primary'] }};color:#fff;text-align:left;padding:6px}
td{padding:6px;border-bottom:1px solid #ddd}
.r{text-align:right}.tot td{border:0}.big{font-size:14px;font-weight:bold}
.foot{margin-top:30px;color:#777;font-size:9px}
</style>
</head>
<body>
<div class="head">
<table><tr>
<td style="border:0">@if($logo)<img src="{{ $logo }}">@else<h1>{{ $brand['name'] }}</h1>@endif</td>
<td class="r" style="border:0"><h1>{{ __('Factura :number', ['number' => $inv->number]) }}</h1>{{ __('Fecha:') }} {{ $inv->issued_on->toDateString() }}<br>{{ __('Vence:') }} {{ $inv->due_on->toDateString() }}</td>
</tr></table>
</div>
<p><strong>{{ __('De:') }}</strong> {{ $worker->name }} ({{ $worker->email }})<br>
<strong>{{ __('Para:') }}</strong> {{ $company->name }}<br>
<strong>{{ __('Período:') }}</strong> {{ __(':from a :to', ['from' => $inv->period_from->toDateString(), 'to' => $inv->period_to->toDateString()]) }}</p>
<table>
<tr><th>{{ __('Concepto') }}</th><th class="r">{{ __('Horas') }}</th><th class="r">{{ __('Tarifa') }}</th><th class="r">{{ __('Importe') }}</th></tr>
@foreach($inv->lines as $l)
<tr><td>{{ $l['description'] }}</td><td class="r">{{ number_format($l['hours'], 2) }}</td><td class="r">${{ number_format($l['rate'], 2) }}</td><td class="r">${{ number_format($l['amount'], 2) }}</td></tr>
@endforeach
</table>
<table class="tot" style="margin-top:12px;width:55%;margin-left:45%">
<tr><td>{{ __('Total bruto') }}</td><td class="r">${{ number_format((float) $inv->gross, 2) }}</td></tr>
@if((float) $inv->retention > 0)<tr><td>{{ __('Retención servicios profesionales (:pct%)', ['pct' => rtrim(rtrim((string) $terms->retention_pct, '0'), '.')]) }}</td><td class="r">−${{ number_format((float) $inv->retention, 2) }}</td></tr>@endif
@if((float) $inv->ivu > 0)<tr><td>{{ __('IVU retenido (:pct%)', ['pct' => rtrim(rtrim((string) $terms->ivu_pct, '0'), '.')]) }}</td><td class="r">−${{ number_format((float) $inv->ivu, 2) }}</td></tr>@endif
<tr class="big"><td>{{ __('Neto a pagar') }}</td><td class="r">${{ number_format((float) $inv->net, 2) }}</td></tr>
</table>
@if((float) $inv->ivu > 0)<p class="foot">{{ __('Como agente de retención del IVU, el pagador remite el IVU retenido mediante SURI.') }}</p>@endif
<p class="foot">{{ $brand['email_footer'] }}</p>
</body>
</html>
