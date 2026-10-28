<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
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
    private function logDay(string $day, bool $approve, ?string $end = '16:00'): void
    {
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry(['work_date' => $day, 'end_time' => $end]))->assertCreated()->json();
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
}
