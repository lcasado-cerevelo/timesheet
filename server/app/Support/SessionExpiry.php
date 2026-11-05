<?php

namespace App\Support;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Vencimiento deslizante de la sesión (token de Sanctum) por inactividad.
 * Un token vence cuando pasaron más de rules.remember_days días desde su último uso (o desde su creación si nunca se usó).
 * Con remember_days = 0 el servidor aplica un respaldo de 12 horas sin uso; el "no recordar" real lo hace el cliente.
 * Una sesión marcada con la habilidad 'bio' (el teléfono activó la huella) no vence por inactividad: solo por el tope absoluto.
 */
class SessionExpiry
{
    public const FALLBACK_HOURS = 12;
    /** Cada cuánto, como máximo, se escribe last_used_at (evita un UPDATE por petición). */
    public const TOUCH_MINUTES = 5;

    public static function message(): string
    {
        if (request()->attributes->get('session_expired_reason') === 'max') {
            return __('Tu sesión llegó a su duración máxima. Inicia sesión de nuevo.');
        }
        return __('Tu sesión venció por inactividad. Inicia sesión de nuevo.');
    }

    /** Días que puede durar una sesión desde que se inició (0 = sin tope): más corto para el dueño y la oficina. */
    public static function capDays(?\App\Models\User $user): int
    {
        $rules = $user?->company?->rules();
        return $rules ? (int) $rules->get($user->isStaff() ? 'session_max_days_office' : 'session_max_days') : 0;
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
        $bio = in_array('bio', (array) $token->abilities, true);

        if (! $bio && $last->lt($limit)) {
            $token->delete();
            request()->attributes->set('session_expired', true);
            return false;
        }
        // Tope absoluto: cuenta desde que se escribió la contraseña, se haya usado o no la sesión.
        $cap = self::capDays($token->tokenable);
        if ($cap > 0 && $token->created_at->lt(now()->subDays($cap))) {
            $token->delete();
            request()->attributes->set('session_expired', true);
            request()->attributes->set('session_expired_reason', 'max');
            return false;
        }
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(self::TOUCH_MINUTES))) {
            $token->forceFill(['last_used_at' => now()])->save();
        }
        return true;
    }
}
