<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionResult;
use App\Models\ContractorTerms;
use App\Models\Customer;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\TimesheetEvent;
use App\Models\TimesheetInternalApproval;
use App\Services\Accountisi\SalesInvoiceService;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * Cuando el cliente no aprueba: el dueño CIERRA la hoja (aprobada por la compañía, no se factura al cliente), la ANULA (horas que no valen, sin
 * facturar) o la PONE EN PAUSA (sin recordatorios). Y la comisión de un consultor con la opción de facturar con la aprobación interna se calcula
 * pero no se libera mientras las horas esperan al cliente.
 */
class SheetCloseVoidHoldTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    /** Hoja de 8 h enviada, en la etapa del cliente, con aprobación interna; el consultor tiene la opción. */
    private function waitingSheet(bool $option = true, string $stage = 'external'): Timesheet
    {
        Mail::fake();
        Storage::fake('local');
        $this->world();
        ContractorTerms::create(['user_id' => $this->worker->id, 'rate' => 40, 'terms_days' => 30, 'bill_on_internal_approval' => $option]);
        $sheet = Timesheet::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'week_ending' => '2026-10-25', 'status' => Timesheet::DRAFT,
            'stage' => $stage, 'version' => 1, 'total_hours' => 8, 'submitted_at' => now()->subDays(10)]);
        TimeEntry::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'project_id' => $this->project->id,
            'service_id' => $this->project->services()->first()->id, 'timesheet_id' => $sheet->id, 'work_date' => '2026-10-20',
            'start_time' => '08:00', 'end_time' => '16:00', 'hours' => 8, 'billable' => true, 'running' => false]);
        Timesheet::whereKey($sheet->id)->update(['status' => Timesheet::SUBMITTED]);
        TimesheetInternalApproval::create(['timesheet_id' => $sheet->id, 'project_id' => $this->project->id, 'service_id' => $this->project->services()->first()->id,
            'version' => 1, 'approved_by_user_id' => $this->owner->id, 'skipped' => false, 'approved_at' => now()]);
        return $sheet;
    }

    private function owner(string $method, string $url, array $body = [])
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner);
        return $this->postJson($url, $body + ['signature' => 'secret-password']);
    }

    public function test_the_owner_closes_a_sheet_the_client_will_not_approve(): void
    {
        $sheet = $this->waitingSheet();
        $this->owner('post', '/api/sheets/'.$sheet->id.'/close', ['comment' => 'El cliente no respondió'])->assertOk()->assertJsonPath('status', 'approved')
            ->assertJsonPath('closed_by_company', true);

        $sheet->refresh();
        $this->assertSame('company_close', $sheet->decided_via);
        $this->assertNull($sheet->stage);
        $this->assertTrue(TimesheetEvent::where('timesheet_id', $sheet->id)->where('type', 'closed_by_company')->exists());
    }

    public function test_a_closed_sheet_is_not_billed_to_the_client_but_a_normally_approved_one_is(): void
    {
        $sheet = $this->waitingSheet();
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Cliente SA']);
        $this->project->update(['customer_id' => $customer->id, 'billable' => true]);

        $this->owner('post', '/api/sheets/'.$sheet->id.'/close', ['comment' => 'Lo absorbe la compañía'])->assertOk();
        $plan = app(SalesInvoiceService::class)->preview($this->company, $customer, '2026-10-01', '2026-10-31');
        $this->assertSame([], $plan['lines']);

        Timesheet::whereKey($sheet->id)->update(['closed_by_company' => false]);   // como si el cliente sí hubiera aprobado
        $plan = app(SalesInvoiceService::class)->preview($this->company, $customer, '2026-10-01', '2026-10-31');
        $this->assertNotEmpty($plan['lines']);
    }

    public function test_only_a_sheet_waiting_for_the_client_can_be_closed(): void
    {
        $sheet = $this->waitingSheet(true, 'internal');
        $this->owner('post', '/api/sheets/'.$sheet->id.'/close', ['comment' => 'Cerrarla ya'])->assertStatus(422);
        $this->assertSame('submitted', $sheet->fresh()->status);
    }

    public function test_the_owner_voids_a_sheet_and_the_days_are_free_again(): void
    {
        $sheet = $this->waitingSheet();
        $this->owner('post', '/api/sheets/'.$sheet->id.'/void', ['comment' => 'Horas duplicadas'])->assertOk()->assertJsonPath('status', 'void');

        $this->assertSame(0, TimeEntry::where('timesheet_id', $sheet->id)->count());
        $ev = TimesheetEvent::where('timesheet_id', $sheet->id)->where('type', 'voided')->first();
        $this->assertNotNull($ev);
        $this->assertEquals(8.0, $ev->data['hours']);

        // La persona puede volver a anotar horas ese día: la hoja anulada no cubre nada.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-20']))->assertStatus(201);
    }

    public function test_an_invoiced_sheet_cannot_be_voided(): void
    {
        $sheet = $this->waitingSheet();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/contractor-invoices', ['from' => '2026-10-01', 'to' => '2026-10-31', 'signature' => 'secret-password'])->assertCreated();

        $this->owner('post', '/api/sheets/'.$sheet->id.'/void', ['comment' => 'Por error'])->assertStatus(422);
        $this->assertSame('submitted', $sheet->fresh()->status);
    }

    public function test_a_sheet_on_hold_gets_no_reminders_until_the_hold_is_lifted(): void
    {
        $sheet = $this->waitingSheet(false);
        $sheet->internalApprovals()->delete();
        $this->assertSame(1, app(ApprovalService::class)->sendReminders());

        Timesheet::whereKey($sheet->id)->update(['last_reminded_at' => null]);
        $this->owner('post', '/api/sheets/'.$sheet->id.'/hold', ['until' => '2026-11-30', 'comment' => 'La gerencia del cliente paga en noviembre'])
            ->assertOk()->assertJsonPath('hold_until', '2026-11-30');
        $this->assertSame(0, app(ApprovalService::class)->sendReminders());

        $this->owner('post', '/api/sheets/'.$sheet->id.'/hold')->assertOk()->assertJsonPath('hold_until', null);
        $this->assertSame(1, app(ApprovalService::class)->sendReminders());
    }

    public function test_the_hold_never_moves_the_dates_of_the_hours(): void
    {
        $sheet = $this->waitingSheet();
        $this->owner('post', '/api/sheets/'.$sheet->id.'/hold', ['until' => '2026-12-01', 'comment' => 'Pausa'])->assertOk();
        $this->assertSame('2026-10-20', TimeEntry::where('timesheet_id', $sheet->id)->first()->work_date->toDateString());
    }

    /** Tarea con comisión pagable sobre esas 8 h. */
    private function taskWithCommission(): Task
    {
        $task = Task::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Diseño', 'budget_hours' => 20,
            'savings_goal_pct' => 20, 'commissionable' => true]);
        TimeEntry::query()->update(['task_id' => $task->id]);
        Commission::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'project_id' => $this->project->id, 'percent_required' => 80,
            'extra_per_hour' => 5, 'savings_mode' => 'fixed', 'savings_pct' => 100, 'savings_per_hour' => 5, 'tolerance_hours' => 0])->tasks()->sync([$task->id]);
        return $task;
    }

    public function test_the_commission_is_calculated_with_internal_approval_but_not_released_until_the_client_approves_or_the_owner_closes(): void
    {
        $sheet = $this->waitingSheet();
        $task = $this->taskWithCommission();

        // Pedir liberarla ya se rechaza; completar sin liberar la deja pendiente.
        $this->owner('post', '/api/tasks/'.$task->id.'/complete', ['release' => true])->assertStatus(422)->assertJsonPath('code', 'awaiting_client');
        $this->owner('post', '/api/tasks/'.$task->id.'/complete', [])->assertOk();
        $result = CommissionResult::where('task_id', $task->id)->where('user_id', $this->worker->id)->first();
        $this->assertSame('pending', $result->status);
        $this->assertGreaterThan(0, (float) $result->total);

        $this->owner('post', '/api/commission-results/'.$result->id.'/release')->assertStatus(422);
        $this->assertSame('pending', $result->fresh()->status);

        // El dueño cierra la hoja: ya se puede liberar.
        $this->owner('post', '/api/sheets/'.$sheet->id.'/close', ['comment' => 'Honramos la comisión'])->assertOk();
        $this->owner('post', '/api/commission-results/'.$result->id.'/release')->assertOk();
        $this->assertSame('released', $result->fresh()->status);
    }

    public function test_without_the_option_the_hours_still_need_the_client_before_the_task_can_be_completed(): void
    {
        $this->waitingSheet(false);
        $task = $this->taskWithCommission();
        $this->owner('post', '/api/tasks/'.$task->id.'/complete', ['release' => true])->assertStatus(422)->assertJsonPath('code', 'pending_hours');
    }
}
