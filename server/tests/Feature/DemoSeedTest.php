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

    public function test_the_seed_has_only_the_three_people_and_the_amsi_pits_project(): void
    {
        config(['app.demo' => true]);
        $this->assertSame(0, Artisan::call('tiempo:demo', ['--fresh' => true]), Artisan::output());
        $this->assertStringNotContainsString('·', Artisan::output(), 'la siembra no debe dejar avisos de cosas que no se pudieron sembrar');
        $co = Company::where('slug', Demo::COMPANY_SLUG)->firstOrFail();
        $this->assertSame('es', $co->locale);
        $this->assertSame(0, $co->rules()->weekStart());
        $c = $this->counts();

        // Solo tres personas: el dueño, el consultor y la aprobadora (gerente de proyecto del cliente).
        $this->assertSame(6, $c['users']);
        $this->assertSame(4, $c['approvers']);
        $this->assertSame(1, User::where('company_id', $co->id)->where('role', 'owner')->count());
        $this->assertSame(4, User::where('company_id', $co->id)->where('role', 'approver')->count());
        $this->assertSame(1, User::where('company_id', $co->id)->where('worker_type', 'consultant')->where('role', 'member')->count());
        $this->assertSame(4, Approver::where('company_id', $co->id)->where('project_manager', true)->count());
        $this->assertSame(['Jarvis Rodriguez', 'Jose Pereira', 'Ketsy Figueroa', 'Luis Casado', 'Maribel Solivan', 'Vivian Colon'], User::where('company_id', $co->id)->orderBy('name')->pluck('name')->all());

        // Un proyecto con cinco servicios de doble aprobación y cuatro tareas.
        $this->assertSame(1, $c['projects']);
        $this->assertSame(5, $c['services']);
        $this->assertSame(5, \App\Models\Service::where('company_id', $co->id)->where('approval_mode', 'both')->where('bill_rate', 125)->count());
        $this->assertSame(4, $c['tasks']);

        // El mes anterior, aprobado por completo y con las dos aprobaciones; la semana pasada, enviada sin aprobar; esta semana, nada.
        $sheets = Timesheet::where('company_id', $co->id);
        $this->assertGreaterThan(3, (clone $sheets)->where('status', 'approved')->count());
        $this->assertSame(0, (clone $sheets)->whereNotIn('status', ['approved', 'submitted'])->count());
        $this->assertGreaterThan(0, (clone $sheets)->where('status', 'submitted')->where('stage', 'external')->count(), 'enviada, pendiente del cliente');
        $this->assertGreaterThan(0, (clone $sheets)->where('late', true)->count(), 'tardía');
        $thisWeek = now('America/Puerto_Rico')->startOfWeek(\Carbon\Carbon::SUNDAY)->toDateString();
        $this->assertSame(0, TimeEntry::where('company_id', $co->id)->where('work_date', '>=', $thisWeek)->count(), 'esta semana no lleva nada');
        $approved = (clone $sheets)->where('status', 'approved')->first();
        $this->assertGreaterThanOrEqual(2, Signature::where('subject_type', 'timesheet')->where('subject_id', $approved->id)->where('action', 'sheet.approve')->count(), 'doble aprobación');
        $this->assertTrue(TimeEntry::where('company_id', $co->id)->whereNotNull('task_id')->exists());
        $this->assertTrue(ContractorInvoice::where('company_id', $co->id)->where('status', 'paid')->exists(), 'factura pagada');
        $this->assertTrue(ContractorInvoice::where('company_id', $co->id)->where('status', '!=', 'paid')->exists(), 'factura generada');

        // Las tres cuentas entran con la misma contraseña, sin códigos, y la entrada no trae botones de acceso rápido.
        foreach (Demo::LOGIN_ACCOUNTS as [, $email]) {
            $this->postJson('/api/login/web', ['email' => $email, 'password' => Demo::password()])->assertOk()->assertJsonStructure(['token']);
        }
        $this->assertSame([], $this->getJson('/api/public-config')->json('demo_accounts'));
    }

    public function test_the_project_manager_sees_progress_and_the_exports_have_content(): void
    {
        config(['app.demo' => true]);
        Artisan::call('tiempo:demo', ['--fresh' => true]);
        $manager = User::where('email', 'kfigueroa@amsipr.com')->firstOrFail();
        Sanctum::actingAs($manager);
        $list = $this->getJson('/api/approver/progress')->assertOk()->json();
        $this->assertCount(1, $list);
        $d = $this->getJson('/api/approver/progress/'.$list[0]['id'])->assertOk()->json();
        $this->assertGreaterThan(0, $d['hours']);
        $this->get('/api/approver/progress/'.$list[0]['id'].'/export?format=pdf')->assertOk();

        Sanctum::actingAs(User::where('email', 'lcasado@cerevelo.com')->firstOrFail());
        $range = 'from='.now()->subWeeks(9)->toDateString().'&to='.now()->toDateString();
        foreach (['csv', 'xlsx', 'pdf'] as $f) {
            $r = $this->get('/api/tables/sheets/export?format='.$f.'&'.$range)->assertOk();
            $this->assertGreaterThan(500, strlen($r->getContent()), "el export $f trae contenido");
        }
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
