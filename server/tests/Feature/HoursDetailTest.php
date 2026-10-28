<?php

namespace Tests\Feature;

use App\Models\ContractorTerms;
use App\Models\Customer;
use App\Models\TimeEntry;
use App\Services\ClientTimesheetService;
use App\Services\ContractorInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** El detalle de horas que acompaña la factura sigue cómo se entró: hora por hora o total del día. */
class HoursDetailTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private Customer $customer;

    /** Semana aprobada: el 19/10 hora por hora (08:00–12:00) y el 20/10 por total (6 h). */
    private function mixedWeek(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->world();
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Advance', 'email' => 'a@adv.test']);
        $this->project->update(['customer_id' => $this->customer->id]);
        ContractorTerms::create(['user_id' => $this->worker->id, 'rate' => 40, 'retention_pct' => 10, 'ivu_agent' => false, 'ivu_pct' => 0, 'terms_days' => 30]);
        $this->owner->update(['approver_id' => null]);
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19', 'start_time' => '08:00', 'end_time' => '12:00']))->assertCreated();
        $e = $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-20']))->assertCreated()->json();
        TimeEntry::find($e['id'])->update(['start_time' => null, 'end_time' => null, 'hours' => 6]);
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        Sanctum::actingAs($this->owner);
        $this->approveSheet($e['timesheet_id']);
    }

    public function test_client_timesheet_lines_say_how_they_were_entered(): void
    {
        $this->mixedWeek();
        $d = $this->postJson('/api/client-timesheet/preview', ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk()->json();
        [$a, $b] = $d['projects'][0]['lines'];
        $this->assertEquals(['times', '08:00', '12:00', 4.0], [$a['entered_as'], $a['start'], $a['end'], $a['hours']]);
        $this->assertEquals(['total', null, null, 6.0], [$b['entered_as'], $b['start'], $b['end'], $b['hours']]);
        $this->assertTrue($d['has_times']);

        $csv = $this->post('/api/client-timesheet/download', ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'csv']);
        $text = $csv->getContent();
        $this->assertStringContainsString('Desde', $text);
        $this->assertStringNotContainsString('Día completo', $text);

        $html = view('pdf.client-timesheet', ['d' => $d, 'brand' => $this->company->brand(), 'logo' => null])->render();
        $this->assertStringContainsString('08:00–12:00', $html);
        $this->assertStringNotContainsString('Día completo', $html);
    }

    public function test_total_only_sheet_has_no_empty_schedule_columns(): void
    {
        $this->mixedWeek();
        TimeEntry::query()->update(['start_time' => null, 'end_time' => null]);
        $d = $this->postJson('/api/client-timesheet/preview', ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])->json();
        $this->assertFalse($d['has_times']);
        $this->assertFalse($d['projects'][0]['has_times']);

        $table = app(ClientTimesheetService::class)->asTable($d);
        $this->assertSame(['Fecha', 'Persona', 'Proyecto', 'Horas', 'Descripción'], array_column($table['columns'], 'h'));
        $this->assertCount(5, $table['rows'][0]);
        $this->assertCount(5, $table['total']);

        $html = view('pdf.client-timesheet', ['d' => $d, 'brand' => $this->company->brand(), 'logo' => null])->render();
        $this->assertStringNotContainsString('Horario', $html);
        $this->assertStringNotContainsString('Día completo', $html);

        $xlsx = app(ClientTimesheetService::class)->xlsx($d);
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsx)->getActiveSheet();
        $this->assertSame('Horas', $sheet->getCell('D6')->getValue());
        $this->assertSame('Descripción', $sheet->getCell('E6')->getValue());
        unlink($xlsx);
    }

    public function test_contractor_invoice_pdf_has_the_hours_detail_as_entered(): void
    {
        $this->mixedWeek();
        $inv = app(ContractorInvoiceService::class)->create($this->worker, '2026-10-01', '2026-10-31');
        $this->assertEquals(10.0, (float) $inv->lines[0]['hours']);               // las líneas y los importes no cambian
        $this->assertEquals(400.0, (float) $inv->gross);

        $detail = app(ContractorInvoiceService::class)->hoursDetail($inv);
        $this->assertCount(1, $detail);
        $this->assertSame(10.0, $detail[0]['hours']);
        $this->assertSame(['2026-10-19', '08:00', '12:00', 4.0], [$detail[0]['lines'][0]['date'], $detail[0]['lines'][0]['start'], $detail[0]['lines'][0]['end'], $detail[0]['lines'][0]['hours']]);
        $this->assertSame(['2026-10-20', null, null, 6.0], [$detail[0]['lines'][1]['date'], $detail[0]['lines'][1]['start'], $detail[0]['lines'][1]['end'], $detail[0]['lines'][1]['hours']]);

        $html = view('pdf.contractor-invoice', ['inv' => $inv, 'worker' => $this->worker, 'terms' => $this->worker->terms, 'brand' => $this->company->brand(),
            'logo' => null, 'company' => $this->company, 'detail' => $detail])->render();
        $this->assertStringContainsString('Detalle de horas', $html);
        $this->assertStringContainsString('08:00–12:00', $html);
        $this->assertStringContainsString('2026-10-20', $html);
        $this->assertTrue(Storage::disk('local')->exists($inv->pdf_path));
    }
}
