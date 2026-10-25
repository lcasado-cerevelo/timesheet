@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px;">Hola {{ $name }},</p>
@if($decision === 'approved')
<p style="margin:0 0 12px;">Tu hoja de la semana que termina el <strong>{{ $week }}</strong> fue <strong style="color:#1b7f3b;">aprobada</strong>. Ya no se puede cambiar.</p>
@else
<p style="margin:0 0 12px;">Tu hoja de la semana que termina el <strong>{{ $week }}</strong> fue <strong style="color:#b00020;">devuelta</strong> para corregir.</p>
<p style="background:#fdecea;border-left:4px solid #b00020;padding:10px 12px;margin:0 0 12px;">{{ $comment }}</p>
<p style="margin:0;">Corrígela en la aplicación y vuelve a enviarla a aprobación.</p>
@endif
@endsection
