<?php

namespace App\Console\Commands;

use App\Services\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Siembra (o renueva) el sitio de demostración: una compañía de ejemplo con datos de los últimos 2 meses que cubren todas las funciones.
 * Solo corre con DEMO_MODE=true, para no tocar producción por error. Con --fresh borra la compañía demo y la vuelve a crear
 * (correrlo cada noche mantiene la demo fresca); sin --fresh no duplica nada.
 */
class DemoCommand extends Command
{
    protected $signature = 'tiempo:demo {--fresh : Borra la compañía demo y la vuelve a crear} {--brand=cerevelo : Marca de la compañía: cerevelo o amsi}';

    protected $description = 'Siembra el sitio de demostración con datos de ejemplo (solo con DEMO_MODE=true)';

    public function handle(DemoSeeder $seeder): int
    {
        if (! config('app.demo')) {
            $this->error(__('Este comando solo corre en un sitio de demostración: pon DEMO_MODE=true en el .env de ese sitio. No se tocó nada.'));
            return self::FAILURE;
        }
        $brand = strtolower((string) $this->option('brand'));
        if (! in_array($brand, ['cerevelo', 'amsi'], true)) {
            $this->error(__('La marca debe ser cerevelo o amsi.'));
            return self::FAILURE;
        }
        set_time_limit(0);
        $this->info(__('Sembrando la demostración…'));
        $counts = $seeder->run($brand, (bool) $this->option('fresh'));
        foreach ($counts as $name => $n) {
            $this->line(sprintf('  %-20s %d', $name, $n));
        }
        $this->line('');
        $this->line('  '.__('Hojas por semana (semana que termina, estado, horas):'));
        foreach (\App\Models\Timesheet::whereHas('user', fn ($q) => $q->where('email', \App\Support\Demo::LOGIN_ACCOUNTS['consultant'][1]))->orderBy('week_ending')->get() as $sheet) {
            $this->line(sprintf('  %s  %-10s %-9s %s h', $sheet->week_ending->toDateString(), $sheet->status, $sheet->stage ?? '-', $sheet->total_hours));
        }
        foreach ($seeder->notes as $note) {
            $this->warn('  · '.$note);
        }
        $this->info(__('Listo. Cuentas de ejemplo (la misma contraseña para todas):'));
        foreach (\App\Support\Demo::LOGIN_ACCOUNTS as [$name, $email]) {
            $this->line(sprintf('  %-20s %s', $name, $email));
        }
        $this->line('  '.__('Contraseña: :password', ['password' => \App\Support\Demo::password()]));
        return self::SUCCESS;
    }
}
