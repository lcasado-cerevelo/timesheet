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

    /** key => [nombre, correo, etiqueta del botón (es), etiqueta (en)] de las cuentas que salen en la pantalla de entrada. */
    public const LOGIN_ACCOUNTS = [
        'owner' => ['Marisol Vega', 'dueno@demo.cerevelo.com', 'Dueña', 'Owner'],
        'office' => ['Carlos Rivera', 'oficina@demo.cerevelo.com', 'Oficina', 'Office'],
        'employee' => ['Ana Torres', 'ana.torres@demo.cerevelo.com', 'Empleada', 'Employee'],
        'consultant' => ['Ricardo Cruz', 'ricardo.cruz@demo.cerevelo.com', 'Consultor', 'Consultant'],
        'internal' => ['Patricia Colón', 'patricia.colon@demo.cerevelo.com', 'Aprobadora interna', 'Internal approver'],
        'external' => ['Nelson Báez', 'nelson.baez@demo.cerevelo.com', 'Aprobador externo', 'External approver'],
        'manager' => ['Elena Robles', 'elena.robles@demo.cerevelo.com', 'Gerente de proyecto del cliente', 'Client project manager'],
    ];

    /** ¿Es una cuenta de ejemplo? (todo lo que termina en el dominio de ejemplo) */
    public static function isExample(?string $email): bool
    {
        return $email !== null && str_ends_with(strtolower($email), '@demo.cerevelo.com');
    }

    /** Las cuentas que enseña la pantalla de entrada (solo las que existen). */
    public static function publicAccounts(): array
    {
        $emails = array_column(self::LOGIN_ACCOUNTS, 1);
        $found = User::whereIn('email', $emails)->pluck('email')->all();
        $out = [];
        foreach (self::LOGIN_ACCOUNTS as $key => [$name, $email, $es, $en]) {
            if (in_array($email, $found, true)) {
                $out[] = ['key' => $key, 'name' => $name, 'email' => $email, 'password' => self::PASSWORD, 'label_es' => $es, 'label_en' => $en];
            }
        }
        return $out;
    }

    /** En demostración, las cuentas de ejemplo no cambian su contraseña (ni se la anulan con un reseteo o una invitación). */
    public static function guardPassword(User $user): void
    {
        if (config('app.demo') && self::isExample($user->email)) {
            abort(403, __('En la demostración no se puede cambiar la contraseña de las cuentas de ejemplo.'));
        }
    }
}
