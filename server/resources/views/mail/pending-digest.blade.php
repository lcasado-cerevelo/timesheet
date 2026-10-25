@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px;">Hola {{ $name }},</p>
<p style="margin:0 0 12px;">Tienes <strong>{{ $count }}</strong> {{ $count === 1 ? 'hoja' : 'hojas' }} esperando tu decisión desde hace varios días.</p>
<p style="margin:20px 0;"><a href="{{ $url }}" style="background:{{ $brand['color_accent'] }};color:#fff;text-decoration:none;padding:12px 22px;border-radius:6px;display:inline-block;font-weight:bold;">Ver todas las pendientes</a></p>
<p style="color:#777;font-size:13px;margin:0;">Ahí verás cada hoja con su detalle y podrás aprobarla, devolverla o aprobar todas. Por seguridad te pediremos un código que llega a este mismo correo.</p>
@endsection
