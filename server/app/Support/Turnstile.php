<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile: desafío anti-bots para el formulario de login de la web (gratis, funciona con el dominio en "solo DNS").
 * Sin TURNSTILE_SECRET queda apagado (desarrollo y pruebas). Si falla la verificación o Cloudflare no responde, se rechaza.
 */
class Turnstile
{
    public static function enabled(): bool
    {
        return (string) config('services.turnstile.secret') !== '' && (string) config('services.turnstile.site_key') !== '';
    }

    public static function verify(?string $token, ?string $ip = null): bool
    {
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }
        try {
            $r = Http::asForm()->timeout(8)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                'secret' => config('services.turnstile.secret'), 'response' => $token, 'remoteip' => $ip,
            ]));
            return $r->successful() && $r->json('success') === true;
        } catch (\Throwable) {
            return false;   // si Cloudflare no responde, no se deja pasar
        }
    }
}
