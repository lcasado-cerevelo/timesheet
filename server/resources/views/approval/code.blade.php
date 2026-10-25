@extends('approval.layout')
@section('title', 'Verificación')
@section('content')
<div class="card">
<h1>Verifica que eres tú</h1>
<p>Por seguridad, enviamos un código de 6 dígitos al correo del aprobador. Escríbelo aquí para continuar.</p>
<form method="post" action="/{{ $prefix }}/{{ $plain }}/code" style="margin-bottom:14px">@csrf
<button class="btn pri small" type="submit">{{ $sent ? 'Enviar otro código' : 'Enviarme el código' }}</button></form>
<form method="post" action="/{{ $prefix }}/{{ $plain }}/verify">@csrf
<label>Código<br><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" style="max-width:200px;font-size:22px;letter-spacing:6px"></label>
<p><button class="btn" type="submit">Continuar</button></p></form>
</div>
@endsection
