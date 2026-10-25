@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px;">{{ __('Hola :name,', ['name' => $name]) }}</p>
<p style="margin:0 0 12px;">{!! $count === 1
    ? __('Tienes <strong>1</strong> hoja esperando tu decisión desde hace varios días.')
    : __('Tienes <strong>:count</strong> hojas esperando tu decisión desde hace varios días.', ['count' => $count]) !!}</p>
<p style="margin:20px 0;"><a href="{{ $url }}" style="background:{{ $brand['color_accent'] }};color:#fff;text-decoration:none;padding:12px 22px;border-radius:6px;display:inline-block;font-weight:bold;">{{ __('Ver todas las pendientes') }}</a></p>
<p style="color:#777;font-size:13px;margin:0;">{{ __('Ahí verás cada hoja con su detalle y podrás aprobarla, devolverla o aprobar todas. Por seguridad te pediremos un código que llega a este mismo correo.') }}</p>
@endsection
