<?php

namespace App\Support;

use App\Models\User;

/**
 * Sitio de demostración: las cuentas de ejemplo (una por rol) y lo que se protege de ellas. Todas comparten la misma contraseña conocida.
 * Solo se usa con DEMO_MODE=true (config('app.demo')); con false nada de esto se ve ni se aplica.
 */
class Demo
{
    public const PASSWORD = 'Demo-Timesheet#2026';
    public const DOMAIN = 'timesheet-demo.cerevelo.com';
    public const COMPANY_SLUG = 'demo';

    /** key => [nombre, correo, etiqueta (es), etiqueta (en)] de las cuentas de ejemplo (las tres personas de la demo). */
    public const LOGIN_ACCOUNTS = [
        'owner' => ['Luis Casado', 'dueno@demo.cerevelo.com', 'Dueño', 'Owner'],
        'consultant' => ['Jarvis Rodriguez', 'jarvis.rodriguez@demo.cerevelo.com', 'Consultor', 'Consultant'],
        'manager' => ['Ketsy Figueroa', 'ketsy.figueroa@demo.cerevelo.com', 'Aprobadora y gerente de proyecto del cliente', 'Approver and client project manager'],
    ];

    /** La contraseña de las tres cuentas: la de DEMO_PASSWORD en el .env, o la de ejemplo si no se puso ninguna. */
    public static function password(): string
    {
        $custom = (string) config('app.demo_password');
        return $custom !== '' ? $custom : self::PASSWORD;
    }

    /** ¿Es una cuenta de ejemplo? (todo lo que termina en el dominio de ejemplo) */
    public static function isExample(?string $email): bool
    {
        return $email !== null && str_ends_with(strtolower($email), '@demo.cerevelo.com');
    }

    /** La pantalla de entrada ya no trae botones de acceso rápido: cada persona entra con su correo y la contraseña que se le dio. */
    public static function publicAccounts(): array
    {
        return [];
    }

    /** En demostración, las cuentas de ejemplo no cambian su contraseña (ni se la anulan con un reseteo o una invitación). */
    public static function guardPassword(User $user): void
    {
        if (config('app.demo') && self::isExample($user->email)) {
            abort(403, __('En la demostración no se puede cambiar la contraseña de las cuentas de ejemplo.'));
        }
    }
}
