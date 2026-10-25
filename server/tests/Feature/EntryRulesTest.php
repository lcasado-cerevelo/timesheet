<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

class EntryRulesTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    public function test_from_to_user_must_give_times_and_hours_are_computed(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);

        $this->postJson('/api/entries', $this->entry())->assertCreated()->assertJsonPath('hours', 8);
        $this->postJson('/api/entries', ['project_id' => $this->project->id, 'work_date' => '2026-10-19', 'hours' => 8])
            ->assertStatus(422)->assertJsonValidationErrors('start_time');
    }

    public function test_full_day_user_enters_a_total_and_cannot_send_times(): void
    {
        $this->world(['capture_mode' => 'full_day']);
        Sanctum::actingAs($this->worker);

        $this->postJson('/api/entries', ['project_id' => $this->project->id, 'work_date' => '2026-10-20', 'hours' => 8])
            ->assertCreated()->assertJsonPath('start_time', null);
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19']))
            ->assertStatus(422)->assertJsonValidationErrors('start_time');
    }

    public function test_project_that_requires_times_is_refused_to_a_full_day_user(): void
    {
        $this->world(['capture_mode' => 'full_day']);
        $this->project->update(['invoice_detail' => 'with_times']);
        Sanctum::actingAs($this->worker);

        $this->postJson('/api/entries', ['project_id' => $this->project->id, 'work_date' => '2026-10-20', 'hours' => 8])
            ->assertStatus(422)->assertJsonValidationErrors('project_id');
    }

    public function test_user_cannot_change_their_own_capture_mode(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);
        $this->putJson('/api/users/'.$this->worker->id, ['name' => 'x', 'email' => 'x@x.test', 'worker_type' => 'consultant',
            'capture_mode' => 'full_day'])->assertForbidden();
    }

    public function test_owner_sets_capture_mode_per_user(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/users/'.$this->worker->id, ['name' => 'Ana Torres', 'email' => 'ana@cerevelo.test',
            'worker_type' => 'consultant', 'capture_mode' => 'full_day'])->assertOk()->assertJsonPath('capture_mode', 'full_day');
    }

    public function test_only_assigned_projects_can_be_used(): void
    {
        $this->world();
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Otro']);
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['project_id' => $other->id]))->assertStatus(422)
            ->assertJsonValidationErrors('project_id');
    }

    public function test_only_the_owner_manages_projects(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/projects', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/projects', ['code' => 'X', 'name' => 'X', 'user_ids' => [$this->worker->id]])->assertCreated();
    }

    public function test_future_dates_overlaps_and_daily_maximum_are_rejected(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);

        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-22']))->assertStatus(422);
        $this->postJson('/api/entries', $this->entry())->assertCreated();
        $this->postJson('/api/entries', $this->entry(['start_time' => '15:00', 'end_time' => '17:00']))
            ->assertStatus(422)->assertJsonValidationErrors('start_time');
        $this->company->update(['rules' => ['max_day_hours' => 10]]);
        $this->worker->unsetRelation('company');
        $this->postJson('/api/entries', $this->entry(['start_time' => '16:00', 'end_time' => '23:59']))
            ->assertStatus(422)->assertJsonValidationErrors('hours');
    }

    public function test_clock_starts_and_stops_and_blocks_a_second_one(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);

        $this->postJson('/api/clock/start', ['project_id' => $this->project->id])->assertCreated()->assertJsonPath('running', true);
        $this->postJson('/api/clock/start', ['project_id' => $this->project->id])->assertStatus(422);
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-21 12:30:00', 'America/Puerto_Rico'));
        $this->postJson('/api/clock/stop')->assertOk()->assertJsonPath('running', false)->assertJsonPath('hours', 2.5);
    }

    public function test_hours_are_rounded_to_the_increment(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['start_time' => '08:00', 'end_time' => '08:20']))
            ->assertCreated()->assertJsonPath('hours', 0.25);
    }

    public function test_weeks_too_far_back_are_refused(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-06-01']))->assertStatus(422)
            ->assertJsonValidationErrors('work_date');
    }

    public function test_company_isolation_for_entries_and_projects(): void
    {
        $this->world();
        $this->postJson('/api/login', [])->assertStatus(422);
        $c2 = \App\Models\Company::create(['name' => 'Otra', 'slug' => 'otra', 'locale' => 'es']);
        $u2 = \App\Models\User::create(['company_id' => $c2->id, 'name' => 'Otro', 'email' => 'o@o.test', 'password' => 'secret-password', 'role' => 'owner']);
        $e = TimeEntry::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'project_id' => $this->project->id,
            'timesheet_id' => app(\App\Services\SheetService::class)->forWeek($this->worker, '2026-10-20')->id,
            'work_date' => '2026-10-20', 'hours' => 1]);
        Sanctum::actingAs($u2);
        $this->deleteJson('/api/entries/'.$e->id)->assertForbidden();
        $this->putJson('/api/projects/'.$this->project->id, ['code' => 'P-1', 'name' => 'hack'])->assertNotFound();
        $this->assertSame([], $this->getJson('/api/projects')->json());
    }
}
