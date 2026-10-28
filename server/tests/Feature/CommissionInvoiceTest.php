<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionResult;
use App\Models\ContractorTerms;
use App\Models\Customer;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * La factura del consultor: sus horas, siempre a su tarifa normal, y las comisiones LIBERADAS en una línea aparte por proyecto
 * (totales globalizados, con el detalle por tarea), con retención e IVU solo si esa comisión los lleva.
 * Tarifa 40, retención 10%, IVU 4% (agente). Hay 12 h aprobadas = 480.
 */
class CommissionInvoiceTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private Task $task;

    private function setUpInvoice(bool $withHours = true): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->world();
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Advance Logistics']);
        $this->project->update(['customer_id' => $customer->id]);
        ContractorTerms::create(['user_id' => $this->worker->id, 'rate' => 40, 'retention_pct' => 10, 'ivu_agent' => true, 'ivu_pct' => 4, 'terms_days' => 30]);
        $this->owner->update(['approver_id' => null]);
        if ($withHours) {
            foreach (['2026-10-20' => '16:00', '2026-10-13' => '12:00'] as $day => $end) {
                Sanctum::actingAs($this->worker);
                $e = $this->postJson('/api/entries', $this->entry(['work_date' => $day, 'end_time' => $end]))->assertCreated()->json();
                $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
                Sanctum::actingAs($this->owner);
                $this->postJson('/api/sheets/'.$e['timesheet_id'].'/approve')->assertOk();
            }
        }
        // La tarea se crea después de registrar las horas: con una tarea abierta, el proyecto exigiría escogerla en cada entrada.
        $this->task = Task::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Diseño',
            'budget_hours' => 20, 'savings_goal_pct' => 20, 'commissionable' => true]);
    }

    private function released(array $over = []): CommissionResult
    {
        $commission = Commission::firstOrCreate(['user_id' => $this->worker->id, 'project_id' => $this->project->id],
            ['company_id' => $this->company->id, 'percent_required' => 80, 'extra_per_hour' => 5]);
        return CommissionResult::create($over + [
            'company_id' => $this->company->id, 'commission_id' => $commission->id, 'user_id' => $this->worker->id, 'project_id' => $this->project->id,
            'task_id' => $this->task->id, 'status' => 'released', 'completed_on' => '2026-10-21', 'budget_hours' => 20, 'goal_hours' => 16, 'total_hours' => 16,
            'user_hours' => 13, 'user_pct' => 81.25, 'saved_hours' => 4, 'percent_required' => 80, 'extra_per_hour' => 5, 'savings_mode' => 'fixed',
            'savings_pct' => 100, 'savings_per_hour' => 5, 'met_goal' => true, 'met_date' => true, 'worked_min' => true, 'met_share' => true,
            'commission_amount' => 65, 'savings_amount' => 20, 'adjustment' => 0, 'total' => 85, 'withhold' => true, 'ivu' => false,
        ]);
    }

    private function invoice(?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->worker);
        return $this->postJson('/api/contractor-invoices', ['from' => '2026-10-01', 'to' => '2026-10-31']);
    }

    public function test_a_released_commission_goes_in_its_own_line_and_the_hours_keep_the_normal_rate(): void
    {
        $this->setUpInvoice();
        $this->released();   // 85, con retención y sin IVU
        $inv = $this->invoice()->assertCreated()->json();

        $this->assertCount(2, $inv['lines']);
        $hours = $inv['lines'][0];
        $this->assertEquals([12, 40, 480], [$hours['hours'], $hours['rate'], $hours['amount']]);   // las horas, a 40 como siempre
        $c = $inv['lines'][1];
        $this->assertSame('commission', $c['kind']);
        $this->assertStringContainsString('P-1', $c['description']);
        $this->assertEquals(85, $c['amount']);
        $this->assertSame('Diseño', $c['details'][0]['task']);
        $this->assertEquals([65, 20, 85], [$c['details'][0]['commission'], $c['details'][0]['savings'], $c['details'][0]['total']]);

        // Bruto 565. La retención (10%) cubre horas y comisión: 56.50. El IVU (4%) solo las horas, porque la comisión no lo lleva: 19.20.
        $this->assertEquals([565, 56.5, 19.2, 489.3], [(float) $inv['gross'], (float) $inv['retention'], (float) $inv['ivu'], (float) $inv['net']]);
        $r = CommissionResult::firstOrFail();
        $this->assertSame('invoiced', $r->status);
        $this->assertSame($inv['id'], $r->contractor_invoice_id);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($inv['pdf_path']));
    }

    public function test_retention_and_ivu_follow_what_each_commission_carries(): void
    {
        $this->setUpInvoice();
        $this->released(['withhold' => false, 'ivu' => true]);   // sin retención, con IVU
        $inv = $this->invoice()->assertCreated()->json();
        $this->assertEquals(565, (float) $inv['gross']);
        $this->assertEquals(48, (float) $inv['retention']);        // 10% solo de las horas (480)
        $this->assertEquals(22.6, (float) $inv['ivu']);            // 4% de 565
    }

    public function test_only_released_commissions_of_that_person_are_invoiced_and_only_once(): void
    {
        $this->setUpInvoice();
        $other = Task::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Pruebas', 'budget_hours' => 10, 'commissionable' => true]);
        $third = Task::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Otra', 'budget_hours' => 10, 'commissionable' => true]);
        $this->released();                                                              // se factura
        $this->released(['task_id' => $other->id, 'status' => 'pending']);              // sin liberar: no
        $this->released(['task_id' => $third->id, 'status' => 'none', 'total' => 0]);   // sin comisión: no
        $ana = User::create(['company_id' => $this->company->id, 'name' => 'Otra Persona', 'email' => 'otra@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'consultant', 'capture_mode' => 'from_to']);
        $c = Commission::create(['company_id' => $this->company->id, 'user_id' => $ana->id, 'project_id' => $this->project->id, 'percent_required' => 80, 'extra_per_hour' => 5]);
        $this->released(['user_id' => $ana->id, 'commission_id' => $c->id]);            // de otra persona: no

        $inv = $this->invoice()->assertCreated()->json();
        $this->assertEquals(85, collect($inv['lines'])->firstWhere('kind', 'commission')['amount']);
        $this->assertSame(['invoiced', 'pending', 'none', 'released'], CommissionResult::orderBy('id')->pluck('status')->all());

        // Liberada después, sale en la próxima factura; la ya facturada no se repite.
        CommissionResult::where('status', 'pending')->update(['status' => 'released']);
        Timesheet::query()->update(['contractor_invoice_id' => null]);   // para poder volver a facturar horas
        $second = $this->invoice()->assertCreated()->json();
        $commissionLines = collect($second['lines'])->where('kind', 'commission');
        $this->assertCount(1, $commissionLines);
        $this->assertEquals(85, $commissionLines->first()['amount']);
        $this->assertSame('Pruebas', $commissionLines->first()['details'][0]['task']);
    }

    public function test_an_invoice_can_be_only_commissions_when_there_are_no_weeks_to_bill(): void
    {
        $this->setUpInvoice(false);
        $this->invoice()->assertStatus(422)->assertJsonValidationErrors('from');   // ni horas ni comisión liberada
        $this->released();
        $inv = $this->invoice()->assertCreated()->json();
        $this->assertCount(1, $inv['lines']);
        $this->assertEquals([85, 8.5, 0, 76.5], [(float) $inv['gross'], (float) $inv['retention'], (float) $inv['ivu'], (float) $inv['net']]);
    }

    public function test_the_owner_invoices_for_a_consultant_and_sees_which_commissions_are_waiting(): void
    {
        $this->setUpInvoice();
        $this->released();
        $this->released(['task_id' => Task::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Pruebas', 'budget_hours' => 10, 'commissionable' => true])->id, 'total' => 15]);

        Sanctum::actingAs($this->worker);
        $mine = $this->getJson('/api/contractor-invoices/pending-commissions')->assertOk()->json();
        $this->assertEquals(100, $mine['total']);
        $this->assertCount(2, $mine['items']);
        Sanctum::actingAs($this->owner);
        $this->assertEquals(100, $this->getJson('/api/contractor-invoices/pending-commissions?user_id='.$this->worker->id)->json('total'));
        Sanctum::actingAs($this->worker);
        $this->getJson('/api/contractor-invoices/pending-commissions?user_id='.$this->owner->id)->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/contractor-invoices', ['from' => '2026-10-01', 'to' => '2026-10-31', 'user_id' => $this->worker->id])->assertCreated();
        Sanctum::actingAs($this->worker);
        $this->assertEquals(0, $this->getJson('/api/contractor-invoices/pending-commissions')->json('total'));
        $list = $this->getJson('/api/contractor-invoices')->assertOk()->json();
        $this->assertTrue($list[0]['has_commission']);
    }

    public function test_the_invoice_without_commissions_is_exactly_as_before(): void
    {
        $this->setUpInvoice();
        $inv = $this->invoice()->assertCreated()->json();
        $this->assertCount(1, $inv['lines']);
        $this->assertEquals([480, 48, 19.2, 412.8], [(float) $inv['gross'], (float) $inv['retention'], (float) $inv['ivu'], (float) $inv['net']]);
        Sanctum::actingAs($this->worker);
        $this->assertFalse($this->getJson('/api/contractor-invoices')->json('0.has_commission'));
    }

    public function test_the_worker_sees_the_invoiced_commission_as_invoiced_and_the_task_cannot_be_reopened(): void
    {
        $this->setUpInvoice();
        $this->task->update(['active' => false, 'completed_on' => '2026-10-21']);
        Commission::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'project_id' => $this->project->id, 'percent_required' => 80, 'extra_per_hour' => 5])
            ->tasks()->sync([$this->task->id]);
        $this->released();
        $this->invoice()->assertCreated();

        $t = $this->getJson('/api/me/commissions')->assertOk()->json('0.tasks.0');
        $this->assertSame('invoiced', $t['status']);
        $this->assertEquals(85, $t['earned']['total']);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/tasks/'.$this->task->id.'/reopen', ['signature' => 'secret-password'])->assertStatus(422);
    }
}
