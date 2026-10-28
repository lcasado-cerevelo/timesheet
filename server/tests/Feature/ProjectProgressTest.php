<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\Service;
use App\Models\TimeEntry;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** Gerente de proyecto: el aprobador con cuenta y la marca ve el Avance (solo lectura) de los proyectos de sus clientes. */
class ProjectProgressTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->world();
        $this->clientWithApprover($this->project);
        $this->project->update(['budget_hours' => 40]);
        $this->approver->update(['can_login' => true, 'project_manager' => true]);
        $this->pm = User::create(['company_id' => $this->company->id, 'name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'password' => 'secret-password',
            'role' => 'approver', 'approver_profile_id' => $this->approver->id]);
    }

    /** Un día enviado por la trabajadora; con $approve el dueño lo aprueba. */
    private function logDay(string $day, bool $approve, ?string $end = '16:00', array $extra = []): void
    {
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry(['work_date' => $day, 'end_time' => $end] + $extra))->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        if ($approve) {
            Sanctum::actingAs($this->owner);
            $this->postJson('/api/sheets/'.$e['timesheet_id'].'/approve')->assertOk();
        }
    }

    public function test_only_a_marked_approver_sees_progress(): void
    {
        $this->approver->update(['project_manager' => false]);
        Sanctum::actingAs($this->pm);
        $this->getJson('/api/approver/progress')->assertForbidden();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('project_manager', false);
        $this->approver->update(['project_manager' => true]);
        $this->getJson('/api/me')->assertJsonPath('project_manager', true);
        $this->getJson('/api/approver/progress')->assertOk()->assertJsonCount(1)->assertJsonPath('0.code', 'P-1');
        Sanctum::actingAs($this->worker);
        $this->getJson('/api/approver/progress')->assertForbidden();
    }

    public function test_only_the_projects_of_their_clients(): void
    {
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Otro', 'customer_id' => Customer::create(['company_id' => $this->company->id, 'name' => 'Otro SA'])->id]);
        Sanctum::actingAs($this->pm);
        $this->getJson('/api/approver/progress')->assertJsonCount(1);
        $this->getJson('/api/approver/progress/'.$other->id)->assertNotFound();
        $this->getJson('/api/approver/progress/'.$this->project->id)->assertOk();
    }

    public function test_counts_approved_and_pending_but_never_drafts(): void
    {
        $this->logDay('2026-10-13', true);                    // 8 h aprobadas (semana pasada)
        $this->logDay('2026-10-20', false);                   // 8 h enviadas (esperan al aprobador)
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-06']))->assertCreated();   // borrador: no cuenta
        Sanctum::actingAs($this->pm);
        $d = $this->getJson('/api/approver/progress/'.$this->project->id)->assertOk()->json();
        $this->assertEquals(8, $d['hours']);
        $this->assertEquals(8, $d['pending_hours']);
        $this->assertEquals(40, $d['budget_hours']);
        $this->assertEquals(20, $d['pct']);
        $this->assertEquals(32, $d['remaining_hours']);
        $this->assertEquals(0, $d['this_week']);    // lo de esta semana está por aprobar
        $this->assertEquals(8, $d['last_week']);
        $this->assertEquals(8, $d['weeks'][7]['pending']);
        $this->assertCount(8, $d['weeks']);
        $this->assertSame('Ana Torres', $d['by_person'][0]['name']);
    }

    public function test_tasks_show_budget_and_lateness_but_never_the_goal_or_commissions(): void
    {
        $this->logDay('2026-10-19', true);
        Task::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Diseño', 'budget_hours' => 10,
            'estimated_end' => '2026-10-15', 'savings_goal_pct' => 20, 'commissionable' => true]);
        Sanctum::actingAs($this->pm);
        $json = $this->getJson('/api/approver/progress/'.$this->project->id)->assertOk();
        $t = $json->json('tasks.0');
        $this->assertSame('open', $t['status']);
        $this->assertTrue($t['late']);
        $this->assertEquals(10, $t['budget_hours']);
        $raw = $json->getContent();
        foreach (['savings', 'commission', 'bill_rate', 'rate', 'goal'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "no debe salir '$secret'");
        }
    }

    public function test_exports_pdf_and_xlsx(): void
    {
        $this->logDay('2026-10-19', true);
        Sanctum::actingAs($this->pm);
        foreach (['pdf', 'xlsx'] as $f) {
            $this->get('/api/approver/progress/'.$this->project->id.'/export?format='.$f)->assertOk();
        }
    }

    /** 8 h facturables aprobadas (semana pasada), 8 h de cortesía aprobadas (hace dos semanas) y 8 h de cortesía enviadas (esta semana). */
    private function courtesyWorld(bool $flag): Service
    {
        $this->project->update(['show_courtesy' => $flag]);
        $general = $this->project->services()->where('name', 'General')->first();
        $free = Service::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Garantía', 'billable' => false]);
        $this->logDay('2026-10-13', true, '16:00', ['service_id' => $general->id]);
        $this->logDay('2026-10-06', true, '16:00', ['service_id' => $free->id]);
        $this->logDay('2026-10-20', false, '16:00', ['service_id' => $free->id]);
        return $free;
    }

    public function test_courtesy_hours_are_hidden_unless_the_project_shows_them(): void
    {
        $this->courtesyWorld(false);
        Sanctum::actingAs($this->pm);
        $d = $this->getJson('/api/approver/progress/'.$this->project->id)->assertOk()->json();
        $this->assertFalse($d['courtesy_enabled']);
        $this->assertEquals(0, $d['courtesy_hours']);
        $this->assertEquals(0, $d['courtesy_pending']);
        $this->assertCount(1, $d['by_service'], 'el servicio de cortesía no aparece');
        $this->assertEquals(8, $d['hours']);
    }

    public function test_courtesy_hours_are_separate_from_the_billable_ones(): void
    {
        $free = $this->courtesyWorld(true);
        $task = \App\Models\Task::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Soporte', 'budget_hours' => 10]);
        TimeEntry::where('service_id', $free->id)->where('work_date', '2026-10-06')->update(['task_id' => $task->id]);
        Sanctum::actingAs($this->pm);
        $json = $this->getJson('/api/approver/progress/'.$this->project->id)->assertOk();
        $d = $json->json();
        $this->assertTrue($d['courtesy_enabled']);
        $this->assertEquals(8, $d['courtesy_hours']);
        $this->assertEquals(8, $d['courtesy_pending']);
        $this->assertEquals(8, $d['hours'], 'lo facturable no cambia');
        $this->assertEquals(20, $d['pct'], 'el presupuesto solo mide lo facturable');
        $this->assertEquals(0, $d['tasks'][0]['hours'], 'las horas usadas de la tarea son solo las facturables');
        $this->assertEquals(8, $d['tasks'][0]['courtesy_hours']);
        $row = collect($d['by_service'])->firstWhere('name', 'Garantía');
        $this->assertEquals(8, $row['courtesy_hours']);
        $this->assertEquals(0, $row['hours']);
        $this->assertEquals(8, $d['weeks'][5]['courtesy']);
        $this->assertEquals(8, $d['weeks'][7]['courtesy_pending']);
        // solo horas: ningún valor en dinero
        $this->assertStringNotContainsString('$', $json->getContent());
        $this->get('/api/approver/progress/'.$this->project->id.'/export?format=xlsx')->assertOk();
    }

    public function test_internal_hour_types_are_never_courtesy(): void
    {
        $this->project->update(['show_courtesy' => true]);
        $this->logDay('2026-10-13', true);
        TimeEntry::query()->update(['hour_type' => 'nb', 'billable' => false]);
        Sanctum::actingAs($this->pm);
        $d = $this->getJson('/api/approver/progress/'.$this->project->id)->json();
        $this->assertEquals(0, $d['courtesy_hours']);
        $this->assertEquals(0, $d['hours']);
    }

    // ---------- el dueño ve el avance de cualquier proyecto ----------

    public function test_the_owner_lists_every_project_of_the_company_and_nobody_else_does(): void
    {
        Project::create(['company_id' => $this->company->id, 'code' => 'INT-1', 'name' => 'Interno']);                    // sin cliente
        $other = \App\Models\Company::create(['name' => 'Otra', 'slug' => 'otra']);
        $foreign = Project::create(['company_id' => $other->id, 'code' => 'X-1', 'name' => 'De otra compañía']);
        Sanctum::actingAs($this->owner);
        $codes = collect($this->getJson('/api/progress')->assertOk()->json())->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['P-1', 'INT-1'], $codes);
        $this->getJson('/api/progress/'.$this->project->id)->assertOk()->assertJsonPath('project.code', 'P-1');
        $this->getJson('/api/progress/'.$foreign->id)->assertNotFound();
        $this->get('/api/progress/'.$this->project->id.'/export?format=xlsx')->assertOk();
        $this->get('/api/progress/'.$this->project->id.'/export?format=csv')->assertStatus(422);

        foreach ([$this->worker, $this->pm] as $who) {
            Sanctum::actingAs($who);
            $this->getJson('/api/progress')->assertForbidden();
        }
    }

    public function test_the_owner_sees_the_same_numbers_as_the_project_manager(): void
    {
        $this->logDay('2026-10-13', true);
        Sanctum::actingAs($this->pm);
        $pm = $this->getJson('/api/approver/progress/'.$this->project->id)->assertOk()->json();
        Sanctum::actingAs($this->owner);
        $o = $this->getJson('/api/progress/'.$this->project->id)->assertOk()->json();
        foreach (['hours', 'pending_hours', 'budget_hours', 'remaining_hours', 'pct', 'this_week', 'last_week', 'weeks', 'tasks', 'by_person', 'by_service'] as $k) {
            $this->assertEquals($pm[$k], $o[$k], $k);
        }
        $this->assertEquals(8, $o['hours']);
        $this->assertEquals(32, $o['remaining_hours']);
    }

    public function test_the_owner_also_sees_what_waits_for_the_internal_approval_and_always_the_courtesy_hours(): void
    {
        $supervisor = User::create(['company_id' => $this->company->id, 'name' => 'Supervisora', 'email' => 'sup@cerevelo.test', 'password' => 'secret-password', 'role' => 'member', 'worker_type' => 'employee']);
        $internal = Service::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Interno', 'approval_mode' => 'internal']);
        $internal->internalApprovers()->sync([$supervisor->id]);
        $free = Service::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Garantía', 'billable' => false]);
        $this->logDay('2026-10-20', false, '16:00', ['service_id' => $internal->id]);       // 8 h esperando a la aprobación interna
        $this->logDay('2026-10-13', true, '16:00', ['service_id' => $free->id]);            // 8 h de cortesía aprobadas (el proyecto no las muestra al cliente)
        $this->assertFalse((bool) $this->project->fresh()->show_courtesy);

        Sanctum::actingAs($this->pm);
        $pm = $this->getJson('/api/approver/progress/'.$this->project->id)->assertOk()->json();
        $this->assertEquals(0, $pm['pending_hours']);
        $this->assertFalse($pm['courtesy_enabled']);

        Sanctum::actingAs($this->owner);
        $o = $this->getJson('/api/progress/'.$this->project->id)->assertOk()->json();
        $this->assertEquals(8, $o['pending_hours']);
        $this->assertTrue($o['courtesy_enabled']);
        $this->assertEquals(8, $o['courtesy_hours']);
        $this->assertEquals(1, $o['awaiting_you']);
    }
}
