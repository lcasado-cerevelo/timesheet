@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px;">{{ __('Hola :name,', ['name' => $name]) }}</p>
<p style="margin:0 0 16px;">{{ __('Tu contraseña de :company fue restablecida. Tu contraseña anterior ya no sirve y se cerraron tus sesiones. Crea una nueva con este botón.', ['company' => $companyName]) }}</p>
<p style="margin:0 0 16px;"><a href="{{ $url }}" style="display:inline-block;background:{{ $brand['color_primary'] }};color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:bold;">{{ __('Crear mi contraseña nueva') }}</a></p>
<p style="color:#777;font-size:13px;margin:0;">{{ __('El enlace sirve una sola vez y vale por :hours horas. Si no lo esperabas, avísale a quien administra tu cuenta.', ['hours' => $hours]) }}</p>
@endsection
