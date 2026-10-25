<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Detrás de un balanceador que termina el HTTPS (AWS), la aplicación solo ve HTTP; el balanceador avisa el esquema real en
        // X-Forwarded-Proto. TRUSTED_PROXIES: "*" (el contenedor solo es alcanzable desde el balanceador) o una lista de IP/CIDR
        // separadas por coma. Sin definir (hosting directo, desarrollo) no se confía en ningún proxy.
        $trusted = trim((string) env('TRUSTED_PROXIES', ''));
        if ($trusted !== '') {
            $middleware->trustProxies(at: $trusted === '*' ? '*' : array_map('trim', explode(',', $trusted)));
        }

        $middleware->alias([
            'owner' => \App\Http\Middleware\OwnerOnly::class,
            'public.page' => \App\Http\Middleware\PublicPageHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
