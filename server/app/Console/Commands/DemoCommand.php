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
        foreach ($seeder->notes as $note) {
            $this->warn('  · '.$note);
        }
        $this->info(__('Listo. Contraseña de las cuentas de ejemplo: :password', ['password' => \App\Support\Demo::PASSWORD]));
        return self::SUCCESS;
    }
}
