@extends('mail.layout')
@section('body')
@php $one = $items[0]; @endphp
<p style="margin:0 0 12px;">{{ __('Hola :name,', ['name' => $name]) }}</p>
@if($kind === 'changed')
<p style="background:#fff4d6;border-left:4px solid #e0a800;padding:10px 12px;margin:0 0 16px;"><strong>{{ __('Esta hoja cambió') }}</strong> {{ __('desde el último aviso. Las aprobaciones internas anteriores ya no cuentan.') }}</p>
@elseif($kind === 'resubmitted')
<p style="background:#e8f1fb;border-left:4px solid {{ $brand['color_accent'] }};padding:10px 12px;margin:0 0 16px;">{{ __('La hoja fue corregida y se envía de nuevo para aprobación.') }}</p>
@endif
@if($kind === 'reminder')
<p style="margin:0 0 12px;">{!! count($items) === 1
    ? __('Tienes <strong>1</strong> hoja esperando tu aprobación interna desde hace varios días.')
    : __('Tienes <strong>:count</strong> hojas esperando tu aprobación interna desde hace varios días.', ['count' => count($items)]) !!}</p>
@else
<p style="margin:0 0 12px;">{!! __('<strong>:worker</strong> envió su hoja de la semana que termina el <strong>:week</strong> y necesita tu aprobación interna.', ['worker' => e($one['worker']), 'week' => e($one['week'])]) !!}</p>
@endif
@foreach($items as $it)
@if($kind === 'reminder')<p style="margin:12px 0 4px;"><strong>{{ $it['worker'] }}</strong> · {{ $it['week'] }}</p>@endif
<table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:0 0 12px;">
@foreach($it['projects'] as $p)
<tr><td style="border-bottom:1px solid #eee;">{{ $p['project'] }}</td><td align="right" style="border-bottom:1px solid #eee;">{{ number_format($p['hours'], 2) }} h</td></tr>
@endforeach
<tr><td><strong>{{ __('Total') }}</strong></td><td align="right"><strong>{{ number_format($it['total_hours'], 2) }} h</strong></td></tr>
</table>
@if($kind === 'changed' && count($it['changes']))
<p style="margin:12px 0 4px;"><strong>{{ __('Qué cambió:') }}</strong></p>
<ul style="margin:0 0 12px;padding-left:20px;">@foreach($it['changes'] as $c)<li>{{ $c }}</li>@endforeach</ul>
@endif
@endforeach
<p style="margin:20px 0;"><a href="{{ $url }}" style="background:{{ $brand['color_accent'] }};color:#fff;text-decoration:none;padding:12px 22px;border-radius:6px;display:inline-block;font-weight:bold;">{{ __('Entrar y revisar') }}</a></p>
<p style="color:#777;font-size:13px;margin:0;">{{ __('Entra con tu cuenta. Al aprobar, la hoja pasa al aprobador externo; si la devuelves, vuelve completa a la persona con tu comentario.') }}</p>
@endsection
