<?php

namespace Tests\Feature;

use App\Models\Approver;
use App\Models\ContractorInvoice;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** Lo que se exporta es lo que se ve filtrado en la tabla (incluidos los totales). */
class TableFilterExportTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private Customer $cA;
    private Customer $cB;
    private Project $p2;
    private Project $p3;
    private User $marcos;

    private function csv(string $ds, array $q = []): string
    {
        return $this->call('GET', "/api/tables/$ds/export", $q + ['format' => 'csv'])->assertOk()->getContent();
    }

    /** @return string[] filas de datos (sin encabezado) */
    private function rows(string $csv): array
    {
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($csv, "\xEF\xBB\xBF")))));
        return array_values(array_filter(array_slice($lines, 1), fn ($l) => ! str_starts_with($l, 'Total,')));   // sin la fila de total
    }

    private function setUpData(): void
    {
        Mail::fake();
        $this->world();
        $this->cA = Customer::create(['company_id' => $this->company->id, 'name' => 'Advance Logistics', 'email' => 'a@adv.test', 'accountisi_id' => '2']);
        $this->cB = Customer::create(['company_id' => $this->company->id, 'name' => 'InnovaSyst', 'active' => false]);
        $this->project->update(['customer_id' => $this->cA->id, 'name' => 'Soporte']);
        $this->p2 = Project::create(['company_id' => $this->company->id, 'customer_id' => $this->cA->id, 'code' => 'P-2', 'name' => 'Desarrollo', 'active' => false]);
        $this->p3 = Project::create(['company_id' => $this->company->id, 'customer_id' => $this->cB->id, 'code' => 'P-3', 'name' => 'Médico Niño', 'billable' => false]);
        $this->marcos = User::create(['company_id' => $this->company->id, 'name' => 'Marcos Ruiz', 'email' => 'marcos@x.test', 'password' => 'secret-password',
            'worker_type' => 'employee', 'weekly_hours' => 40, 'capture_mode' => 'full_day', 'active' => false]);
        Approver::create(['company_id' => $this->company->id, 'name' => 'Laura Gómez', 'email' => 'laura@cliente.test', 'active' => false]);
        Sanctum::actingAs($this->owner);
    }

    public function test_projects_filters(): void
    {
        $this->setUpData();
        $this->assertCount(3, $this->rows($this->csv('projects')));

        $nino = $this->rows($this->csv('projects', ['q' => 'nino']));                 // sin importar tildes ni mayúsculas
        $this->assertCount(1, $nino);
        $this->assertStringContainsString('P-3', $nino[0]);
        $this->assertCount(1, $this->rows($this->csv('projects', ['q' => 'MÉDICO'])));
        $this->assertCount(2, $this->rows($this->csv('projects', ['q' => 'advance'])));   // por nombre del cliente
        $this->assertCount(0, $this->rows($this->csv('projects', ['q' => 'advance zzz'])));

        $inactive = $this->rows($this->csv('projects', ['active' => '0']));
        $this->assertCount(1, $inactive);
        $this->assertStringContainsString('P-2', $inactive[0]);
        $this->assertCount(2, $this->rows($this->csv('projects', ['active' => '1'])));
        $this->assertCount(1, $this->rows($this->csv('projects', ['billable' => '0'])));
        $this->assertCount(2, $this->rows($this->csv('projects', ['customer_id' => $this->cA->id])));
        $both = $this->rows($this->csv('projects', ['customer_id' => $this->cA->id, 'active' => '1']));
        $this->assertCount(1, $both);
        $this->assertStringContainsString('P-1', $both[0]);
    }

    public function test_people_approvers_and_customers_filters(): void
    {
        $this->setUpData();
        $emp = $this->rows($this->csv('people', ['worker_type' => 'employee']));
        $this->assertCount(1, $emp);
        $this->assertStringContainsString('Marcos Ruiz', $emp[0]);
        $this->assertCount(1, $this->rows($this->csv('people', ['capture_mode' => 'full_day'])));
        $this->assertCount(1, $this->rows($this->csv('people', ['active' => '0'])));
        $this->assertCount(2, $this->rows($this->csv('people', ['active' => '1'])));
        $this->assertCount(1, $this->rows($this->csv('people', ['q' => 'ANA torres'])));

        $inactiveApprover = $this->rows($this->csv('approvers', ['active' => '0']));
        $this->assertCount(1, $inactiveApprover);
        $this->assertStringContainsString('Laura', $inactiveApprover[0]);
        $this->assertCount(1, $this->rows($this->csv('approvers', ['q' => 'gomez'])));      // "Gómez" sin tilde

        $linked = $this->rows($this->csv('customers', ['linked' => '1']));
        $this->assertCount(1, $linked);
        $this->assertStringContainsString('Advance Logistics', $linked[0]);
        $this->assertCount(1, $this->rows($this->csv('customers', ['linked' => '0'])));
        $this->assertCount(1, $this->rows($this->csv('customers', ['active' => '0', 'q' => 'innova'])));
    }

    public function test_invoices_filters_and_filtered_totals(): void
    {
        $this->setUpData();
        $mk = fn (User $u, string $n, string $date, string $status, float $gross) => ContractorInvoice::create([
            'company_id' => $this->company->id, 'user_id' => $u->id, 'number' => $n, 'period_from' => $date, 'period_to' => $date, 'issued_on' => $date,
            'due_on' => $date, 'gross' => $gross, 'retention' => 0, 'ivu' => 0, 'net' => $gross, 'lines' => [], 'status' => $status]);
        $mk($this->worker, 'CER-2026-0001', '2026-09-10', 'paid', 100);
        $mk($this->worker, 'CER-2026-0002', '2026-10-05', 'sent', 200);
        $mk($this->marcos, 'CER-2026-0003', '2026-10-20', 'sent', 400);

        $this->assertCount(3, $this->rows($this->csv('invoices')));
        $sent = $this->csv('invoices', ['status' => 'sent']);
        $this->assertCount(2, $this->rows($sent));
        $this->assertStringContainsString('Total,,,,600.00,', $sent);                        // el total es solo de lo filtrado
        $ana = $this->csv('invoices', ['user_id' => $this->worker->id]);
        $this->assertStringContainsString('Total,,,,300.00,', $ana);
        $range = $this->csv('invoices', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertStringContainsString('Total,,,,600.00,', $range);
        $this->assertStringNotContainsString('CER-2026-0001', $range);
        $this->assertStringContainsString('Total,,,,200.00,', $this->csv('invoices', ['q' => 'cer-2026-0002']));
        $this->assertStringContainsString('Total,,,,400.00,', $this->csv('invoices', ['q' => 'marcos', 'status' => 'sent']));
    }

    public function test_pending_filters_and_filtered_totals(): void
    {
        $this->setUpData();
        $this->owner->update(['approver_id' => null]);
        $this->marcos->update(['active' => true, 'approver_id' => $this->approver->id]);
        $this->marcos->projects()->attach($this->project->id);
        $submit = function (User $u, array $e) {
            Sanctum::actingAs($u);
            $r = $this->postJson('/api/entries', $e)->assertCreated()->json();
            $this->postJson('/api/sheets/'.$r['timesheet_id'].'/submit')->assertOk();
            return Timesheet::find($r['timesheet_id']);
        };
        $a = $submit($this->worker, $this->entry());                                          // Ana 8 h
        $m = $submit($this->marcos, ['project_id' => $this->project->id, 'work_date' => '2026-10-20', 'hours' => 4]);   // Marcos 4 h
        Timesheet::whereKey($m->id)->update(['late' => true]);
        Sanctum::actingAs($this->owner);

        $all = $this->csv('pending');
        $this->assertStringContainsString('Total,,12.00,,,', $all);
        $this->assertStringContainsString('Total,,8.00,,,', $this->csv('pending', ['user_id' => $this->worker->id]));
        $late = $this->csv('pending', ['late' => '1']);
        $this->assertCount(1, $this->rows($late));
        $this->assertStringContainsString('Marcos Ruiz', $late);
        $this->assertStringContainsString('Total,,4.00,,,', $late);
        $this->assertCount(1, $this->rows($this->csv('pending', ['q' => 'ana'])));
        $this->assertStringContainsString('Total,,0.00,,,', $this->csv('pending', ['q' => 'nadie']));
    }

    public function test_report_by_project_matches_the_screen_endpoint(): void
    {
        $this->setUpData();
        $this->worker->projects()->attach($this->p3->id);
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry())->assertCreated();                                         // 8 h en P-1
        $this->postJson('/api/entries', $this->entry(['project_id' => $this->p3->id, 'start_time' => '17:00', 'end_time' => '19:00']))->assertCreated();   // 2 h en P-3
        $q = ['from' => '2026-10-01', 'to' => '2026-10-31', 'group' => 'day'];

        $this->assertStringContainsString('Total,10.00,', $this->csv('report', $q));
        $filtered = $this->csv('report', $q + ['project_id' => $this->p3->id]);
        $this->assertStringContainsString('Total,2.00,', $filtered);
        // La pantalla (/reports/summary) y la exportación dan lo mismo con el mismo filtro.
        $screen = $this->getJson('/api/reports/summary?'.http_build_query($q + ['project_id' => $this->p3->id]))->assertOk()->json();
        $this->assertEquals(2.0, $screen['total_hours']);
        $this->assertStringContainsString('2.00', $filtered);
    }

    public function test_the_filter_is_written_in_the_file_header(): void
    {
        $this->setUpData();
        $r = $this->call('GET', '/api/tables/projects/export', ['format' => 'xlsx', 'active' => '0', 'q' => 'des']);
        $copy = tempnam(sys_get_temp_dir(), 'ff').'.xlsx';
        file_put_contents($copy, $r->getContent());
        $sheet = IOFactory::load($copy)->getActiveSheet();
        $text = '';
        for ($i = 1; $i <= 6; $i++) {
            $text .= ' '.$sheet->getCell('A'.$i)->getValue();
        }
        $this->assertStringContainsString('Filtro:', $text);
        $this->assertStringContainsString('texto "des"', $text);
        $this->assertStringContainsString('solo inactivos', $text);
        unlink($copy);

        $html = app(\App\Services\Tables\TableExporter::class)->html($this->company, (new \App\Services\Tables\Datasets())->build('projects', $this->owner, ['q' => 'des', 'active' => '0']), null);
        $this->assertStringContainsString('Filtro: texto', $html);
        $this->assertStringContainsString('solo inactivos', $html);
        $this->assertStringNotContainsString('P-1', $html);                                  // lo filtrado no aparece
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $this->setUpData();
        $this->call('GET', '/api/tables/people/export', ['format' => 'csv', 'worker_type' => 'jefe'], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(422);
        $this->call('GET', '/api/tables/invoices/export', ['format' => 'csv', 'from' => 'ayer'], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(422);
    }
}
