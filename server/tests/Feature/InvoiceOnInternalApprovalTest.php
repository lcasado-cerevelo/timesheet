<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\ContractorTerms;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\TimesheetInternalApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * Un consultor con la opción "facturar con la aprobación interna" cobra una hoja que ya aprobó alguien de la compañía aunque siga a la
 * espera del cliente. Sin la opción, o sin aprobación interna real, la hoja espera como siempre. Las comisiones no cambian: se calculan
 * con horas aprobadas por completo, así que no se liberan hasta que el cliente apruebe.
 */
class InvoiceOnInternalApprovalTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function setUpSheet(bool $option, string $stage = 'external', bool $internalApproved = true, bool $skipped = false): Timesheet
    {
        Mail::fake();
        Storage::fake('local');
        $this->world();
        ContractorTerms::create(['user_id' => $this->worker->id, 'rate' => 40, 'terms_days' => 30, 'bill_on_internal_approval' => $option]);
        $sheet = Timesheet::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'week_ending' => '2026-10-25', 'status' => Timesheet::DRAFT,
            'stage' => $stage, 'version' => 1, 'total_hours' => 8]);
        TimeEntry::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'project_id' => $this->project->id,
            'service_id' => $this->project->services()->first()->id, 'timesheet_id' => $sheet->id, 'work_date' => '2026-10-20',
            'start_time' => '08:00', 'end_time' => '16:00', 'hours' => 8, 'billable' => true, 'running' => false]);
        Timesheet::whereKey($sheet->id)->update(['status' => Timesheet::SUBMITTED]);   // las entradas se crean con la hoja abierta
        if ($internalApproved) {
            TimesheetInternalApproval::create(['timesheet_id' => $sheet->id, 'project_id' => $this->project->id, 'service_id' => $this->project->services()->first()->id,
                'version' => 1, 'approved_by_user_id' => $skipped ? null : $this->owner->id, 'skipped' => $skipped, 'approved_at' => now()]);
        }
        return $sheet;
    }

    private function invoice()
    {
        Sanctum::actingAs($this->worker);
        return $this->postJson('/api/contractor-invoices', ['from' => '2026-10-01', 'to' => '2026-10-31', 'signature' => 'secret-password']);
    }

    public function test_with_the_option_an_internally_approved_sheet_is_invoiced_while_it_waits_for_the_client(): void
    {
        $sheet = $this->setUpSheet(true);
        $this->invoice()->assertCreated()->assertJsonPath('gross', '320.00');

        $sheet->refresh();
        $this->assertNotNull($sheet->contractor_invoice_id);
        $this->assertSame(Timesheet::SUBMITTED, $sheet->status);   // el cliente aún no la aprobó
        $this->invoice()->assertStatus(422);                       // y no se factura dos veces
    }

    public function test_without_the_option_the_sheet_waits_for_the_client(): void
    {
        $sheet = $this->setUpSheet(false);
        $this->invoice()->assertStatus(422);
        $this->assertNull($sheet->fresh()->contractor_invoice_id);
    }

    public function test_an_internal_part_skipped_for_lack_of_another_approver_counts_as_approved(): void
    {
        $this->setUpSheet(true, 'external', true, true);
        $this->invoice()->assertCreated();
    }

    public function test_more_hours_approved_later_come_out_as_a_positive_adjustment_in_the_next_invoice(): void
    {
        $sheet = $this->setUpSheet(true);
        $this->invoice()->assertCreated()->assertJsonPath('gross', '320.00');

        // El cliente resuelve por más: 2 h más en el mismo proyecto, y la hoja queda aprobada del todo.
        Timesheet::whereKey($sheet->id)->update(['status' => Timesheet::DRAFT]);
        TimeEntry::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'project_id' => $this->project->id,
            'service_id' => $this->project->services()->first()->id, 'timesheet_id' => $sheet->id, 'work_date' => '2026-10-21',
            'start_time' => '08:00', 'end_time' => '10:00', 'hours' => 2, 'billable' => true, 'running' => false]);
        Timesheet::whereKey($sheet->id)->update(['status' => Timesheet::APPROVED]);

        $second = $this->invoice()->assertCreated()->json();
        $this->assertEquals(80.0, (float) $second['gross']);                       // 2 h × 40, solo la diferencia
        $this->assertSame('adjustment', $second['lines'][0]['kind']);
        $this->invoice()->assertStatus(422);                                        // y no se vuelve a ajustar
    }

    public function test_fewer_hours_never_produce_a_negative_adjustment(): void
    {
        $sheet = $this->setUpSheet(true);
        $this->invoice()->assertCreated();
        Timesheet::whereKey($sheet->id)->update(['status' => Timesheet::DRAFT]);
        TimeEntry::query()->update(['hours' => 5]);
        Timesheet::whereKey($sheet->id)->update(['status' => Timesheet::APPROVED]);
        $this->invoice()->assertStatus(422);
    }

    public function test_the_option_needs_a_real_internal_approval(): void
    {
        $this->setUpSheet(true, 'external', false);
        $this->invoice()->assertStatus(422);
    }

    public function test_a_sheet_still_in_the_internal_stage_is_not_invoiced(): void
    {
        $this->setUpSheet(true, 'internal', false);
        $this->invoice()->assertStatus(422);
    }

    public function test_a_commission_cannot_be_released_while_the_hours_wait_for_the_client(): void
    {
        $this->setUpSheet(true);
        $task = Task::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Diseño', 'budget_hours' => 20,
            'savings_goal_pct' => 20, 'commissionable' => true]);
        TimeEntry::query()->update(['task_id' => $task->id]);
        Commission::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'project_id' => $this->project->id, 'percent_required' => 80,
            'extra_per_hour' => 5, 'savings_mode' => 'fixed', 'savings_pct' => 100, 'savings_per_hour' => 5, 'tolerance_hours' => 0])->tasks()->sync([$task->id]);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/tasks/'.$task->id.'/complete', ['release' => true, 'signature' => 'secret-password'])->assertStatus(422)->assertJsonPath('code', 'awaiting_client');
    }
}
