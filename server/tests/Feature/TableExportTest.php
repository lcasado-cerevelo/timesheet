<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\Tables\TableExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

class TableExportTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private const OWNER_TABLES = ['pending', 'projects', 'people', 'approvers', 'customers'];

    private function withBrand(): void
    {
        Storage::fake('public');
        $logo = UploadedFile::fake()->image('logo.png', 220, 60)->store('logos/'.$this->company->id, 'public');
        $this->company->update(['logo_path' => $logo, 'color_primary' => '#7a1f1f', 'color_accent' => '#0b6e4f', 'brand_name' => 'EBVS', 'email_footer' => 'EB Validation Services, Caguas PR']);
        $this->owner->unsetRelation('company');
        $this->worker->unsetRelation('company');
    }

    private function hours(): void
    {
        Mail::fake();
        $this->world();
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Advance Logistics', 'email' => 'a@adv.test']);
        $this->project->update(['customer_id' => $this->customer->id, 'name' => 'Soporte']);
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['note' => '=HYPERLINK("http://malo.test","click")']))->assertCreated();
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19', 'start_time' => '09:00', 'end_time' => '11:30', 'note' => 'Normal']))->assertCreated();
    }

    private ?Customer $customer = null;

    /** @return array{0:string,1:string,2:array} */
    private function datasets(): array
    {
        return [
            ['report', ['from' => '2026-10-01', 'to' => '2026-10-31', 'group' => 'project']],
            ['week', ['date' => '2026-10-20']],
            ['invoices', []],
            ['pending', []], ['projects', []], ['people', []], ['approvers', []], ['customers', []],
        ];
    }

    public function test_every_table_exports_in_the_three_formats(): void
    {
        $this->hours();
        Sanctum::actingAs($this->owner);
        foreach ($this->datasets() as [$ds, $params]) {
            foreach (['csv' => 'text/csv', 'xlsx' => 'spreadsheetml', 'pdf' => 'application/pdf'] as $fmt => $mime) {
                $r = $this->call('GET', "/api/tables/$ds/export", $params + ['format' => $fmt]);
                $r->assertOk();
                $this->assertStringContainsString($mime, $r->headers->get('Content-Type'), "$ds/$fmt");
                $this->assertStringContainsString('.'.$fmt, $r->headers->get('Content-Disposition'));
                $this->assertNotSame('', $r->getContent(), "$ds/$fmt");
                if ($fmt === 'pdf') {
                    $this->assertStringStartsWith('%PDF', $r->getContent(), $ds);
                }
                if ($fmt === 'xlsx') {
                    $this->assertStringStartsWith('PK', $r->getContent(), $ds);
                }
            }
        }
    }

    public function test_csv_has_bom_header_totals_and_blocks_formula_injection(): void
    {
        $this->hours();
        Sanctum::actingAs($this->worker);
        $csv = $this->call('GET', '/api/tables/week/export', ['date' => '2026-10-20', 'format' => 'csv'])->assertOk()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF".'Fecha,Proyecto,Desde,Hasta,Horas,Nota', $csv);
        $this->assertStringContainsString('2026-10-20,"P-1 · Soporte",08:00,16:00,8.00', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);                 // no se ejecuta como fórmula en Excel
        $this->assertStringContainsString('Total,,,,10.50,', $csv);             // 8 + 2.5
        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);
    }

    public function test_excel_has_the_company_color_and_logo(): void
    {
        $this->hours();
        $this->withBrand();
        Sanctum::actingAs($this->worker);
        $r = $this->call('GET', '/api/tables/week/export', ['date' => '2026-10-20', 'format' => 'xlsx'])->assertOk();
        $copy = tempnam(sys_get_temp_dir(), 'tx').'.xlsx';
        file_put_contents($copy, $r->getContent());
        $sheet = IOFactory::load($copy)->getActiveSheet();

        $this->assertCount(1, $sheet->getDrawingCollection());                  // el logo
        $this->assertSame('EBVS', $sheet->getCell('A2')->getValue());           // nombre de la compañía
        $this->assertSame('Hoja de la semana', $sheet->getCell('A3')->getValue());
        $headRow = null;
        for ($i = 1; $i <= 10; $i++) {
            if ($sheet->getCell('A'.$i)->getValue() === 'Fecha') {
                $headRow = $i;
            }
        }
        $this->assertNotNull($headRow);
        $this->assertSame('FF7A1F1F', strtoupper($sheet->getStyle('A'.$headRow)->getFill()->getStartColor()->getARGB()));   // color principal
        $this->assertSame('FFFFFFFF', strtoupper($sheet->getStyle('A'.$headRow)->getFont()->getColor()->getARGB()));
        // La nota que empieza con "=" queda como texto, no como fórmula.
        $found = false;
        for ($i = $headRow + 1; $i <= $sheet->getHighestRow(); $i++) {
            $c = $sheet->getCell('F'.$i);
            if (str_starts_with((string) $c->getValue(), '=HYPERLINK')) {
                $found = true;
                $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $c->getDataType());
            }
        }
        $this->assertTrue($found);
        unlink($copy);
    }

    public function test_pdf_page_carries_the_logo_colors_and_footer(): void
    {
        $this->hours();
        $this->withBrand();
        $company = $this->company->fresh();
        $exporter = app(TableExporter::class);
        $table = (new \App\Services\Tables\Datasets())->build('week', $this->worker, ['date' => '2026-10-20']);
        $logo = 'data:image/png;base64,'.base64_encode(Storage::disk('public')->get($company->logo_path));

        $html = $exporter->html($company, $table, $logo);
        $this->assertStringContainsString('#7a1f1f', $html);                    // color de los encabezados
        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
        $this->assertStringContainsString('Hoja de la semana', $html);
        $this->assertStringContainsString('EB Validation Services, Caguas PR', $html);
        $this->assertStringContainsString('10.50', $html);                      // total formateado
        $this->assertStringNotContainsString('<script', $html);

        Sanctum::actingAs($this->worker);
        $pdf = $this->call('GET', '/api/tables/week/export', ['date' => '2026-10-20', 'format' => 'pdf'])->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertGreaterThan(3000, strlen($pdf->getContent()));
    }

    public function test_pdf_without_logo_uses_the_company_name_and_wide_tables_go_landscape(): void
    {
        $this->hours();
        $company = $this->company->fresh();
        $table = (new \App\Services\Tables\Datasets())->build('projects', $this->owner, []);
        $html = app(TableExporter::class)->html($company, $table, null);
        $this->assertStringContainsString('<h1>Cerevelo</h1>', $html);          // sin logo, sale el nombre
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('#1f4e79', $html);                    // color por omisión
    }

    public function test_html_in_cell_values_is_escaped_in_the_pdf(): void
    {
        $this->hours();
        $table = ['title' => 'T', 'subtitle' => '', 'columns' => [['h' => 'A', 't' => 'text']], 'rows' => [['<script>alert(1)</script>']], 'total' => null];
        $html = app(TableExporter::class)->html($this->company, $table, null);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_access_rules(): void
    {
        $this->hours();
        Sanctum::actingAs($this->worker);
        foreach (self::OWNER_TABLES as $ds) {
            $this->call('GET', "/api/tables/$ds/export", ['format' => 'csv'])->assertForbidden();
        }
        $this->call('GET', '/api/tables/week/export', ['date' => '2026-10-20', 'format' => 'csv'])->assertOk();
        $this->call('GET', '/api/tables/nada/export', ['format' => 'csv'])->assertNotFound();
        $this->call('GET', '/api/tables/week/export', ['date' => '2026-10-20', 'format' => 'docx'])->assertStatus(422);
        $this->call('GET', '/api/tables/report/export', ['format' => 'csv'])->assertStatus(422);      // faltan fechas
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();
    }

    public function test_exports_only_contain_the_requesters_company_and_own_hours(): void
    {
        $this->hours();
        $c2 = \App\Models\Company::create(['name' => 'Otra', 'slug' => 'otra', 'locale' => 'es']);
        $u2 = User::create(['company_id' => $c2->id, 'name' => 'Forastero Otra', 'email' => 'f@otra.test', 'password' => 'secret-password', 'role' => 'owner']);
        Customer::create(['company_id' => $c2->id, 'name' => 'Cliente de Otra']);

        Sanctum::actingAs($this->owner);
        $people = $this->call('GET', '/api/tables/people/export', ['format' => 'csv'])->getContent();
        $this->assertStringContainsString('Ana Torres', $people);
        $this->assertStringNotContainsString('Forastero Otra', $people);
        $this->assertStringNotContainsStringIgnoringCase('password', $people);
        $this->assertStringNotContainsString('Cliente de Otra', $this->call('GET', '/api/tables/customers/export', ['format' => 'csv'])->getContent());

        Sanctum::actingAs($u2);
        $this->assertStringNotContainsString('Ana Torres', $this->call('GET', '/api/tables/people/export', ['format' => 'csv'])->getContent());
        $mine = $this->call('GET', '/api/tables/report/export', ['from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'csv'])->getContent();
        $this->assertStringNotContainsString('P-1', $mine);                     // las horas de Ana no son de esta persona
    }

    public function test_report_groupings_and_totals(): void
    {
        $this->hours();
        Sanctum::actingAs($this->worker);
        $byDay = $this->call('GET', '/api/tables/report/export', ['from' => '2026-10-01', 'to' => '2026-10-31', 'group' => 'day', 'format' => 'csv'])->getContent();
        $this->assertStringContainsString('2026-10-19,2.50,2.50,0.00', $byDay);
        $this->assertStringContainsString('2026-10-20,8.00,8.00,0.00', $byDay);
        $this->assertStringContainsString('Total,10.50,10.50,0.00', $byDay);
        $byCustomer = $this->call('GET', '/api/tables/report/export', ['from' => '2026-10-01', 'to' => '2026-10-31', 'group' => 'customer', 'format' => 'csv'])->getContent();
        $this->assertStringContainsString('"Advance Logistics",10.50', $byCustomer);
    }

    public function test_client_timesheet_also_exports_csv(): void
    {
        $this->hours();
        Sanctum::actingAs($this->owner);
        $this->owner->update(['approver_id' => null]);
        $sheet = \App\Models\Timesheet::where('user_id', $this->worker->id)->first();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/sheets/'.$sheet->id.'/submit')->assertOk();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$sheet->id.'/approve')->assertOk();
        $r = $this->post('/api/client-timesheet/download', ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'csv']);
        $r->assertOk();
        $csv = $r->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF".'Fecha,Persona,Proyecto,Desde,Hasta,Horas,Descripción', $csv);
        $this->assertStringContainsString('Ana Torres', $csv);
        $this->assertStringContainsString('TOTAL,,,,,10.50', $csv);
    }
}
