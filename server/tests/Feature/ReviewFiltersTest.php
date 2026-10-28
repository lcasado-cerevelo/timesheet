<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** La lista de aprobaciones se filtra por proyecto, servicio y tarea (varios a la vez), con cualquier estado. */
class ReviewFiltersTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    public function test_the_review_list_filters_by_project_service_and_task_and_offers_the_options(): void
    {
        Mail::fake();
        $this->world();
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner);
        $all = $this->getJson('/api/review/sheets?status=pending')->assertOk();
        $all->assertJsonCount(1, 'sheets');
        $this->assertSame([$this->project->id], array_column($all->json('options.projects'), 'id'));
        $this->assertContains($e['service_id'], array_column($all->json('options.services'), 'id'));

        $this->getJson('/api/review/sheets?status=pending&project_ids='.$this->project->id)->assertOk()->assertJsonCount(1, 'sheets');
        $this->getJson('/api/review/sheets?status=pending&project_ids=999999')->assertOk()->assertJsonCount(0, 'sheets')->assertJsonPath('counts.pending', 0);
        $this->getJson('/api/review/sheets?status=pending&service_ids='.$e['service_id'])->assertOk()->assertJsonCount(1, 'sheets');
        $this->getJson('/api/review/sheets?status=pending&service_ids='.$e['service_id'].'&task_ids=999999')->assertOk()->assertJsonCount(0, 'sheets');
        // Varias opciones del mismo filtro: la hoja entra si coincide con alguna.
        $this->getJson('/api/review/sheets?status=all&project_ids=999999,'.$this->project->id)->assertOk()->assertJsonCount(1, 'sheets');
    }
}
