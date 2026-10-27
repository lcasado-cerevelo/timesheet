<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Cabeceras de seguridad en toda respuesta de la aplicación: que no la metan en un marco de otra página (clickjacking),
 * que el navegador no adivine tipos de archivo, qué scripts, estilos y conexiones se permiten (CSP) y HTTPS obligatorio (HSTS).
 * Los archivos estáticos (/app/...) no pasan por PHP: llevan las mismas cabeceras desde public/.htaccess o desde Caddy.
 * Una cabecera que la respuesta ya trae (las páginas de aprobación tienen las suyas) no se pisa.
 */
class SecurityHeaders
{
    /** Debe coincidir con public/.htaccess y deploy/Caddyfile. Turnstile (Cloudflare) y las fuentes de Google son lo único externo. */
    public const CSP = "default-src 'self'; script-src 'self' https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        ."font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; frame-src https://challenges.cloudflare.com; "
        ."frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $h = $response->headers;
        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(self), payment=()',
            'Content-Security-Policy' => self::CSP,
        ] as $name => $value) {
            if (! $h->has($name)) {
                $h->set($name, $value);
            }
        }
        if ($request->isSecure() && ! $h->has('Strict-Transport-Security')) {
            $h->set('Strict-Transport-Security', 'max-age=31536000');
        }
        // Lo que responde la API (horas, personas, tarifas) no se guarda en cachés compartidas ni del navegador.
        if ($request->is('api/*') && ! str_contains((string) $h->get('Cache-Control'), 'no-store')) {
            $h->set('Cache-Control', 'no-store, private');
        }
        return $response;
    }
}
