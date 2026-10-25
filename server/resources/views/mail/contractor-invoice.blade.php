@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px;"><strong>{{ $worker }}</strong> generó la factura <strong>{{ $inv->number }}</strong> por sus horas aprobadas del {{ $inv->period_from->toDateString() }} al {{ $inv->period_to->toDateString() }}.</p>
<table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:0 0 12px;">
<tr><td>Total bruto</td><td align="right">${{ number_format((float) $inv->gross, 2) }}</td></tr>
@if((float) $inv->retention > 0)<tr><td>Retención</td><td align="right">−${{ number_format((float) $inv->retention, 2) }}</td></tr>@endif
@if((float) $inv->ivu > 0)<tr><td>IVU retenido</td><td align="right">−${{ number_format((float) $inv->ivu, 2) }}</td></tr>@endif
<tr><td><strong>Neto a pagar</strong></td><td align="right"><strong>${{ number_format((float) $inv->net, 2) }}</strong></td></tr>
</table>
<p style="color:#777;font-size:13px;margin:0;">El PDF va adjunto. Vence el {{ $inv->due_on->toDateString() }}.</p>
@endsection
