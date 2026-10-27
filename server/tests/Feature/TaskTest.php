<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * Tareas de un proyecto: un proyecto puede no tener ninguna (y entonces las horas no llevan tarea); si tiene tareas activas, cada entrada
 * debe escoger una. El aprobador las ve junto al proyecto y al servicio.
 */
class TaskTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function task(string $name = 'Diseño', ?Project $p = null, bool $active = true): Task
    {
        $p ??= $this->project;
        return Task::create(['company_id' => $this->company->id, 'project_id' => $p->id, 'name' => $name, 'active' => $active]);
    }

    public function test_only_the_owner_manages_tasks_and_names_are_unique_per_project(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/projects/'.$this->project->id.'/tasks', ['name' => 'Diseño'])->assertForbidden();

        Sanctum::actingAs($this->owner);
        $t = $this->postJson('/api/projects/'.$this->project->id.'/tasks', ['name' => 'Diseño'])->assertCreated()
            ->assertJsonPath('name', 'Diseño')->assertJsonPath('active', true)->json();
        $this->postJson('/api/projects/'.$this->project->id.'/tasks', ['name' => 'Diseño'])->assertStatus(422)->assertJsonValidationErrors('name');
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Otra']);
        $this->postJson('/api/projects/'.$other->id.'/tasks', ['name' => 'Diseño'])->assertCreated();   // el mismo nombre en otro proyecto sí

        $this->putJson('/api/tasks/'.$t['id'], ['name' => 'Diseño gráfico'])->assertOk()->assertJsonPath('name', 'Diseño gráfico');
        $this->putJson('/api/tasks/'.$t['id'], ['active' => false])->assertOk()->assertJsonPath('active', false);
    }

    public function test_a_task_of_another_company_cannot_be_touched(): void
    {
        $this->world();
        $t = $this->task();
        $company2 = \App\Models\Company::create(['name' => 'Otra', 'slug' => 'otra', 'locale' => 'es']);
        $owner2 = User::create(['company_id' => $company2->id, 'name' => 'Dueña', 'email' => 'duena@otra.test', 'password' => 'secret-password', 'role' => 'owner', 'capture_mode' => 'from_to']);
        Sanctum::actingAs($owner2);
        $this->putJson('/api/tasks/'.$t->id, ['name' => 'X'])->assertNotFound();
        $this->postJson('/api/projects/'.$this->project->id.'/tasks', ['name' => 'X'])->assertNotFound();
    }

    public function test_the_project_payload_lists_tasks_and_hides_inactive_ones_from_workers(): void
    {
        $this->world();
        $this->task('Diseño');
        $this->task('Vieja', null, false);

        Sanctum::actingAs($this->owner);
        $this->assertSame(['Diseño', 'Vieja'], collect($this->getJson('/api/projects')->json('0.tasks'))->pluck('name')->sort()->values()->all());
        Sanctum::actingAs($this->worker);
        $this->assertSame(['Diseño'], collect($this->getJson('/api/projects')->json('0.tasks'))->pluck('name')->all());
    }

    public function test_a_project_without_tasks_works_as_before_and_with_tasks_the_entry_must_pick_one(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);
        $this->assertSame([], $this->getJson('/api/projects')->json('0.tasks'));
        $this->postJson('/api/entries', $this->entry())->assertCreated()->assertJsonPath('task_id', null)->assertJsonPath('task', null);

        $t = $this->task('Diseño');   // ahora el proyecto tiene una tarea activa: es obligatoria
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19']))->assertStatus(422)->assertJsonValidationErrors('task_id');
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19', 'task_id' => null]))->assertStatus(422)->assertJsonValidationErrors('task_id');
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19', 'task_id' => $t->id]))->assertCreated();

        // Si todas sus tareas están desactivadas, el proyecto vuelve a no exigir tarea.
        $t->update(['active' => false]);
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-18']))->assertCreated()->assertJsonPath('task', null);
    }

    public function test_an_old_entry_without_task_can_still_be_edited_after_the_project_gets_tasks(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);
        $old = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $t = $this->task('Diseño');

        $this->putJson('/api/entries/'.$old['id'], $this->entry(['note' => 'corregida']))->assertOk()->assertJsonPath('task', null);
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19']))->assertStatus(422)->assertJsonValidationErrors('task_id');
        // Y puede ganar tarea cuando la persona la escoge.
        $this->putJson('/api/entries/'.$old['id'], $this->entry(['task_id' => $t->id]))->assertOk()->assertJsonPath('task.name', 'Diseño');
    }

    public function test_an_entry_can_carry_a_task_of_its_own_project_only(): void
    {
        $this->world();
        $t = $this->task('Diseño');
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Otra']);
        $foreign = $this->task('Ajena', $other);
        Sanctum::actingAs($this->worker);

        $this->postJson('/api/entries', $this->entry(['task_id' => $foreign->id]))->assertStatus(422)->assertJsonValidationErrors('task_id');
        $this->postJson('/api/entries', $this->entry(['task_id' => 99999]))->assertStatus(422)->assertJsonValidationErrors('task_id');
        $e = $this->postJson('/api/entries', $this->entry(['task_id' => $t->id]))->assertCreated()
            ->assertJsonPath('task_id', $t->id)->assertJsonPath('task.name', 'Diseño')->json();

        $week = $this->getJson('/api/entries?from=2026-10-19&to=2026-10-25')->assertOk();
        $this->assertSame('Diseño', $week->json('entries.0.task.name'));
        $this->assertSame($t->id, $e['task_id']);
    }

    public function test_an_inactive_task_cannot_be_used_in_new_entries_but_an_old_entry_keeps_it(): void
    {
        $this->world();
        $t = $this->task('Diseño');
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry(['task_id' => $t->id]))->assertCreated()->json();

        $t->update(['active' => false]);
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19', 'task_id' => $t->id]))->assertStatus(422)->assertJsonValidationErrors('task_id');
        // Editar la nota de la entrada vieja (mandando la misma tarea o sin mandarla) no la pierde.
        $this->putJson('/api/entries/'.$e['id'], $this->entry(['task_id' => $t->id, 'note' => 'a']))->assertOk()->assertJsonPath('task_id', $t->id);
        $this->putJson('/api/entries/'.$e['id'], $this->entry(['note' => 'b']))->assertOk()->assertJsonPath('task_id', $t->id);
    }

    public function test_the_task_can_be_changed_but_not_removed_and_does_not_follow_a_project_change(): void
    {
        $this->world();
        $a = $this->task('Diseño');
        $b = $this->task('Pruebas');
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Otra']);
        $this->worker->projects()->attach($other->id);
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry(['task_id' => $a->id]))->assertCreated()->json();

        $this->putJson('/api/entries/'.$e['id'], $this->entry(['task_id' => $b->id]))->assertOk()->assertJsonPath('task.name', 'Pruebas');
        // Con tareas en el proyecto no se puede quitar la tarea, solo cambiarla.
        $this->putJson('/api/entries/'.$e['id'], $this->entry(['task_id' => null]))->assertStatus(422)->assertJsonValidationErrors('task_id');

        $this->putJson('/api/entries/'.$e['id'], $this->entry(['task_id' => $a->id]))->assertOk();
        $this->putJson('/api/entries/'.$e['id'], $this->entry(['project_id' => $other->id]))->assertOk()->assertJsonPath('task', null);
    }

    public function test_the_clock_can_start_on_a_task_and_keeps_it_when_stopped(): void
    {
        $this->world();
        $t = $this->task('Diseño');
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/clock/start', ['project_id' => $this->project->id, 'task_id' => $t->id, 'at' => '2026-10-21T09:00:00-04:00'])
            ->assertCreated()->assertJsonPath('task.name', 'Diseño');
        $this->postJson('/api/clock/stop', ['at' => '2026-10-21T10:00:00-04:00'])->assertOk()->assertJsonPath('task.name', 'Diseño');
    }

    public function test_the_approver_sees_the_task_on_the_page_and_in_the_account_api(): void
    {
        Mail::fake();
        $this->world();
        $this->clientWithApprover($this->project);
        $t = $this->task('Diseño');
        Sanctum::actingAs($this->worker);
        $id = $this->postJson('/api/entries', $this->entry(['task_id' => $t->id]))->assertCreated()->json('timesheet_id');
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19', 'start_time' => '08:00', 'end_time' => '09:00', 'task_id' => $t->id]))->assertCreated();
        $this->postJson('/api/sheets/'.$id.'/submit')->assertOk();

        $mail = Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.approval-request' && $m->hasTo('jorge@cliente.test'))->last();
        $page = $this->get('/a/'.$this->tokenFromMail($mail->render()))->assertOk()->getContent();
        $this->assertStringContainsString('Diseño', $page);

        $account = User::create(['company_id' => $this->company->id, 'name' => 'Jorge', 'email' => 'cuenta@cliente.test', 'password' => 'secret-password',
            'role' => 'approver', 'worker_type' => 'employee', 'capture_mode' => 'full_day', 'approver_profile_id' => $this->approver->id]);
        Sanctum::actingAs($account);
        $entries = collect($this->getJson('/api/approver/sheets/'.$id)->assertOk()->json('entries'));
        $this->assertSame(['Diseño', 'Diseño'], $entries->pluck('task.name')->all());
    }
}
