@extends('mail.layout')
@section('body')
@php $s = $summary; @endphp
@if($kind === 'changed')
<p style="background:#fff4d6;border-left:4px solid #e0a800;padding:10px 12px;margin:0 0 16px;"><strong>{{ __('Esta hoja cambió') }}</strong> {{ __('desde el último aviso. El enlace anterior ya no sirve; usa este.') }}</p>
@elseif($kind === 'resubmitted')
<p style="background:#e8f1fb;border-left:4px solid {{ $brand['color_accent'] }};padding:10px 12px;margin:0 0 16px;">{{ __('La hoja fue corregida y se envía de nuevo para aprobación.') }}</p>
@endif
<p style="margin:0 0 12px;">{!! __('<strong>:worker</strong> envió su hoja de la semana que termina el <strong>:week</strong>.', ['worker' => e($s['worker']), 'week' => e($s['week_ending'])]) !!}</p>
<table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:0 0 12px;">
@foreach($s['projects'] as $p)
<tr><td style="border-bottom:1px solid #eee;">{{ $p['project'] }}</td><td align="right" style="border-bottom:1px solid #eee;">{{ number_format($p['hours'], 2) }} h</td></tr>
@endforeach
<tr><td><strong>{{ __('Total') }}</strong></td><td align="right"><strong>{{ number_format($s['total_hours'], 2) }} h</strong></td></tr>
</table>
@if($s['late'])<p style="color:#b00020;margin:0 0 8px;">{{ __('Esta hoja se envió después de la fecha límite.') }}</p>@endif
@foreach($s['warnings'] as $w)<p style="color:#b26a00;margin:0 0 8px;">{{ $w['message'] }}</p>@endforeach
@if(count($changes))
<p style="margin:12px 0 4px;"><strong>{{ __('Qué cambió:') }}</strong></p>
<ul style="margin:0 0 12px;padding-left:20px;">@foreach($changes as $c)<li>{{ $c }}</li>@endforeach</ul>
@endif
<p style="margin:20px 0;"><a href="{{ $url }}" style="background:{{ $brand['color_accent'] }};color:#fff;text-decoration:none;padding:12px 22px;border-radius:6px;display:inline-block;font-weight:bold;">{{ __('Revisar y decidir') }}</a></p>
<p style="color:#777;font-size:13px;margin:0;">{{ __('El botón abre la hoja completa; ahí podrás aprobarla o devolverla con un comentario. No se aprueba nada al abrir el enlace. Vence en :days días.', ['days' => $days]) }}</p>
@endsection
