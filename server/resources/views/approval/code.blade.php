@extends('approval.layout')
@section('title', __('Verificación'))
@section('content')
<div class="card">
<h1>{{ __('Verifica que eres tú') }}</h1>
<p>{{ __('Por seguridad, enviamos un código de 6 dígitos al correo del aprobador. Escríbelo aquí para continuar.') }}</p>
<form method="post" action="/{{ $prefix }}/{{ $plain }}/code" style="margin-bottom:14px">@csrf
<button class="btn pri small" type="submit">{{ $sent ? __('Enviar otro código') : __('Enviarme el código') }}</button></form>
<form method="post" action="/{{ $prefix }}/{{ $plain }}/verify">@csrf
<label>{{ __('Código') }}<br><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" style="max-width:200px;font-size:22px;letter-spacing:6px"></label>
<p><button class="btn" type="submit">{{ __('Continuar') }}</button></p></form>
</div>
@endsection
