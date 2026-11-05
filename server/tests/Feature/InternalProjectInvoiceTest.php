<?php

namespace Tests\Feature;

use App\Models\ContractorTerms;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * Un proyecto interno de la compañía (sin cliente, no facturable) no se cobra a nadie, pero el consultor que trabaja en él sí factura sus horas:
 * la factura del consultor paga todo el trabajo aprobado, se cobre o no al cliente.
 */
class InternalProjectInvoiceTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    public function test_a_consultant_invoices_hours_of_an_internal_project_that_is_not_billed_to_anyone(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->world();
        $this->project->update(['customer_id' => null, 'billable' => false]);
        $service = $this->project->services()->first();
        $service->update(['billable' => false]);
        ContractorTerms::create(['user_id' => $this->worker->id, 'rate' => 40, 'terms_days' => 30]);

        $sheet = Timesheet::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'week_ending' => '2026-10-25', 'status' => Timesheet::DRAFT,
            'version' => 1, 'total_hours' => 8]);
        TimeEntry::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'project_id' => $this->project->id, 'service_id' => $service->id,
            'timesheet_id' => $sheet->id, 'work_date' => '2026-10-20', 'start_time' => '08:00', 'end_time' => '16:00', 'hours' => 8, 'billable' => false, 'running' => false]);
        Timesheet::whereKey($sheet->id)->update(['status' => Timesheet::APPROVED]);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->worker);
        $inv = $this->postJson('/api/contractor-invoices', ['from' => '2026-10-01', 'to' => '2026-10-31', 'signature' => 'secret-password'])->assertCreated()->json();

        $this->assertEquals(320.0, (float) $inv['gross']);                 // 8 h × 40
        $this->assertNotNull($sheet->fresh()->contractor_invoice_id);
        $this->assertNotEmpty(app(\App\Services\ContractorInvoiceService::class)->hoursDetail(\App\Models\ContractorInvoice::find($inv['id'])));
    }
}
