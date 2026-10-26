<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#222;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#fff;border-radius:8px;overflow:hidden;">
{{-- Franja de color de la marca, sin imagen ni texto: los correos de solo texto con un botón llegan mejor y no dependen de que se carguen imágenes. --}}
<tr><td style="background:{{ $brand['color_primary'] }};height:12px;line-height:12px;font-size:0;">&nbsp;</td></tr>
<tr><td style="padding:24px;font-size:15px;line-height:1.5;">
@yield('body')
</td></tr>
<tr><td style="padding:14px 24px;background:#fafafa;color:#777;font-size:12px;">
{{ $brand['email_footer'] ?: $brand['name'] }}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
