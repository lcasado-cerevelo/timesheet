@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px;">{{ __('Tu código de verificación es:') }}</p>
<p style="font-size:32px;letter-spacing:6px;font-weight:bold;margin:0 0 12px;">{{ $code }}</p>
<p style="color:#777;font-size:13px;margin:0;">{{ __('Vale por :minutes minutos. Si no lo pediste, ignora este correo.', ['minutes' => $minutes]) }}</p>
@endsection
