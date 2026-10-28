<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** La tabla "Hojas de tiempo": una fila por día y línea, con la nota, en CSV, Excel y PDF. */
class SheetsExportTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function entry(string $day, float $h, ?string $note): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', ['work_date' => $day, 'project_id' => $this->project->id, 'start_time' => '08:00', 'end_time' => sprintf('%02d:%02d', 8 + intdiv((int) ($h * 60), 60), (int) ($h * 60) % 60), 'note' => $note])->assertStatus(201);
    }

    private function export(string $from, string $to, string $format = 'csv')
    {
        return $this->postJson('/api/tables/sheets/export', ['from' => $from, 'to' => $to, 'format' => $format]);
    }

    public function test_the_owner_exports_every_line_with_its_note_and_the_worker_only_hers(): void
    {
        $this->world();
        $this->entry('2026-10-19', 8, 'Revisé el plano, sin cambios');
        $this->entry('2026-10-20', 4, null);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner);
        $r = $this->export('2026-10-19', '2026-10-25')->assertOk();
        $csv = $r->getContent();
        $this->assertStringContainsString('Revisé el plano, sin cambios', $csv);
        $this->assertStringContainsString('Ana Torres', $csv);
        $this->assertStringContainsString('Nota', $csv);
        $this->assertSame('2', $r->headers->get('X-Export-Rows'));
        foreach (['xlsx', 'pdf'] as $fmt) {
            $this->export('2026-10-19', '2026-10-25', $fmt)->assertOk();
        }
        $this->export('2026-11-02', '2026-11-08')->assertOk()->assertHeader('X-Export-Rows', '0');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->worker);
        $this->export('2026-10-19', '2026-10-25')->assertOk()->assertHeader('X-Export-Rows', '2');
    }

    public function test_the_pdf_carries_every_detail_but_csv_and_excel_keep_their_columns(): void
    {
        $this->world();
        $this->entry('2026-10-19', 8, 'Revisé el plano, sin cambios');
        $ds = app(\App\Services\Tables\Datasets::class);
        $q = ['from' => '2026-10-19', 'to' => '2026-10-25'];

        $pdf = $ds->build('sheets', $this->owner, $q, 'pdf');
        $csv = $ds->build('sheets', $this->owner, $q, 'csv');
        $this->assertContains('Tarea', array_column($pdf['columns'], 'h'));
        $this->assertNotContains('Tarea', array_column($csv['columns'], 'h'));
        $this->assertSame(count($pdf['columns']), count($pdf['rows'][0]));
        $this->assertSame(count($pdf['columns']), count($pdf['total']));
        $this->assertContains('Revisé el plano, sin cambios', $pdf['rows'][0]);

        // La hoja de la semana en PDF: una fila por entrada con todo el detalle; en CSV sigue la cuadrícula de la pantalla.
        $week = $ds->build('week', $this->worker, ['date' => '2026-10-20'], 'pdf');
        $this->assertSame(['Fecha', 'Proyecto', 'Servicio', 'Tarea', 'Desde', 'Hasta', 'Descanso', 'Horas', 'Nota'], array_column($week['columns'], 'h'));
        $this->assertSame('Revisé el plano, sin cambios', $week['rows'][0][8]);
        $grid = $ds->build('week', $this->worker, ['date' => '2026-10-20'], 'csv');
        $this->assertNotContains('Tarea', array_column($grid['columns'], 'h'));
    }
}
