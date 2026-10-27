<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Prende o apaga el código por correo del dueño y la oficina. Sirve si el correo dejó de funcionar y nadie puede entrar para
 * cambiarlo en Settings → Rules (por ejemplo, desde un cron de una sola vez en un hosting sin terminal).
 */
class TwoStepCommand extends Command
{
    protected $signature = 'tiempo:two-step {state : on o off} {--company= : slug de la compañía (si no, todas)}';

    protected $description = 'Prende o apaga el código por correo del dueño y la oficina al entrar';

    public function handle(): int
    {
        $state = strtolower((string) $this->argument('state'));
        if (! in_array($state, ['on', 'off'], true)) {
            $this->error(__('Usa "on" u "off".'));
            return self::FAILURE;
        }
        $companies = Company::query()->when($this->option('company'), fn ($q, $slug) => $q->where('slug', $slug))->get();
        foreach ($companies as $c) {
            $c->update(['rules' => array_replace($c->rules ?? [], ['two_step_office' => $state === 'on'])]);
        }
        $this->info(__('Código por correo del dueño y la oficina: :state (:n compañía(s)).', ['state' => $state === 'on' ? __('activado') : __('apagado'), 'n' => $companies->count()]));
        return self::SUCCESS;
    }
}
