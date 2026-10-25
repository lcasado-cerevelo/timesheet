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

        $middleware->appendToGroup('api', \App\Http\Middleware\SetLocale::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\SetLocale::class);

        $middleware->alias([
            'approver.scope' => \App\Http\Middleware\ApproverScope::class,
            'approver' => \App\Http\Middleware\ApproverOnly::class,
            'owner' => \App\Http\Middleware\OwnerOnly::class,
            'internal' => \App\Http\Middleware\InternalOnly::class,
            'public.page' => \App\Http\Middleware\PublicPageHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sesión vencida por inactividad: 401 con código propio para que la web y el móvil sepan que deben volver a entrar.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->attributes->get('session_expired') && ($request->is('api/*') || $request->expectsJson())) {
                return response()->json(['message' => \App\Support\SessionExpiry::message(), 'code' => 'session_expired'], 401);
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
