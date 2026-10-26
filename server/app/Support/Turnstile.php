<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            Log::warning('Turnstile: no valid token received', ['length' => is_string($token) ? strlen($token) : null]);
            return false;
        }
        try {
            $r = Http::asForm()->timeout(8)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                'secret' => config('services.turnstile.secret'), 'response' => $token, 'remoteip' => $ip,
            ]));
            $ok = $r->successful() && $r->json('success') === true;
            if (! $ok) {
                Log::warning('Turnstile: verification rejected', ['status' => $r->status(), 'errors' => $r->json('error-codes'), 'hostname' => $r->json('hostname'), 'ip' => $ip]);
            }
            return $ok;
        } catch (\Throwable $e) {
            Log::warning('Turnstile: request to Cloudflare failed', ['error' => $e->getMessage()]);
            return false;   // si Cloudflare no responde, no se deja pasar
        }
    }
}
