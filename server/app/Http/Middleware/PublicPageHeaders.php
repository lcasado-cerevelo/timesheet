<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Páginas con enlace secreto: que no se indexen, no se guarden en caché ni filtren el enlace por Referer. */
class PublicPageHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Frame-Options', 'DENY');
        return $response;
    }
}
