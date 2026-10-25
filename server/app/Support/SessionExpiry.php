<?php

namespace App\Support;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Vencimiento deslizante de la sesión (token de Sanctum) por inactividad.
 * Un token vence cuando pasaron más de rules.remember_days días desde su último uso (o desde su creación si nunca se usó).
 * Con remember_days = 0 el servidor aplica un respaldo de 12 horas sin uso; el "no recordar" real lo hace el cliente.
 */
class SessionExpiry
{
    public const FALLBACK_HOURS = 12;
    /** Cada cuánto, como máximo, se escribe last_used_at (evita un UPDATE por petición). */
    public const TOUCH_MINUTES = 5;

    public static function message(): string
    {
        return __('Tu sesión venció por inactividad. Inicia sesión de nuevo.');
    }

    public static function check(PersonalAccessToken $token, bool $valid): bool
    {
        if (! $valid) {
            return false;
        }
        $company = $token->tokenable?->company;
        $days = $company ? (int) $company->rules()->get('remember_days') : 0;
        $limit = $days > 0 ? now()->subDays($days) : now()->subHours(self::FALLBACK_HOURS);
        $last = $token->last_used_at ?? $token->created_at;

        if ($last->lt($limit)) {
            $token->delete();
            request()->attributes->set('session_expired', true);
            return false;
        }
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(self::TOUCH_MINUTES))) {
            $token->forceFill(['last_used_at' => now()])->save();
        }
        return true;
    }
}
