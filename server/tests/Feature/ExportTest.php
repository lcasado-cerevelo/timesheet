<?php

namespace Tests\Feature;

use App\Models\Company;
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

class ExportTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    /** Copia de LectorEncabezados.Normalizar de Consultorio 1500 (sin acentos, minúsculas, símbolos como espacio). */
    private function normalize(string $t): string
    {
        $t = \Normalizer::normalize($t, \Normalizer::FORM_D);
        $t = preg_replace('/\p{Mn}/u', '', $t);
        $t = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $t);
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $t)));
    }

    private function approvedWeek(): void
    {
        Mail::fake();
        $this->world();
        $this->project->update(['code' => 'PAC-1', 'name' => 'Maria Lopez', 'bill_rate' => 100,
            'meta' => ['plan_medico' => 'Triple S', 'numero_contrato' => 'C-123']]);
        $priv = Project::create(['company_id' => $this->company->id, 'code' => 'PAC-2', 'name' => 'Juan Perez', 'bill_rate' => 80,
            'meta' => ['plan_medico' => 'Privado', 'numero_contrato' => '']]);
        $this->worker->projects()->attach($priv->id);

        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['start_time' => '08:00', 'end_time' => '09:00', 'note' => 'Sesión 1']))->assertCreated();
        $this->postJson('/api/entries', $this->entry(['start_time' => '10:00', 'end_time' => '11:30', 'note' => 'Sesión 2']))->assertCreated();
        $r = $this->postJson('/api/entries', $this->entry(['project_id' => $priv->id, 'work_date' => '2026-10-19']))->assertCreated()->json();
        $sheet = Timesheet::find($r['timesheet_id']);
        $this->postJson('/api/sheets/'.$sheet->id.'/submit')->assertOk();
        $this->owner->approver_id = null;
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$sheet->id.'/approve')->assertOk();
    }

    public function test_consultorio_1500_template_matches_what_its_importer_reads(): void
    {
        $this->approvedWeek();
        Sanctum::actingAs($this->owner);
        $tpl = collect($this->getJson('/api/export-templates')->assertOk()->json())->firstWhere('slug', 'consultorio-1500');

        $res = $this->postJson('/api/export', ['template_id' => $tpl['id'], 'from' => '2026-10-19', 'to' => '2026-10-25'])->assertOk();
        $file = $res->baseResponse->getFile()->getPathname();
        $copy = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        copy($file, $copy);

        $book = IOFactory::load($copy);
        $this->assertSame(['Atendidos 2026'], $book->getSheetNames());   // el importador solo lee "Atendidos <año>"
        $sheet = $book->getActiveSheet();

        // Encabezados: cada campo del importador debe encontrar su columna por contenido normalizado.
        $heads = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $c) {
                $heads[$c->getColumn()] = $this->normalize((string) $c->getValue());
            }
        }
        $find = fn (callable $p) => array_search(true, array_map($p, $heads), true);
        $has = fn (string ...$frag) => fn ($t) => collect($frag)->every(fn ($f) => str_contains($t, $f));
        $this->assertNotFalse($find($has('asegurado')), 'columna del paciente');
        $this->assertNotFalse($find($has('fecha')), 'columna de fecha');
        $this->assertNotFalse($find($has('contrato')));
        $this->assertNotFalse($find($has('plan', 'medico')));
        $this->assertNotFalse($find($has('facturada', 'con plan')));
        $this->assertNotFalse($find($has('facturada', 'sin plan')));
        $this->assertNotFalse($find(fn ($t) => $t === 'descripcion'));
        // No debe colarse una columna que el importador tome por "pagado por el plan" o "copago".
        $this->assertFalse($find(fn ($t) => str_contains($t, 'pagado') && str_contains($t, 'plan')));
        $this->assertFalse($find(fn ($t) => $t === 'copago'));
        // El nombre del paciente se busca primero por "asegurado"; ninguna otra columna lo contiene.
        $this->assertCount(1, array_filter($heads, fn ($t) => str_contains($t, 'asegurado')));

        // Datos: una fila por paciente y día (Maria 2026-10-20 suma 1 h + 1.5 h).
        $this->assertSame(2, $sheet->getHighestRow() - 1);
        $rows = [];
        for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
            $rows[$sheet->getCell('A'.$r)->getValue()] = [
                'contrato' => $sheet->getCell('B'.$r)->getValue(), 'plan' => $sheet->getCell('C'.$r)->getValue(),
                'con' => $sheet->getCell('D'.$r)->getValue(), 'sin' => $sheet->getCell('E'.$r)->getValue(),
                'fecha' => $sheet->getCell('F'.$r)->getValue(), 'fecha_fmt' => $sheet->getStyle('F'.$r)->getNumberFormat()->getFormatCode(), 'desc' => $sheet->getCell('G'.$r)->getValue(),
            ];
        }
        $this->assertEquals(250, $rows['Maria Lopez']['con']);          // 2.5 h x 100
        $this->assertNull($rows['Maria Lopez']['sin']);
        $this->assertSame('C-123', $rows['Maria Lopez']['contrato']);
        $this->assertSame('Sesión 1; Sesión 2', $rows['Maria Lopez']['desc']);
        $this->assertEquals(640, $rows['Juan Perez']['sin']);           // 8 h x 80, plan privado
        $this->assertNull($rows['Juan Perez']['con']);
        // La fecha es una fecha de Excel real, no texto.
        $this->assertSame('yyyy-mm-dd', $rows['Maria Lopez']['fecha_fmt']);
        $this->assertSame('2026-10-20', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($rows['Maria Lopez']['fecha'])->format('Y-m-d'));
        unlink($copy);
    }

    public function test_only_approved_hours_are_exported_and_the_log_is_kept(): void
    {
        $this->approvedWeek();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-13']))->assertCreated();   // semana sin aprobar
        Sanctum::actingAs($this->owner);
        $tpl = collect($this->getJson('/api/export-templates')->json())->firstWhere('slug', 'horas-por-dia');
        $res = $this->postJson('/api/export', ['template_id' => $tpl['id'], 'from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk();
        $this->assertSame('3', $res->headers->get('X-Export-Rows'));    // 2 de Maria + 1 de Juan; la semana sin aprobar no sale
        $this->assertDatabaseCount('export_logs', 1);
    }

    public function test_custom_template_csv_and_validation(): void
    {
        $this->approvedWeek();
        Sanctum::actingAs($this->owner);
        $bad = $this->postJson('/api/export-templates', ['name' => 'Mala', 'format' => 'csv',
            'config' => ['columns' => [['header' => 'X', 'source' => 'inventado']]]])->assertStatus(422);
        $ok = $this->postJson('/api/export-templates', ['name' => 'Mi CSV', 'format' => 'csv', 'config' => [
            'group_by' => [], 'filters' => ['status' => 'approved'],
            'columns' => [['header' => 'Persona', 'source' => 'worker'], ['header' => 'Horas', 'source' => 'hours'],
                ['header' => 'Tipo', 'source' => 'literal', 'value' => 'REG']],
        ]])->assertCreated()->json();
        $res = $this->postJson('/api/export', ['template_id' => $ok['id'], 'from' => '2026-10-19', 'to' => '2026-10-25'])->assertOk();
        $csv = file_get_contents($res->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Persona,Horas,Tipo', $csv);
        $this->assertStringContainsString('"Ana Torres",1,REG', $csv);
    }

    public function test_system_templates_are_read_only_and_members_only_export_their_own_hours(): void
    {
        $this->approvedWeek();
        $other = User::create(['company_id' => $this->company->id, 'name' => 'Pedro', 'email' => 'p@x.test', 'password' => 'secret-password',
            'capture_mode' => 'from_to', 'approver_id' => $this->approver->id]);
        Sanctum::actingAs($this->owner);
        $tpl = collect($this->getJson('/api/export-templates')->json())->firstWhere('slug', 'consultorio-1500');
        $this->putJson('/api/export-templates/'.$tpl['id'], ['name' => 'x', 'format' => 'csv',
            'config' => ['columns' => [['header' => 'a', 'source' => 'hours']]]])->assertStatus(422);
        $this->deleteJson('/api/export-templates/'.$tpl['id'])->assertStatus(422);

        Sanctum::actingAs($other);
        $res = $this->postJson('/api/export', ['template_id' => $tpl['id'], 'from' => '2026-10-19', 'to' => '2026-10-25'])->assertOk();
        $this->assertSame('0', $res->headers->get('X-Export-Rows'));
        $this->postJson('/api/export-templates', ['name' => 'n', 'format' => 'csv', 'config' => ['columns' => []]])->assertForbidden();
    }

    public function test_consultorio_1500_exports_one_office_and_the_chosen_patients(): void
    {
        $this->approvedWeek();
        $a = Customer::create(['company_id' => $this->company->id, 'name' => 'Oficina A']);
        $b = Customer::create(['company_id' => $this->company->id, 'name' => 'Oficina B']);
        $maria = Project::where('name', 'Maria Lopez')->first();
        $juan = Project::where('name', 'Juan Perez')->first();
        $maria->update(['customer_id' => $a->id]);
        $juan->update(['customer_id' => $b->id]);
        Sanctum::actingAs($this->owner);
        $tpl = collect($this->getJson('/api/export-templates')->json())->firstWhere('slug', 'consultorio-1500');
        $range = ['from' => '2026-10-19', 'to' => '2026-10-25'];

        // Pacientes disponibles de cada oficina, con lo aprobado en el período.
        $pa = $this->getJson('/api/export/patients?'.http_build_query($range + ['customer_id' => $a->id]))->assertOk()->json();
        $this->assertSame(['Maria Lopez'], array_column($pa, 'name'));
        $this->assertEquals(2.5, $pa[0]['hours']);
        $this->assertSame(1, $pa[0]['days']);

        // Una oficina por archivo.
        $res = $this->postJson('/api/export', $range + ['template_id' => $tpl['id'], 'customer_id' => $a->id])->assertOk();
        $this->assertSame('1', $res->headers->get('X-Export-Rows'));
        $this->assertStringContainsString('oficina-a', (string) $res->headers->get('content-disposition'));

        // Escoger pacientes: de una oficina sin pacientes escogidos no sale nada de otra.
        $res = $this->postJson('/api/export', $range + ['template_id' => $tpl['id'], 'customer_id' => $a->id, 'project_ids' => [$juan->id]])->assertOk();
        $this->assertSame('0', $res->headers->get('X-Export-Rows'));
        $res = $this->postJson('/api/export', $range + ['template_id' => $tpl['id'], 'project_ids' => [$maria->id, $juan->id]])->assertOk();
        $this->assertSame('2', $res->headers->get('X-Export-Rows'));

        // Una oficina de otra compañía no existe para nosotros.
        $other = Customer::create(['company_id' => Company::create(['name' => 'Otra', 'slug' => 'otra'])->id, 'name' => 'Ajena']);
        $this->postJson('/api/export', $range + ['template_id' => $tpl['id'], 'customer_id' => $other->id])->assertNotFound();
        $this->getJson('/api/export/patients?'.http_build_query($range + ['customer_id' => $other->id]))->assertNotFound();
    }

    public function test_patient_without_a_medical_plan_goes_to_the_without_plan_column(): void
    {
        $this->approvedWeek();
        Project::where('name', 'Maria Lopez')->update(['meta' => json_encode(['plan_medico' => '', 'numero_contrato' => ''])]);
        Sanctum::actingAs($this->owner);
        $tpl = collect($this->getJson('/api/export-templates')->json())->firstWhere('slug', 'consultorio-1500');
        $res = $this->postJson('/api/export', ['template_id' => $tpl['id'], 'from' => '2026-10-19', 'to' => '2026-10-25'])->assertOk();
        $copy = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        copy($res->baseResponse->getFile()->getPathname(), $copy);
        $sheet = IOFactory::load($copy)->getActiveSheet();
        for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
            if ($sheet->getCell('A'.$r)->getValue() === 'Maria Lopez') {
                $this->assertNull($sheet->getCell('D'.$r)->getValue());   // con plan
                $this->assertEquals(250, $sheet->getCell('E'.$r)->getValue()); // sin plan
            }
        }
        unlink($copy);
    }

    public function test_project_name_dashes_split_patient_plan_and_contract(): void
    {
        $this->approvedWeek();
        Project::where('name', 'Maria Lopez')->update(['name' => 'Ana Ruiz - Triple S - 998-77', 'meta' => json_encode([])]);
        Project::where('name', 'Juan Perez')->update(['name' => 'Luis Gil-Platino', 'meta' => json_encode(['numero_contrato' => 'K-5'])]);
        Sanctum::actingAs($this->owner);
        $tpl = collect($this->getJson('/api/export-templates')->json())->firstWhere('slug', 'consultorio-1500');
        $res = $this->postJson('/api/export', ['template_id' => $tpl['id'], 'from' => '2026-10-19', 'to' => '2026-10-25'])->assertOk();
        $copy = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        copy($res->baseResponse->getFile()->getPathname(), $copy);
        $sheet = IOFactory::load($copy)->getActiveSheet();
        $rows = [];
        for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
            $rows[$sheet->getCell('A'.$r)->getValue()] = [$sheet->getCell('B'.$r)->getValue(), $sheet->getCell('C'.$r)->getValue(), $sheet->getCell('D'.$r)->getValue(), $sheet->getCell('E'.$r)->getValue()];
        }
        // Después del segundo guión todo es el contrato; lo demás sale del nombre.
        $this->assertSame(['998-77', 'Triple S'], [$rows['Ana Ruiz'][0], $rows['Ana Ruiz'][1]]);
        $this->assertEquals(250, $rows['Ana Ruiz'][2]);   // con plan
        // Plan "Platino" del nombre, contrato del campo del proyecto.
        $this->assertSame(['K-5', 'Platino'], [$rows['Luis Gil'][0], $rows['Luis Gil'][1]]);
        unlink($copy);
    }
}
