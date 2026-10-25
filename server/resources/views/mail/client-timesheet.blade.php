@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px;">{!! __('Le enviamos la hoja de tiempo de <strong>:customer</strong> del :from al :to.', ['customer' => e($d['customer']), 'from' => e($d['from']), 'to' => e($d['to'])]) !!}</p>
@if($note)<p style="margin:0 0 12px;white-space:pre-line;">{{ $note }}</p>@endif
<table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:0 0 12px;">
@foreach($d['projects'] as $p)
<tr><td style="border-bottom:1px solid #eee;">{{ $p['code'] }} · {{ $p['name'] }}</td><td align="right" style="border-bottom:1px solid #eee;">{{ number_format($p['hours'], 2) }} h</td></tr>
@endforeach
<tr><td><strong>{{ __('Total') }}</strong></td><td align="right"><strong>{{ number_format($d['total_hours'], 2) }} h</strong></td></tr>
</table>
<p style="color:#777;font-size:13px;margin:0;">{{ __('El detalle por día, con la descripción de lo trabajado, va en el PDF adjunto.') }}</p>
@endsection
