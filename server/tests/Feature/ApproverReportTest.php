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
        $this->approver->update(['project_manager' => true]);
        $this->getJson('/api/approver/reports/summary?from=2026-10-01&to=2026-10-31')->assertOk();
        Sanctum::actingAs($this->worker);
        $this->getJson('/api/approver/reports/summary?from=2026-10-01&to=2026-10-31')->assertForbidden();
    }
}
