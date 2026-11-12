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
        'owner' => ['Luis Casado', 'lcasado@cerevelo.com', 'Dueño', 'Owner'],
        'consultant' => ['Jarvis Rodriguez', 'jrodriguez@cerevelo.com', 'Consultor', 'Consultant'],
        'manager' => ['Ketsy Figueroa', 'kfigueroa@amsipr.com', 'Aprobadora y gerente de proyecto del cliente', 'Approver and client project manager'],
        'manager2' => ['Jose Pereira', 'jpereira@amsipr.com', 'Aprobador y gerente de proyecto del cliente', 'Approver and client project manager'],
        'manager3' => ['Vivian Colon', 'vcolon@amsipr.com', 'Aprobadora y gerente de proyecto del cliente', 'Approver and client project manager'],
        'manager4' => ['Maribel Solivan', 'msolivan@amsipr.com', 'Aprobadora y gerente de proyecto del cliente', 'Approver and client project manager'],
    ];

    /** La contraseña de las tres cuentas: la de DEMO_PASSWORD en el .env, o la de ejemplo si no se puso ninguna. */
    public static function password(): string
    {
        $custom = (string) config('app.demo_password');
        return $custom !== '' ? $custom : self::PASSWORD;
    }

    /** ¿Es una cuenta de ejemplo? (una de las tres personas de la demo) */
    public static function isExample(?string $email): bool
    {
        return $email !== null && in_array(strtolower($email), array_map('strtolower', array_column(self::LOGIN_ACCOUNTS, 1)), true);
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
