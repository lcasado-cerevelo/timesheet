<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="{{ url('/app/icons/favicon.svg') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ url('/app/icons/icon-32.png') }}">
<link rel="apple-touch-icon" href="{{ url('/app/icons/apple-touch-icon.png') }}">
<title>@yield('title', $brand['name'])</title>
<style>
:root{--primary:{{ $brand['color_primary'] }};--accent:{{ $brand['color_accent'] }};}
*{box-sizing:border-box}
body{margin:0;background:#f4f5f7;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#222;line-height:1.5}
header{position:relative;background:#fff;color:#222;padding:14px 20px 19px;font-weight:700;font-size:18px;display:flex;align-items:center;gap:14px;border-bottom:1px solid #e3e5e8}
header::after{content:"";position:absolute;left:0;right:0;bottom:0;height:5px;background:linear-gradient(90deg,var(--primary) 0 58%,#5d5d5d 58% 80%,var(--accent) 80% 100%)}
header img{max-height:44px;max-width:220px;display:block}
main{max-width:760px;margin:20px auto;padding:0 14px 40px}
.card{background:#fff;border-radius:10px;padding:18px 20px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
h1{font-size:22px;margin:0 0 6px}h2{font-size:17px;margin:0 0 8px}
table{width:100%;border-collapse:collapse}td,th{padding:7px 6px;border-bottom:1px solid #eee;text-align:left;font-size:14px}
th{color:#666;font-weight:600}td.r,th.r{text-align:right}
.btn{display:inline-block;border:0;border-radius:8px;padding:12px 20px;font-size:15px;font-weight:700;cursor:pointer;color:#fff;background:var(--accent)}
.btn.sec{background:#fff;color:#b00020;border:2px solid #b00020}.btn.pri{background:var(--primary)}
.btn.small{padding:8px 14px;font-size:14px}
textarea,input[type=text]{width:100%;padding:10px;border:1px solid #bbb;border-radius:8px;font:inherit}
.msg{padding:10px 12px;border-radius:8px;margin-bottom:12px}.ok{background:#e6f4ea;border-left:4px solid #1b7f3b}.err{background:#fdecea;border-left:4px solid #b00020}.warn{background:#fff4d6;border-left:4px solid #e0a800}
.muted{color:#777;font-size:13px}.row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start}
.demo-strip{background:#b45309;color:#fff;text-align:center;font-size:13px;font-weight:700;padding:5px 10px;letter-spacing:.03em}
footer{text-align:center;color:#888;font-size:12px;padding:16px}
</style>
</head>
<body>
@if(config('app.demo'))<div class="demo-strip">{{ __('Demo · datos de ejemplo') }}</div>@endif
<header>@if($brand['logo_url'])<img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }}">@endif @if(! $brand['logo_url'] || ($brand['show_name'] ?? true))<span>{{ $brand['name'] }}</span>@endif</header>
<main>
@if(session('status'))<div class="msg ok">{{ session('status') }}</div>@endif
@if($errors->any())<div class="msg err">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
@yield('content')
</main>
<footer>{{ $brand['email_footer'] ?: $brand['name'] }}</footer>
</body>
</html>
