<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** El gerente de proyecto ve el reporte de horas de los proyectos de sus clientes (y solo eso), sin exportar (la pantalla no ofrece exportes). */
class ApproverReportTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->world();
        $this->clientWithApprover($this->project);
        $this->approver->update(['can_login' => true, 'project_manager' => true]);
        $this->pm = User::create(['company_id' => $this->company->id, 'name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'password' => 'secret-password',
            'role' => 'approver', 'approver_profile_id' => $this->approver->id]);
    }

    private function sheetWith(Project $project, string $status, ?string $stage, float $hours): void
    {
        $sheet = Timesheet::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'week_ending' => '2026-10-25', 'status' => Timesheet::DRAFT,
            'version' => 1, 'total_hours' => $hours]);
        TimeEntry::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'project_id' => $project->id, 'service_id' => $project->services()->first()->id,
            'timesheet_id' => $sheet->id, 'work_date' => '2026-10-20', 'hours' => $hours, 'billable' => true, 'running' => false]);
        Timesheet::whereKey($sheet->id)->update(['status' => $status, 'stage' => $stage]);
    }

    public function test_it_only_counts_the_projects_of_their_clients_and_hours_already_past_the_internal_stage(): void
    {
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Otro', 'customer_id' => Customer::create(['company_id' => $this->company->id, 'name' => 'Otro SA'])->id]);
        $this->worker->projects()->attach($other->id);
        $this->sheetWith($this->project, Timesheet::APPROVED, null, 8);
        $this->sheetWith($this->project, Timesheet::SUBMITTED, Timesheet::STAGE_EXTERNAL, 4);
        $this->sheetWith($this->project, Timesheet::SUBMITTED, Timesheet::STAGE_INTERNAL, 2);   // todavía en la etapa interna: no cuenta
        $this->sheetWith($this->project, Timesheet::DRAFT, null, 1);                          // borrador: no cuenta
        $this->sheetWith($other, Timesheet::APPROVED, null, 6);                               // proyecto de otro cliente: no se ve

        Sanctum::actingAs($this->pm);
        $r = $this->getJson('/api/approver/reports/summary?from=2026-10-01&to=2026-10-31')->assertOk();
        $r->assertJsonPath('total_hours', 12)->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.label', 'P-1 · Planta A');
        $this->getJson('/api/approver/reports/summary?from=2026-10-01&to=2026-10-31&project_id='.$other->id)->assertOk()->assertJsonPath('total_hours', 0);
    }

    public function test_only_a_marked_approver_sees_it(): void
    {
        $this->approver->update(['project_manager' => false]);
        Sanctum::actingAs($this->pm);
        $this->getJson('/api/approver/reports/summary?from=2026-10-01&to=2026-10-31')->assertForbidden();
        $this->getJson('/api/approver/client-timesheet/options')->assertForbidden();
        $this->approver->update(['project_manager' => true]);
        $this->getJson('/api/approver/reports/summary?from=2026-10-01&to=2026-10-31')->assertOk();
        Sanctum::actingAs($this->worker);
        $this->getJson('/api/approver/reports/summary?from=2026-10-01&to=2026-10-31')->assertForbidden();
    }

    public function test_the_hours_report_can_be_exported_with_the_same_table_buttons(): void
    {
        $this->sheetWith($this->project, Timesheet::APPROVED, null, 8);
        Sanctum::actingAs($this->pm);
        $r = $this->get('/api/tables/report/export?format=csv&from=2026-10-01&to=2026-10-31')->assertOk();
        $this->assertSame('1', $r->headers->get('X-Export-Rows'));
        $this->assertStringContainsString('P-1', $r->getContent());
        $this->get('/api/tables/pending/export?format=csv')->assertForbidden();   // lo demás del dueño sigue cerrado
    }

    public function test_the_client_timesheet_is_the_same_but_only_for_their_clients(): void
    {
        $otherCustomer = Customer::create(['company_id' => $this->company->id, 'name' => 'Otro SA']);
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Otro', 'customer_id' => $otherCustomer->id]);
        $this->worker->projects()->attach($other->id);
        $this->sheetWith($this->project, Timesheet::APPROVED, null, 8);
        $this->sheetWith($this->project, Timesheet::SUBMITTED, Timesheet::STAGE_EXTERNAL, 4);
        $this->sheetWith($this->project, Timesheet::SUBMITTED, Timesheet::STAGE_INTERNAL, 2);

        Sanctum::actingAs($this->pm);
        $o = $this->getJson('/api/approver/client-timesheet/options')->assertOk();
        $o->assertJsonCount(1, 'customers')->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.code', 'P-1');
        $body = ['customer_id' => $this->project->customer_id, 'from' => '2026-10-01', 'to' => '2026-10-31'];
        $this->postJson('/api/approver/client-timesheet/preview', $body)->assertOk()->assertJsonPath('total_hours', 8);
        // con "incluir lo no aprobado" ve lo que ya está con el cliente, pero nunca lo que sigue en la etapa interna
        $this->postJson('/api/approver/client-timesheet/preview', $body + ['include_pending' => true])->assertOk()->assertJsonPath('total_hours', 12);
        $this->postJson('/api/approver/client-timesheet/preview', ['customer_id' => $otherCustomer->id] + $body)->assertNotFound();
        $this->post('/api/approver/client-timesheet/download', $body + ['format' => 'csv'])->assertOk();
        $this->postJson('/api/client-timesheet/preview', $body)->assertForbidden();   // las rutas del dueño siguen cerradas
    }
}
