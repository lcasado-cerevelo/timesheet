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
        // Abrir y cerrar una tarea no se hace editándola: se completa o se reabre (con firma).
        $this->putJson('/api/tasks/'.$t['id'], ['active' => false])->assertOk()->assertJsonPath('active', true);
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

    public function test_a_task_has_an_optional_budget_and_the_owner_sees_the_hours_used_against_it(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $t = $this->postJson('/api/projects/'.$this->project->id.'/tasks', ['name' => 'Diseño', 'budget_hours' => 20])->assertCreated()
            ->assertJsonPath('budget_hours', 20)->assertJsonPath('used_hours', 0)->json();
        $this->postJson('/api/projects/'.$this->project->id.'/tasks', ['name' => 'Pruebas'])->assertCreated()->assertJsonPath('budget_hours', null);
        $this->postJson('/api/projects/'.$this->project->id.'/tasks', ['name' => 'Mala', 'budget_hours' => -1])->assertStatus(422)->assertJsonValidationErrors('budget_hours');

        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['task_id' => $t['id']]))->assertCreated();   // 8 h (08:00-16:00)

        Sanctum::actingAs($this->owner);
        $tasks = collect($this->getJson('/api/projects')->json('0.tasks'))->keyBy('name');
        $this->assertEquals(8, $tasks['Diseño']['used_hours']);
        $this->assertEquals(20, $tasks['Diseño']['budget_hours']);
        $this->assertEquals(0, $tasks['Pruebas']['used_hours']);
        $this->putJson('/api/tasks/'.$t['id'], ['budget_hours' => null])->assertOk()->assertJsonPath('budget_hours', null)->assertJsonPath('used_hours', 8);

        // Quien registra horas no ve presupuesto ni horas usadas.
        Sanctum::actingAs($this->worker);
        $this->getJson('/api/projects')->assertJsonMissingPath('0.tasks.0.budget_hours')->assertJsonMissingPath('0.tasks.0.used_hours');
    }

    public function test_a_task_without_hours_can_be_deleted_but_one_with_hours_can_only_be_completed(): void
    {
        $this->world();
        $mistake = $this->task('Por error');
        $used = $this->task('Diseño');
        Sanctum::actingAs($this->worker);
        $this->deleteJson('/api/tasks/'.$mistake->id)->assertForbidden();
        $this->postJson('/api/entries', $this->entry(['task_id' => $used->id]))->assertCreated();

        Sanctum::actingAs($this->owner);
        $tasks = collect($this->getJson('/api/projects')->json('0.tasks'))->keyBy('name');
        $this->assertTrue($tasks['Por error']['can_delete']);
        $this->assertFalse($tasks['Diseño']['can_delete']);

        $this->deleteJson('/api/tasks/'.$used->id)->assertStatus(422);
        $this->assertDatabaseHas('tasks', ['id' => $used->id]);
        $this->deleteJson('/api/tasks/'.$mistake->id)->assertOk();
        $this->assertDatabaseMissing('tasks', ['id' => $mistake->id]);
    }

    /** Una persona (no dueña) que supervisa los servicios del proyecto: aprobador interno. */
    private function supervisor(Project $p, string $email = 'sara@cerevelo.test'): User
    {
        $u = User::create(['company_id' => $this->company->id, 'name' => 'Sara Díaz', 'email' => $email, 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to', 'approver_id' => $this->approver->id]);
        foreach ($p->services()->get() as $svc) {
            $svc->update(['approval_mode' => 'internal']);
            $svc->internalApprovers()->attach($u->id);
        }
        return $u;
    }

    public function test_completing_a_task_needs_the_password_records_a_signature_and_defaults_to_the_last_hours_date(): void
    {
        $this->world();
        $t = $this->task('Diseño');
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['task_id' => $t->id, 'work_date' => '2026-10-19']))->assertCreated();
        $this->postJson('/api/entries', $this->entry(['task_id' => $t->id, 'work_date' => '2026-10-20']))->assertCreated();

        Sanctum::actingAs($this->owner);
        $info = $this->getJson('/api/tasks/'.$t->id.'/completion')->assertOk()
            ->assertJsonPath('default_completed_on', '2026-10-20')->assertJsonPath('last_work_date', '2026-10-20')->json();
        $this->assertEquals(16, $info['total_hours']);
        $this->assertEquals(16, $info['pending_hours']);   // todavía no hay hojas aprobadas

        $this->postJson('/api/tasks/'.$t->id.'/complete')->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->postJson('/api/tasks/'.$t->id.'/complete', ['signature' => 'mala-contraseña'])->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->assertTrue($t->fresh()->active);
        $this->assertSame(0, \App\Models\Signature::count());

        $this->postJson('/api/tasks/'.$t->id.'/complete', ['signature' => 'secret-password'])->assertOk()
            ->assertJsonPath('active', false)->assertJsonPath('completed_on', '2026-10-20')->assertJsonPath('completed_by', 'Luis');
        $sig = \App\Models\Signature::firstOrFail();
        $this->assertSame([$this->owner->id, 'task.complete', 'task', $t->id], [$sig->user_id, $sig->action, $sig->subject_type, $sig->subject_id]);
        $this->assertSame(64, strlen($sig->fingerprint));
        $this->postJson('/api/tasks/'.$t->id.'/complete', ['signature' => 'secret-password'])->assertStatus(422);   // ya estaba completada
    }

    public function test_the_completion_date_can_be_chosen_but_not_in_the_future(): void
    {
        $this->world();
        $t = $this->task('Diseño');
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/tasks/'.$t->id.'/complete', ['signature' => 'secret-password', 'completed_on' => '2026-10-22'])->assertStatus(422)->assertJsonValidationErrors('completed_on');
        $this->postJson('/api/tasks/'.$t->id.'/complete', ['signature' => 'secret-password', 'completed_on' => '2026-10-15'])->assertOk()->assertJsonPath('completed_on', '2026-10-15');
        // Sin horas y sin fecha, se completa hoy.
        $u = $this->task('Otra');
        $this->postJson('/api/tasks/'.$u->id.'/complete', ['signature' => 'secret-password'])->assertOk()->assertJsonPath('completed_on', '2026-10-21');
    }

    public function test_only_the_owner_and_the_internal_approvers_of_the_project_complete_tasks(): void
    {
        $this->world();
        $t = $this->task('Diseño');
        $sara = $this->supervisor($this->project);
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Otra']);
        $foreign = $this->task('Ajena', $other);

        Sanctum::actingAs($this->worker);
        $this->postJson('/api/tasks/'.$t->id.'/complete', ['signature' => 'secret-password'])->assertForbidden();
        $this->getJson('/api/tasks')->assertForbidden();

        Sanctum::actingAs($sara);
        $this->postJson('/api/tasks/'.$foreign->id.'/complete', ['signature' => 'secret-password'])->assertForbidden();   // supervisa otro proyecto
        $this->assertSame(['Diseño'], collect($this->getJson('/api/tasks')->assertOk()->json())->pluck('name')->all());
        $this->postJson('/api/tasks/'.$t->id.'/complete', ['signature' => 'secret-password'])->assertOk()->assertJsonPath('completed_by', 'Sara Díaz');

        $account = User::create(['company_id' => $this->company->id, 'name' => 'Jorge', 'email' => 'cuenta@cliente.test', 'password' => 'secret-password',
            'role' => 'approver', 'worker_type' => 'employee', 'capture_mode' => 'full_day', 'approver_profile_id' => $this->approver->id]);
        Sanctum::actingAs($account);
        $this->postJson('/api/tasks/'.$foreign->id.'/complete', ['signature' => 'secret-password'])->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->assertCount(2, $this->getJson('/api/tasks')->assertOk()->json());
        $this->assertCount(1, $this->getJson('/api/tasks?status=completed')->json());
        $this->assertCount(1, $this->getJson('/api/tasks?status=open')->json());
    }

    public function test_only_the_owner_reopens_a_task_and_it_is_signed(): void
    {
        $this->world();
        $t = $this->task('Diseño');
        $sara = $this->supervisor($this->project);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/tasks/'.$t->id.'/reopen', ['signature' => 'secret-password'])->assertStatus(422);   // ya está abierta
        $this->postJson('/api/tasks/'.$t->id.'/complete', ['signature' => 'secret-password', 'completed_on' => '2026-10-15'])->assertOk();

        Sanctum::actingAs($sara);
        $this->postJson('/api/tasks/'.$t->id.'/reopen', ['signature' => 'secret-password'])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/tasks/'.$t->id.'/reopen', ['signature' => 'mala'])->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->postJson('/api/tasks/'.$t->id.'/reopen', ['signature' => 'secret-password'])->assertOk()
            ->assertJsonPath('active', true)->assertJsonPath('completed_on', null)->assertJsonPath('completed_by', null);
        $this->assertSame(['task.complete', 'task.reopen'], \App\Models\Signature::orderBy('id')->pluck('action')->all());
    }

    public function test_the_signature_is_locked_after_too_many_wrong_passwords(): void
    {
        $this->world();
        $t = $this->task('Diseño');
        Sanctum::actingAs($this->owner);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/tasks/'.$t->id.'/complete', ['signature' => 'mala'])->assertStatus(422);
        }
        // Ya bloqueada: ni siquiera la contraseña correcta firma.
        $this->postJson('/api/tasks/'.$t->id.'/complete', ['signature' => 'secret-password'])->assertStatus(429);
        $this->assertTrue($t->fresh()->active);
    }

    public function test_a_task_has_an_estimated_end_a_savings_goal_and_a_commission_flag_that_needs_a_budget(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $t = $this->postJson('/api/projects/'.$this->project->id.'/tasks', ['name' => 'Diseño', 'budget_hours' => 20, 'estimated_end' => '2026-11-05',
            'savings_goal_pct' => 20, 'commissionable' => true])->assertCreated()
            ->assertJsonPath('estimated_end', '2026-11-05')->assertJsonPath('savings_goal_pct', 20)->assertJsonPath('commissionable', true)->json();
        // Sin presupuesto no se puede marcar que aplica comisión.
        $this->postJson('/api/projects/'.$this->project->id.'/tasks', ['name' => 'Sin presupuesto', 'commissionable' => true])
            ->assertStatus(422)->assertJsonValidationErrors('budget_hours');
        $this->putJson('/api/tasks/'.$t['id'], ['budget_hours' => null])->assertStatus(422)->assertJsonValidationErrors('budget_hours');
        $this->putJson('/api/tasks/'.$t['id'], ['estimated_end' => '2026-11-12'])->assertOk()->assertJsonPath('estimated_end', '2026-11-12');   // la fecha se puede cambiar
        $this->putJson('/api/tasks/'.$t['id'], ['savings_goal_pct' => 120])->assertStatus(422)->assertJsonValidationErrors('savings_goal_pct');
        $this->putJson('/api/tasks/'.$t['id'], ['commissionable' => false, 'budget_hours' => null])->assertOk()->assertJsonPath('budget_hours', null);

        // Los demás no ven estos datos.
        Sanctum::actingAs($this->worker);
        $this->getJson('/api/projects')->assertJsonMissingPath('0.tasks.0.estimated_end')->assertJsonMissingPath('0.tasks.0.commissionable');
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
