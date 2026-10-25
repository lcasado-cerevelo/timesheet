<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Idioma de cada petición: el de la persona que inició sesión, si no el de su compañía, si no el que pida el cliente
 * (cabecera X-Locale o Accept-Language, para la pantalla de entrada) y, al final, el de la aplicación.
 * Español e inglés. Los textos del código están en español; lang/en.json trae su traducción al inglés.
 */
class SetLocale
{
    public const SUPPORTED = ['es', 'en'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('sanctum');
        $locale = $user?->locale ?: $user?->company?->locale;
        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = $this->fromHeaders($request) ?? config('app.locale');
        }
        App::setLocale($locale);
        return $next($request);
    }

    private function fromHeaders(Request $request): ?string
    {
        $x = strtolower(substr((string) $request->header('X-Locale'), 0, 2));
        if (in_array($x, self::SUPPORTED, true)) {
            return $x;
        }
        $a = strtolower(substr((string) $request->header('Accept-Language'), 0, 2));
        return in_array($a, self::SUPPORTED, true) ? $a : null;
    }
}
