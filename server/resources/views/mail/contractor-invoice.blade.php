@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px;">{!! __('<strong>:worker</strong> generó la factura <strong>:number</strong> por sus horas aprobadas del :from al :to.', ['worker' => e($worker), 'number' => e($inv->number), 'from' => $inv->period_from->toDateString(), 'to' => $inv->period_to->toDateString()]) !!}</p>
<table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:0 0 12px;">
<tr><td>{{ __('Total bruto') }}</td><td align="right">${{ number_format((float) $inv->gross, 2) }}</td></tr>
@if((float) $inv->retention > 0)<tr><td>{{ __('Retención') }}</td><td align="right">−${{ number_format((float) $inv->retention, 2) }}</td></tr>@endif
@if((float) $inv->ivu > 0)<tr><td>{{ __('IVU retenido') }}</td><td align="right">−${{ number_format((float) $inv->ivu, 2) }}</td></tr>@endif
<tr><td><strong>{{ __('Neto a pagar') }}</strong></td><td align="right"><strong>${{ number_format((float) $inv->net, 2) }}</strong></td></tr>
</table>
<p style="color:#777;font-size:13px;margin:0;">{{ __('El PDF va adjunto. Vence el :date.', ['date' => $inv->due_on->toDateString()]) }}</p>
@endsection
