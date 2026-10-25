<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountService;
use Illuminate\Console\Command;

/** Para el dueño que se quedó sin acceso: igual que el reseteo del dueño, pero imprime el enlace de un solo uso. */
class ResetPasswordCommand extends Command
{
    protected $signature = 'tiempo:resetear-clave {correo : Correo de la cuenta}';

    protected $description = 'Anula la contraseña de una cuenta, cierra sus sesiones y genera un enlace de un solo uso para crear una nueva (también se envía por correo)';

    public function handle(AccountService $accounts): int
    {
        $user = User::with('company')->where('email', $this->argument('correo'))->first();
        if (! $user) {
            $this->error(__('No existe una cuenta con ese correo.'));
            return self::FAILURE;
        }
        $link = $accounts->reset($user, null, ['via' => 'cli']);
        $this->info(__('Contraseña anulada y sesiones cerradas para :email.', ['email' => $user->email]));
        $this->line(__('Enlace para crear la contraseña nueva (un solo uso, vale :hours horas):', ['hours' => AccountService::RESET_HOURS]));
        $this->line($link);
        return self::SUCCESS;
    }
}
