<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\ExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCompany extends Command
{
    protected $signature = 'tiempo:instalar {compania : Nombre de la compañía} {correo : Correo del dueño} {--nombre= : Nombre del dueño} {--clave= : Contraseña (si no, se genera una)}';

    protected $description = 'Crea una compañía con su dueño y las plantillas de exportación del sistema (incluida Consultorio 1500)';

    public function handle(ExportService $exports): int
    {
        if (User::where('email', $this->argument('correo'))->exists()) {
            $this->error(__('Ya existe un usuario con ese correo.'));
            return self::FAILURE;
        }
        if ($this->option('clave') && mb_strlen($this->option('clave')) < 10) {
            $this->error(__('La contraseña debe tener al menos 10 caracteres.'));
            return self::FAILURE;
        }
        $slug = Str::slug($this->argument('compania'));
        $n = 1;
        while (Company::where('slug', $slug)->exists()) {
            $slug = Str::slug($this->argument('compania')).'-'.(++$n);
        }
        $company = Company::create(['name' => $this->argument('compania'), 'slug' => $slug]);
        $password = $this->option('clave') ?: Str::password(14, symbols: false);
        User::create([
            'company_id' => $company->id, 'name' => $this->option('nombre') ?: 'Dueño', 'email' => $this->argument('correo'),
            'password' => $password, 'role' => 'owner', 'worker_type' => 'consultant', 'capture_mode' => 'from_to',
        ]);
        $exports->ensureSystemTemplates($company);
        $this->info(__('Compañía creada: :name', ['name' => $company->name]));
        $this->line(__('Correo: :email', ['email' => $this->argument('correo')]));
        if (! $this->option('clave')) {
            $this->line(__('Contraseña generada: :password  (cámbiala al entrar)', ['password' => $password]));
        }
        return self::SUCCESS;
    }
}
