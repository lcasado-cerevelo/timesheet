<?php

namespace App\Console\Commands;

use App\Models\Approver;
use App\Models\Company;
use App\Models\ContractorTerms;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Services\ExportService;
use Illuminate\Console\Command;

/** Datos de ejemplo para probar la interfaz (solo desarrollo). */
class DemoData extends Command
{
    protected $signature = 'tiempo:demo';

    protected $description = 'Crea una compañía de ejemplo (Cerevelo) con personas, proyectos y un aprobador';

    public function handle(ExportService $exports): int
    {
        if (Company::where('slug', 'cerevelo-demo')->exists()) {
            $this->warn('La compañía demo ya existe.');
            return self::SUCCESS;
        }
        $c = Company::create(['name' => 'Cerevelo (demo)', 'slug' => 'cerevelo-demo', 'brand_name' => 'Cerevelo', 'invoice_prefix' => 'CER',
            'email_footer' => 'Cerevelo · Puerto Rico']);
        $approver = Approver::create(['company_id' => $c->id, 'name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test']);
        $owner = User::create(['company_id' => $c->id, 'name' => 'Luis', 'email' => 'luis@demo.test', 'password' => 'demo-password-123', 'role' => 'owner', 'capture_mode' => 'from_to']);
        $ana = User::create(['company_id' => $c->id, 'name' => 'Ana Torres', 'email' => 'ana@demo.test', 'password' => 'demo-password-123',
            'worker_type' => 'consultant', 'capture_mode' => 'from_to', 'approver_id' => $approver->id]);
        $marcos = User::create(['company_id' => $c->id, 'name' => 'Marcos Ruiz', 'email' => 'marcos@demo.test', 'password' => 'demo-password-123',
            'worker_type' => 'employee', 'weekly_hours' => 40, 'capture_mode' => 'full_day', 'approver_id' => $approver->id]);
        ContractorTerms::create(['user_id' => $ana->id, 'rate' => 40, 'retention_pct' => 10, 'ivu_agent' => true, 'ivu_pct' => 4, 'terms_days' => 30]);
        $adv = Customer::create(['company_id' => $c->id, 'name' => 'Advance Logistics']);
        $p1 = Project::create(['company_id' => $c->id, 'customer_id' => $adv->id, 'code' => 'ADV-1', 'name' => 'Soporte presencial', 'bill_rate' => 50]);
        $p2 = Project::create(['company_id' => $c->id, 'customer_id' => $adv->id, 'code' => 'ADV-2', 'name' => 'Desarrollo WebApp', 'bill_rate' => 75, 'invoice_detail' => 'by_day']);
        $pac = Project::create(['company_id' => $c->id, 'code' => 'PAC-1', 'name' => 'Maria Lopez', 'bill_rate' => 100,
            'meta' => ['plan_medico' => 'Triple S', 'numero_contrato' => 'C-123']]);
        foreach ([$ana, $marcos] as $u) {
            $u->projects()->sync([$p1->id, $p2->id, $pac->id]);
        }
        $exports->ensureSystemTemplates($c);
        $this->info('Demo lista. Dueño: luis@demo.test · Consultora: ana@demo.test · Empleado: marcos@demo.test · clave demo-password-123');
        return self::SUCCESS;
    }
}
