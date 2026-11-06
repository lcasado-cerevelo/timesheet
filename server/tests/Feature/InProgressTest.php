<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * El dueño y la oficina ven las horas que la gente va trabajando y todavía no ha enviado (solo lectura), y los filtros de cliente tienen la
 * opción "sin cliente" para los proyectos internos.
 */
class InProgressTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private Project $internal;

    private function setUpHours(): void
    {
        $this->world();
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Cliente SA']);
        $this->project->update(['customer_id' => $customer->id]);
        $this->internal = Project::create(['company_id' => $this->company->id, 'code' => 'CEP', 'name' => 'Cepas', 'billable' => false, 'bill_rate' => 0]);
        $this->worker->projects()->attach($this->internal->id);

        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry())->assertCreated();                                   // proyecto con cliente
        $this->postJson('/api/entries', $this->entry(['project_id' => $this->internal->id, 'start_time' => '16:00', 'end_time' => '18:00']))->assertCreated();
    }

    private function asOwner()
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner);
    }

    public function test_the_owner_sees_the_hours_that_are_not_sent_yet(): void
    {
        $this->setUpHours();
        $this->asOwner();
        $r = $this->getJson('/api/in-progress')->assertOk();

        $this->assertSame(1, $r->json('people'));
        $this->assertEquals(10.0, (float) $r->json('hours'));          // 8 h + 2 h
        $this->assertSame('Ana Torres', $r->json('sheets.0.user.name'));
        $this->assertCount(2, $r->json('sheets.0.lines'));
    }

    public function test_a_sent_sheet_is_not_in_progress_any_more(): void
    {
        $this->setUpHours();
        $sheet = Timesheet::where('user_id', $this->worker->id)->first();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/sheets/'.$sheet->id.'/submit')->assertOk();

        $this->asOwner();
        $this->getJson('/api/in-progress')->assertOk()->assertJsonPath('people', 0);
    }

    public function test_the_no_client_option_brings_only_the_internal_projects(): void
    {
        $this->setUpHours();
        $this->asOwner();
        $r = $this->getJson('/api/in-progress?customer_id=none')->assertOk();
        $this->assertEquals(2.0, (float) $r->json('hours'));
        $this->assertSame('CEP · Cepas', $r->json('sheets.0.lines.0.project'));

        $with = $this->getJson('/api/in-progress?customer_id='.$this->project->customer_id)->assertOk();
        $this->assertEquals(8.0, (float) $with->json('hours'));
    }

    public function test_a_consultant_cannot_see_other_peoples_hours_in_progress(): void
    {
        $this->setUpHours();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->worker);
        $this->getJson('/api/in-progress')->assertForbidden();
    }

    public function test_the_projects_table_filters_internal_projects_with_the_no_client_option(): void
    {
        $this->setUpHours();
        $this->asOwner();
        $r = $this->call('GET', '/api/tables/projects/export', ['format' => 'xlsx', 'customer_id' => 'none']);
        $r->assertOk();
        $copy = tempnam(sys_get_temp_dir(), 'nc').'.xlsx';
        file_put_contents($copy, $r->getContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($copy)->getActiveSheet();
        $text = '';
        for ($i = 1; $i <= 12; $i++) {
            $text .= ' '.$sheet->getCell('A'.$i)->getValue();
        }
        unlink($copy);
        $this->assertStringContainsString('CEP', $text);        // el proyecto interno sale
        $this->assertStringNotContainsString('P-1', $text);     // el que tiene cliente, no
        $this->assertStringContainsString('sin cliente (interno)', $text);
    }
}
