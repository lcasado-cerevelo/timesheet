<?php

namespace Tests\Feature;

use App\Models\Approver;
use App\Models\Commission;
use App\Models\CommissionResult;
use App\Models\Company;
use App\Models\ContractorInvoice;
use App\Models\Signature;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** La siembra del sitio de demostración (tiempo:demo): corre completa, cubre todos los estados y se puede repetir. */
class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    private function counts(): array
    {
        $seeder = app(\App\Services\DemoSeeder::class);
        $r = new \ReflectionProperty($seeder, 'company');
        $r->setValue($seeder, Company::where('slug', Demo::COMPANY_SLUG)->firstOrFail());
        return $seeder->counts();
    }

    public function test_the_seed_runs_complete_and_covers_every_state_role_and_feature(): void
    {
        config(['app.demo' => true]);
        $this->assertSame(0, Artisan::call('tiempo:demo', ['--fresh' => true]), Artisan::output());
        $this->assertStringNotContainsString('·', Artisan::output(), 'la siembra no debe dejar avisos de cosas que no se pudieron sembrar');
        $co = Company::where('slug', Demo::COMPANY_SLUG)->firstOrFail();
        $this->assertSame('es', $co->locale);
        $this->assertSame('America/Puerto_Rico', $co->timezone);
        $this->assertSame(0, $co->rules()->weekStart());
        $c = $this->counts();

        // Personas: una cuenta por cada rol.
        $this->assertSame(2, User::where('company_id', $co->id)->where('role', 'owner')->count(), 'dueño y oficina');
        $this->assertSame(3, User::where('email', 'like', '%@demo.cerevelo.com')->whereIn('email', ['ana.torres@demo.cerevelo.com', 'jorge.medina@demo.cerevelo.com', 'lucia.ortiz@demo.cerevelo.com'])->where('worker_type', 'employee')->count());
        $this->assertSame(4, User::where('company_id', $co->id)->where('worker_type', 'consultant')->where('role', 'member')->count());
        $this->assertSame(3, User::where('company_id', $co->id)->where('role', 'approver')->count(), 'tres aprobadores externos con cuenta');
        $this->assertSame(1, Approver::where('company_id', $co->id)->where('can_login', false)->count(), 'uno solo por enlace');
        $this->assertSame(1, Approver::where('company_id', $co->id)->where('project_manager', true)->count());
        $internal = User::where('company_id', $co->id)->get()->filter(fn ($u) => $u->isInternalApprover());
        $this->assertCount(2, $internal, 'dos aprobadores internos');
        $this->assertSame(3, $c['customers']);
        $this->assertContains('AMSI', \App\Models\Customer::where('company_id', $co->id)->pluck('name')->all());
        $this->assertSame(6, $c['projects']);
        $this->assertTrue(\App\Models\Service::where('company_id', $co->id)->where('capture_mode', 'detail')->exists());
        $this->assertTrue(\App\Models\Service::where('company_id', $co->id)->where('capture_mode', 'total')->exists());
        $this->assertTrue(\App\Models\Service::where('company_id', $co->id)->whereIn('approval_mode', ['internal', 'both'])->exists());
        $this->assertTrue(\App\Models\Service::where('company_id', $co->id)->where('approval_mode', 'external')->exists());
        $this->assertTrue(Task::where('company_id', $co->id)->where('active', true)->exists());
        $this->assertTrue(Task::where('company_id', $co->id)->where('active', false)->whereNotNull('completed_on')->exists());
        $this->assertTrue(Task::where('company_id', $co->id)->where('commissionable', true)->exists());

        // Hojas en todos los estados.
        $sheets = Timesheet::where('company_id', $co->id);
        $this->assertGreaterThan(0, (clone $sheets)->where('status', 'draft')->count(), 'borrador');
        $this->assertGreaterThan(0, (clone $sheets)->where('status', 'submitted')->where('stage', 'internal')->count(), 'pendiente de aprobación interna');
        $this->assertGreaterThan(0, (clone $sheets)->where('status', 'submitted')->where('stage', 'external')->count(), 'pendiente del cliente');
        $this->assertGreaterThan(0, (clone $sheets)->where('status', 'approved')->count(), 'aprobada');
        $this->assertGreaterThan(0, (clone $sheets)->where('status', 'returned')->whereNotNull('return_comment')->count(), 'devuelta con comentario');
        $this->assertGreaterThan(0, (clone $sheets)->where('late', true)->count(), 'tardía');
        $this->assertGreaterThanOrEqual(3, (clone $sheets)->whereNotNull('period_start')->count(), 'enviada por partes');
        $this->assertGreaterThan(40, (clone $sheets)->where('status', 'approved')->count(), 'las semanas anteriores, en su mayoría aprobadas');
        $approved = Timesheet::where('company_id', $co->id)->where('status', 'approved')->first();
        $this->assertGreaterThan(0, Signature::where('subject_type', 'timesheet')->where('subject_id', $approved->id)->count(), 'aprobada con firmas');
        $this->assertTrue(Signature::where('method', 'email_code')->exists(), 'firma por el enlace del aprobador sin cuenta');
        $this->assertTrue(Signature::where('method', 'password')->exists());

        // Las 8 semanas anteriores y la actual.
        $entries = TimeEntry::where('company_id', $co->id);
        $this->assertGreaterThanOrEqual(8, (clone $entries)->selectRaw('count(distinct strftime("%Y-%W", work_date)) n')->value('n'));
        $this->assertTrue((clone $entries)->where('break_minutes', '>', 0)->exists(), 'con descanso');
        $this->assertTrue((clone $entries)->whereRaw('length(note) > 150')->exists(), 'notas largas');
        $this->assertTrue((clone $entries)->whereIn('hour_type', ['vac', 'hol', 'sick', 'ot'])->exists(), 'tipos de hora de empleados');
        $this->assertGreaterThan(2, (clone $entries)->distinct()->count('project_id'));
        $this->assertTrue((clone $entries)->whereNotNull('task_id')->exists());
        $this->assertTrue((clone $entries)->where('billable', false)->where('hour_type', 'reg')->exists(), 'horas de cortesía');

        // Facturas, comisiones y firmas.
        $this->assertTrue(ContractorInvoice::where('company_id', $co->id)->where('status', 'paid')->exists(), 'factura pagada');
        $this->assertTrue(ContractorInvoice::where('company_id', $co->id)->where('status', '!=', 'paid')->exists(), 'factura generada');
        $this->assertGreaterThan(0, Commission::where('company_id', $co->id)->count());
        $this->assertTrue(CommissionResult::where('company_id', $co->id)->where('total', '>', 0)->exists(), 'comisión con resultado');
        $this->assertTrue(CommissionResult::where('company_id', $co->id)->where('status', 'none')->exists(), 'tarea que no llegó a la meta');
        $this->assertTrue(Signature::where('action', 'invoice.create')->exists());
        $this->assertTrue(Signature::where('action', 'task.complete')->exists());

        // Todas las cuentas entran con la contraseña conocida (sin códigos) y las de ejemplo salen en la pantalla de entrada.
        foreach (Demo::LOGIN_ACCOUNTS as [, $email]) {
            $this->postJson('/api/login/web', ['email' => $email, 'password' => Demo::PASSWORD])->assertOk()->assertJsonStructure(['token']);
        }
        $this->assertCount(count(Demo::LOGIN_ACCOUNTS), $this->getJson('/api/public-config')->json('demo_accounts'));
    }

    public function test_the_project_manager_sees_progress_with_courtesy_hours_and_the_exports_have_content(): void
    {
        config(['app.demo' => true]);
        Artisan::call('tiempo:demo', ['--fresh' => true]);
        $manager = User::where('email', 'elena.robles@demo.cerevelo.com')->firstOrFail();
        Sanctum::actingAs($manager);
        $list = $this->getJson('/api/approver/progress')->assertOk()->json();
        $this->assertGreaterThanOrEqual(2, count($list));
        $mig = collect($list)->firstWhere('code', 'AMSI-MIG');
        $d = $this->getJson('/api/approver/progress/'.$mig['id'])->assertOk()->json();
        $this->assertTrue($d['courtesy_enabled']);
        $this->assertGreaterThan(0, $d['courtesy_hours']);
        $this->assertGreaterThan(0, $d['hours']);
        $this->get('/api/approver/progress/'.$mig['id'].'/export?format=pdf')->assertOk();

        Sanctum::actingAs(User::where('email', 'dueno@demo.cerevelo.com')->firstOrFail());
        $range = 'from='.now()->subWeeks(9)->toDateString().'&to='.now()->toDateString();
        foreach (['csv', 'xlsx', 'pdf'] as $f) {
            $r = $this->get('/api/tables/sheets/export?format='.$f.'&'.$range)->assertOk();
            $this->assertGreaterThan(500, strlen($r->getContent()), "el export $f trae contenido");
        }
        $this->assertGreaterThan(20, (int) $this->get('/api/tables/sheets/export?format=csv&'.$range)->headers->get('X-Export-Rows'));
        $this->getJson('/api/reports/summary?'.$range)->assertOk();
    }

    public function test_fresh_is_repeatable_and_without_fresh_it_does_not_duplicate(): void
    {
        config(['app.demo' => true]);
        Artisan::call('tiempo:demo', ['--fresh' => true]);
        $first = $this->counts();
        $this->assertSame(0, Artisan::call('tiempo:demo'));                       // sin --fresh: no duplica nada
        $this->assertSame($first, $this->counts());
        $this->assertSame(0, Artisan::call('tiempo:demo', ['--fresh' => true]));   // con --fresh: los mismos conteos
        $this->assertSame($first, $this->counts());
        $this->assertSame(1, Company::where('slug', Demo::COMPANY_SLUG)->count());
        $this->assertSame($first['users'], User::count());
    }

    public function test_fresh_only_removes_the_demo_company(): void
    {
        $other = Company::create(['name' => 'Otra', 'slug' => 'otra']);
        $keep = User::create(['company_id' => $other->id, 'name' => 'Persona', 'email' => 'persona@otra.test', 'password' => 'secret-password', 'role' => 'owner']);
        config(['app.demo' => true]);
        Artisan::call('tiempo:demo', ['--fresh' => true]);
        Artisan::call('tiempo:demo', ['--fresh' => true, '--brand' => 'amsi']);
        $this->assertNotNull(User::find($keep->id));
        $this->assertSame('AMSI', Company::where('slug', Demo::COMPANY_SLUG)->value('brand_name'));
        $this->assertNull(Company::where('slug', Demo::COMPANY_SLUG)->value('logo_path'));
    }
}
