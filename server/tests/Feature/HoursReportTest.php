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

/** El reporte de horas: el dueño y la oficina ven toda la compañía (por persona, cliente y proyecto) y los totales salen separados por estado. */
class HoursReportTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->world();
        $this->other = User::create(['company_id' => $this->company->id, 'name' => 'Pedro Vega', 'email' => 'pedro@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'consultant', 'capture_mode' => 'from_to']);
    }

    private function hours(User $u, string $status, float $hours): void
    {
        $sheet = Timesheet::create(['company_id' => $this->company->id, 'user_id' => $u->id, 'week_ending' => '2026-10-25', 'status' => Timesheet::DRAFT, 'version' => 1, 'total_hours' => $hours]);
        TimeEntry::create(['company_id' => $this->company->id, 'user_id' => $u->id, 'project_id' => $this->project->id, 'service_id' => $this->project->services()->first()->id,
            'timesheet_id' => $sheet->id, 'work_date' => '2026-10-20', 'hours' => $hours, 'billable' => true, 'running' => false]);
        Timesheet::whereKey($sheet->id)->update(['status' => $status]);
    }

    public function test_the_owner_sees_everyones_hours_by_person_split_by_status(): void
    {
        $this->hours($this->worker, Timesheet::APPROVED, 8);
        $this->hours($this->other, Timesheet::SUBMITTED, 4);
        $this->hours($this->other, Timesheet::DRAFT, 1);
        Sanctum::actingAs($this->owner);
        $r = $this->getJson('/api/reports/summary?from=2026-10-01&to=2026-10-31&group=person')->assertOk();
        $r->assertJsonPath('total_hours', 13)->assertJsonPath('total.approved_hours', 8)->assertJsonPath('total.submitted_hours', 4)->assertJsonPath('total.draft_hours', 1);
        $r->assertJsonCount(2, 'rows')->assertJsonPath('rows.0.label', 'Ana Torres')->assertJsonPath('rows.1.label', 'Pedro Vega')->assertJsonPath('rows.1.submitted_hours', 4);
        // filtrar por persona
        $this->getJson('/api/reports/summary?from=2026-10-01&to=2026-10-31&user_id='.$this->other->id)->assertOk()->assertJsonPath('total_hours', 5);
    }

    public function test_filter_by_client_and_a_worker_only_sees_their_own_hours(): void
    {
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Otro', 'customer_id' => Customer::create(['company_id' => $this->company->id, 'name' => 'Otro SA'])->id]);
        $this->hours($this->worker, Timesheet::APPROVED, 8);
        $this->hours($this->other, Timesheet::APPROVED, 3);
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/reports/summary?from=2026-10-01&to=2026-10-31&customer_id='.$other->customer_id)->assertOk()->assertJsonPath('total_hours', 0);
        Sanctum::actingAs($this->other);
        $this->getJson('/api/reports/summary?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonPath('total_hours', 3);
        $this->getJson('/api/reports/summary?from=2026-10-01&to=2026-10-31&user_id='.$this->worker->id)->assertOk()->assertJsonPath('total_hours', 3);   // no puede pedir las de otro
    }
}
