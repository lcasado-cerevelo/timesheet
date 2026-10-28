<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionResult;
use App\Models\Project;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * Comisiones por productividad. Ejemplo del diseño: tarea de 20 h con meta de ahorro del 20% (meta = 16 h), plazo el 2026-10-23 (hoy es el
 * 2026-10-21). Se termina en 10 h (Jarvis 8 y Ana 2): el equipo cumple y se ahorran 6 h respecto a la META (16 − 10). El bono del equipo
 * es 6 × parte del bono × 5 por hora = 30 para cada persona con comisión (con parte fija del 100%). Jarvis cumple su 80% y gana además
 * 8 × 5 = 40 (70 en total); Ana, con un mínimo del 25%, no lo cumple (2 de 10 = 20%) y solo gana el bono del equipo (30).
 */
class CommissionTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private User $jarvis;
    private User $ana;
    private Task $task;

    private function setUpTask(array $taskOver = []): void
    {
        $this->world();
        $this->jarvis = $this->worker;                       // Ana Torres de world() es la "Ana" del ejemplo; se crea otra persona como Jarvis
        $this->ana = $this->worker;
        $this->jarvis = User::create(['company_id' => $this->company->id, 'name' => 'Jarvis Rodríguez', 'email' => 'jarvis@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'consultant', 'capture_mode' => 'full_day', 'approver_id' => $this->approver->id]);
        $this->jarvis->projects()->attach($this->project->id);
        $this->task = Task::create($taskOver + ['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Diseño',
            'budget_hours' => 20, 'savings_goal_pct' => 20, 'estimated_end' => '2026-10-23', 'commissionable' => true]);
    }

    /**
     * Horas de una persona en la tarea, ya con el estado de su hoja (se crean directo, sin pasar por el envío). Una hoja aprobada no
     * admite entradas nuevas, así que se abre un momento y se deja en el estado pedido. Cada semana es una hoja.
     */
    private function approvedHours(User $u, float $hours, string $date = '2026-10-19', ?Task $task = null, string $status = Timesheet::APPROVED): TimeEntry
    {
        $end = (new \DateTimeImmutable($date))->modify('sunday this week')->format('Y-m-d');
        $sheet = Timesheet::firstOrCreate(['user_id' => $u->id, 'week_ending' => $end, 'part' => null],
            ['company_id' => $this->company->id, 'status' => Timesheet::DRAFT, 'version' => 1]);
        Timesheet::whereKey($sheet->id)->update(['status' => Timesheet::DRAFT]);
        $entry = TimeEntry::create(['company_id' => $this->company->id, 'user_id' => $u->id, 'project_id' => $this->project->id,
            'service_id' => $this->project->services()->first()->id, 'task_id' => ($task ?? $this->task)->id, 'timesheet_id' => $sheet->id,
            'work_date' => $date, 'hours' => $hours, 'billable' => true, 'running' => false]);
        Timesheet::whereKey($sheet->id)->update(['status' => $status]);
        return $entry;
    }

    private function commission(User $u, array $over = []): Commission
    {
        $c = Commission::create($over + ['company_id' => $this->company->id, 'user_id' => $u->id, 'project_id' => $this->project->id,
            'percent_required' => 80, 'extra_per_hour' => 5, 'savings_mode' => 'fixed', 'savings_pct' => 100, 'savings_per_hour' => 5, 'tolerance_hours' => 0]);
        $c->tasks()->sync([$this->task->id]);
        return $c;
    }

    private function complete(array $body = [], ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->owner);
        return $this->postJson('/api/tasks/'.$this->task->id.'/complete', $body + ['signature' => 'secret-password']);
    }

    private function teamHours(float $jarvis = 8, float $ana = 2): void
    {
        $this->approvedHours($this->jarvis, $jarvis, '2026-10-19');
        $this->approvedHours($this->ana, $ana, '2026-10-20');
    }

    // ---------- el cálculo ----------

    public function test_the_example_of_the_design_pays_the_team_bonus_to_both_and_the_commission_only_to_who_did_their_share(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->commission($this->ana, ['percent_required' => 25]);   // Ana tiene su propio mínimo; aquí hace 2 de 10 = 20%
        $this->teamHours();

        $this->complete(['completed_on' => '2026-10-21', 'release' => true])->assertOk();
        $j = CommissionResult::where('user_id', $this->jarvis->id)->firstOrFail();
        $a = CommissionResult::where('user_id', $this->ana->id)->firstOrFail();
        $this->assertEquals(10, $j->total_hours);
        $this->assertEquals(16, $j->goal_hours);
        $this->assertEquals(6, $j->bonus_hours);            // horas ahorradas respecto a la meta: 16 − 10
        $this->assertEquals(8, $j->user_hours);
        $this->assertEquals(80, $j->user_pct);
        $this->assertEquals(40, $j->commission_amount);     // 8 h × 5
        $this->assertEquals(30, $j->savings_amount);        // 6 h × 100% × 5
        $this->assertEquals(70, $j->total);
        $this->assertSame('released', $j->status);
        $this->assertTrue($j->met_goal && $j->met_date && $j->met_share && $j->worked_min);
        // Ana: 2 de 10 = 20% < 25%: no cumple su parte, pero el bono es del equipo.
        $this->assertFalse((bool) $a->met_share);
        $this->assertEquals(0, $a->commission_amount);
        $this->assertEquals(30, $a->savings_amount);
        $this->assertEquals(30, $a->total);
    }

    public function test_the_savings_share_can_be_the_percentage_that_corresponded_to_the_person(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis, ['savings_mode' => 'assigned', 'percent_required' => 80]);
        $this->teamHours();
        $this->complete(['release' => true])->assertOk();
        $j = CommissionResult::where('user_id', $this->jarvis->id)->firstOrFail();
        $this->assertEquals(80, $j->savings_pct);
        $this->assertEquals(24, $j->savings_amount);   // 6 h ahorradas × 80% × 5
    }

    public function test_the_tolerance_forgives_going_over_the_goal_by_a_little(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis, ['tolerance_hours' => 0.5]);
        $this->approvedHours($this->jarvis, 16.5);   // meta 16 h; 16.5 con 0.5 de tolerancia sigue valiendo
        $this->complete(['release' => true])->assertOk();
        $r = CommissionResult::firstOrFail();
        $this->assertTrue($r->met_goal);
        $this->assertEquals(0, $r->bonus_hours);                          // no se ahorró nada respecto a la meta: no hay bono del equipo
        $this->assertEquals(16.5 * 5, (float) $r->total);                 // solo la comisión por hora
    }

    public function test_finishing_exactly_on_the_goal_pays_the_commission_but_no_team_bonus(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->approvedHours($this->jarvis, 16);
        $this->complete(['release' => true])->assertOk();
        $r = CommissionResult::firstOrFail();
        $this->assertTrue($r->met_goal);
        $this->assertEquals(0, $r->bonus_hours);
        $this->assertEquals(0, $r->savings_amount);
        $this->assertEquals(80, $r->commission_amount);                   // 16 h × 5
    }

    public function test_going_over_the_goal_or_the_deadline_pays_nobody(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->approvedHours($this->jarvis, 17);     // 17 > 16: no se cumple la meta
        $r = $this->complete(['completed_on' => '2026-10-20'])->assertOk();
        $this->assertEquals(0, CommissionResult::firstOrFail()->total);
        $this->assertSame('none', CommissionResult::firstOrFail()->status);

        $this->postJson('/api/tasks/'.$this->task->id.'/reopen', ['signature' => 'secret-password'])->assertOk();
        TimeEntry::query()->delete();
        $this->approvedHours($this->jarvis, 14);     // meta cumplida, pero se completa pasado el plazo
        $this->task->update(['estimated_end' => '2026-10-15']);
        $this->complete(['completed_on' => '2026-10-21'])->assertOk();
        $x = CommissionResult::firstOrFail();
        $this->assertFalse((bool) $x->met_date);
        $this->assertEquals(0, $x->total);
        $this->assertSame('none', $x->status);
    }

    public function test_the_team_bonus_needs_at_least_one_hour_on_the_task(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->commission($this->ana);
        $this->approvedHours($this->jarvis, 15);
        $this->approvedHours($this->ana, 0.5);   // menos de 1 hora: no recibe el bono del equipo
        $this->complete(['release' => true])->assertOk();
        $this->assertEquals(0, CommissionResult::where('user_id', $this->ana->id)->first()->total);
        $this->assertGreaterThan(0, (float) CommissionResult::where('user_id', $this->jarvis->id)->first()->total);
    }

    public function test_only_approved_hours_count_and_unapproved_ones_block_completing_with_a_commission(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->approvedHours($this->jarvis, 12);
        $this->approvedHours($this->ana, 2, '2026-10-20', null, Timesheet::SUBMITTED);   // sin aprobar

        $this->complete(['release' => true])->assertStatus(422)->assertJsonPath('code', 'pending_hours');
        $this->assertTrue($this->task->fresh()->active);
        $this->assertSame(0, CommissionResult::count());

        Timesheet::where('user_id', $this->ana->id)->update(['status' => Timesheet::APPROVED]);
        $this->complete(['release' => true])->assertOk();
        $this->assertEquals(14, CommissionResult::firstOrFail()->total_hours);   // ahora sí cuentan las de Ana
    }

    public function test_the_hours_of_a_person_only_count_in_the_services_of_the_commission(): void
    {
        $this->setUpTask();
        $other = \App\Models\Service::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Otro']);
        $c = $this->commission($this->jarvis);
        $c->services()->sync([$this->project->services()->first()->id]);
        $this->approvedHours($this->jarvis, 8);
        $e = $this->approvedHours($this->jarvis, 4, '2026-10-20');
        TimeEntry::whereKey($e->id)->update(['service_id' => $other->id]);   // la hoja ya está aprobada: se cambia sin pasar por el modelo
        $this->complete(['release' => true])->assertOk();
        $r = CommissionResult::firstOrFail();
        $this->assertEquals(12, $r->total_hours);   // el total de la tarea incluye todos los servicios
        $this->assertEquals(8, $r->user_hours);     // las horas de la persona, solo en los servicios de su comisión
    }

    public function test_a_task_that_does_not_count_for_commission_completes_without_results(): void
    {
        $this->setUpTask(['commissionable' => false]);
        $this->commission($this->jarvis);
        $this->approvedHours($this->jarvis, 10);
        $this->complete()->assertOk();
        $this->assertSame(0, CommissionResult::count());
    }

    // ---------- completar y liberar ----------

    public function test_the_owner_must_decide_about_a_commission_and_can_review_it_instead(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->teamHours();

        $this->complete()->assertStatus(422)->assertJsonPath('code', 'commission_decision_required')
            ->assertJsonPath('commissions.payable', true)->assertJsonPath('commissions.rows.0.total', 70);
        $this->complete(['release' => false])->assertStatus(422)->assertJsonPath('code', 'commission_decision_required');
        $this->assertTrue($this->task->fresh()->active);     // revisarla: la tarea no se completa
        $this->assertSame(0, \App\Models\Signature::count());
        $this->complete(['release' => true])->assertOk();
        $this->assertSame('released', CommissionResult::firstOrFail()->status);
        $this->assertSame($this->owner->id, (int) CommissionResult::firstOrFail()->released_by);
    }

    public function test_an_internal_approver_completes_and_the_commission_waits_for_the_owner_to_release_it(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->teamHours();
        $sara = User::create(['company_id' => $this->company->id, 'name' => 'Sara Díaz', 'email' => 'sara@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to']);
        foreach ($this->project->services()->get() as $svc) {
            $svc->update(['approval_mode' => 'internal']);
            $svc->internalApprovers()->attach($sara->id);
        }

        $this->complete([], $sara)->assertOk();
        $r = CommissionResult::firstOrFail();
        $this->assertSame('pending', $r->status);
        $this->assertNull($r->released_at);

        Sanctum::actingAs($sara);
        $this->postJson('/api/commission-results/'.$r->id.'/release', ['signature' => 'secret-password'])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/commission-results/'.$r->id.'/release', ['signature' => 'mala'])->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->postJson('/api/commission-results/'.$r->id.'/release', ['signature' => 'secret-password'])->assertOk()->assertJsonPath('status', 'released');
        $this->postJson('/api/commission-results/'.$r->id.'/release', ['signature' => 'secret-password'])->assertStatus(422);   // ya liberada
    }

    public function test_the_preview_follows_the_chosen_date(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->teamHours();
        Sanctum::actingAs($this->owner);
        $ok = $this->getJson('/api/tasks/'.$this->task->id.'/completion?completed_on=2026-10-21')->assertOk()->json('commissions');
        $this->task->update(['estimated_end' => '2026-10-15']);   // el dueño cambia la fecha estimada: ahora esa misma fecha de completada llega tarde
        $late = $this->getJson('/api/tasks/'.$this->task->id.'/completion?completed_on=2026-10-21')->assertOk()->json('commissions');
        $this->assertTrue($ok['payable']);
        $this->assertFalse($late['payable']);
        $this->assertFalse($late['rows'][0]['met_date']);
    }

    public function test_reopening_discards_the_commission_unless_it_was_invoiced_or_paid(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->teamHours();
        $this->complete(['release' => true])->assertOk();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/tasks/'.$this->task->id.'/reopen', ['signature' => 'secret-password'])->assertOk();
        $this->assertSame(0, CommissionResult::count());

        $this->complete(['release' => true])->assertOk();
        CommissionResult::query()->update(['status' => 'invoiced']);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/tasks/'.$this->task->id.'/reopen', ['signature' => 'secret-password'])->assertStatus(422);
        $this->assertFalse($this->task->fresh()->active);
    }

    // ---------- ajustar y pagar ----------

    public function test_the_owner_can_adjust_a_commission_with_a_reason_keeping_the_original_calculation(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->teamHours();
        $this->complete(['release' => true])->assertOk();
        $r = CommissionResult::firstOrFail();

        Sanctum::actingAs($this->owner);
        $url = '/api/commission-results/'.$r->id;
        $this->putJson($url, ['adjustment' => -25, 'signature' => 'secret-password'])->assertStatus(422)->assertJsonValidationErrors('adjustment_note');
        $this->putJson($url, ['adjustment' => -200, 'adjustment_note' => 'Excepción', 'signature' => 'secret-password'])->assertStatus(422)->assertJsonValidationErrors('adjustment');
        $this->putJson($url, ['adjustment' => -25, 'adjustment_note' => 'Entregó tarde una parte', 'signature' => 'secret-password'])->assertOk()
            ->assertJsonPath('commission_amount', 40)->assertJsonPath('savings_amount', 30)->assertJsonPath('adjustment', -25)->assertJsonPath('total', 45);
        $this->putJson($url, ['adjustment' => -25, 'adjustment_note' => 'Motivo del ajuste', 'signature' => 'mala'])->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->assertSame('Entregó tarde una parte', $r->fresh()->adjustment_note);
        // Quitar todo lo ganado la deja sin comisión.
        $this->putJson($url, ['adjustment' => -70, 'adjustment_note' => 'No se paga', 'signature' => 'secret-password'])->assertOk()->assertJsonPath('status', 'none')->assertJsonPath('total', 0);
        // Y se puede conceder a mano una que no se ganó: queda por liberar.
        $this->putJson($url, ['adjustment' => 30, 'adjustment_note' => 'Bono acordado', 'signature' => 'secret-password'])->assertOk()
            ->assertJsonPath('status', 'pending')->assertJsonPath('total', 100);
    }

    public function test_only_released_commissions_of_employees_are_marked_as_paid(): void
    {
        $this->setUpTask();
        $this->jarvis->update(['worker_type' => 'employee']);
        $this->commission($this->jarvis);
        $this->teamHours();
        $this->complete(['release' => true])->assertOk();
        $r = CommissionResult::firstOrFail();

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/commission-results/'.$r->id.'/paid', ['signature' => 'secret-password'])->assertOk()->assertJsonPath('status', 'paid');
        $this->assertNotNull($r->fresh()->paid_at);
        $this->postJson('/api/commission-results/'.$r->id.'/paid', ['signature' => 'secret-password'])->assertStatus(422);
        $this->putJson('/api/commission-results/'.$r->id, ['adjustment' => 1, 'adjustment_note' => 'tarde', 'signature' => 'secret-password'])->assertStatus(422);   // ya pagada

        // Un consultor cobra en su factura: no se marca como pagada.
        $this->jarvis->update(['worker_type' => 'consultant']);
        $r->update(['status' => 'released']);
        $this->postJson('/api/commission-results/'.$r->id.'/paid', ['signature' => 'secret-password'])->assertStatus(422);
    }

    // ---------- configuración ----------

    public function test_the_owner_configures_one_commission_per_person_and_project(): void
    {
        $this->setUpTask();
        $service = $this->project->services()->first();
        $body = ['user_id' => $this->jarvis->id, 'project_id' => $this->project->id, 'service_ids' => [$service->id], 'task_ids' => [$this->task->id],
            'percent_required' => 80, 'extra_per_hour' => 5, 'savings_mode' => 'fixed', 'savings_pct' => 100, 'savings_per_hour' => 5, 'tolerance_hours' => 0.5,
            'withhold' => true, 'ivu' => false];

        Sanctum::actingAs($this->worker);
        $this->postJson('/api/commissions', $body)->assertForbidden();
        Sanctum::actingAs($this->owner);
        $c = $this->postJson('/api/commissions', $body)->assertCreated()->assertJsonPath('user.name', 'Jarvis Rodríguez')
            ->assertJsonPath('task_ids.0', $this->task->id)->assertJsonPath('tolerance_hours', 0.5)->json();
        $this->postJson('/api/commissions', $body)->assertStatus(422)->assertJsonValidationErrors('project_id');   // una por proyecto

        // Las tareas deben ser de las que aplican comisión y las del proyecto; los servicios, del proyecto.
        $plain = Task::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Normal']);
        $this->putJson('/api/commissions/'.$c['id'], ['task_ids' => [$plain->id]])->assertStatus(422)->assertJsonValidationErrors('task_ids');
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Otra']);
        $this->putJson('/api/commissions/'.$c['id'], ['service_ids' => [$other->services()->first()->id]])->assertStatus(422)->assertJsonValidationErrors('service_ids');
        $this->putJson('/api/commissions/'.$c['id'], ['percent_required' => 120])->assertStatus(422)->assertJsonValidationErrors('percent_required');
        $this->putJson('/api/commissions/'.$c['id'], ['percent_required' => 75, 'savings_mode' => 'assigned'])->assertOk()
            ->assertJsonPath('percent_required', 75)->assertJsonPath('savings_mode', 'assigned');
        $this->putJson('/api/commissions/'.$c['id'], ['user_id' => $this->ana->id])->assertStatus(422);   // no se cambia de persona

        $this->assertCount(1, $this->getJson('/api/commissions?user_id='.$this->jarvis->id)->json());
        $this->deleteJson('/api/commissions/'.$c['id'])->assertOk();
    }

    public function test_an_external_approver_account_cannot_have_a_commission_and_a_commission_with_results_is_not_deleted(): void
    {
        $this->setUpTask();
        $account = User::create(['company_id' => $this->company->id, 'name' => 'Jorge', 'email' => 'cuenta@cliente.test', 'password' => 'secret-password',
            'role' => 'approver', 'worker_type' => 'employee', 'capture_mode' => 'full_day', 'approver_profile_id' => $this->approver->id]);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/commissions', ['user_id' => $account->id, 'project_id' => $this->project->id, 'percent_required' => 80, 'extra_per_hour' => 1,
            'savings_mode' => 'fixed', 'savings_pct' => 100, 'savings_per_hour' => 1, 'tolerance_hours' => 0])->assertStatus(422)->assertJsonValidationErrors('user_id');

        $c = $this->commission($this->jarvis);
        $this->teamHours();
        $this->complete(['release' => true])->assertOk();
        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/commissions/'.$c->id)->assertStatus(422);
        $this->putJson('/api/commissions/'.$c->id, ['active' => false])->assertOk()->assertJsonPath('active', false);
    }

    public function test_the_owner_lists_the_results_with_filters_and_exports_them(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->commission($this->ana, ['percent_required' => 20]);
        $this->teamHours();
        $this->complete(['release' => true])->assertOk();
        Sanctum::actingAs($this->owner);
        $this->assertCount(2, $this->getJson('/api/commission-results')->assertOk()->json());
        $this->assertCount(2, $this->getJson('/api/commission-results?status=released')->json());
        $this->assertCount(0, $this->getJson('/api/commission-results?status=paid')->json());
        $this->assertCount(1, $this->getJson('/api/commission-results?user_id='.$this->jarvis->id)->json());

        $csv = $this->get('/api/tables/commissions/export?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('Jarvis Rodríguez', $csv);
        $this->assertStringContainsString('70.00', $csv);
        $this->get('/api/tables/commissions/export?format=csv&status=paid')->assertOk();
        Sanctum::actingAs($this->worker);
        $this->get('/api/tables/commissions/export?format=csv')->assertForbidden();
        $this->getJson('/api/commission-results')->assertForbidden();
    }

    // ---------- lo que ve cada persona ----------

    public function test_a_person_sees_only_their_own_commission_with_how_they_are_doing_and_never_the_others_names(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->commission($this->ana, ['percent_required' => 20]);
        $this->approvedHours($this->jarvis, 8, '2026-10-19');
        $this->approvedHours($this->ana, 2, '2026-10-20');
        $this->approvedHours($this->jarvis, 1.5, '2026-10-27', null, Timesheet::SUBMITTED);   // todavía sin aprobar

        $this->assertTrue($this->getJsonAs($this->jarvis, '/api/me')['has_commissions']);
        $list = $this->getJsonAs($this->jarvis, '/api/me/commissions');
        $this->assertCount(1, $list);
        $this->assertEquals(80, $list[0]['terms']['percent_required']);
        $t = $list[0]['tasks'][0];
        $this->assertSame('open', $t['status']);
        $this->assertEquals(10, $t['team']['total_hours']);       // aprobadas de todo el equipo
        $this->assertEquals(16, $t['team']['goal_hours']);
        $this->assertEquals(8, $t['mine']['hours']);
        $this->assertEquals(80, $t['mine']['pct']);
        $this->assertEquals(1.5, $t['mine']['pending_hours']);
        $this->assertTrue($t['conditions']['goal'] && $t['conditions']['share'] && $t['conditions']['min_hours']);
        $this->assertEquals(8 * 5 + 6 * 5, $t['would_earn']['total']);   // 40 de comisión + 6 h ahorradas respecto a la meta × 5
        $json = json_encode($list);
        $this->assertStringNotContainsString('Ana Torres', $json);

        // Sin comisión no ve nada y no tiene la pantalla.
        $this->assertSame([], $this->getJsonAs($this->owner, '/api/me/commissions'));
        $this->assertFalse($this->getJsonAs($this->owner, '/api/me')['has_commissions']);
    }

    public function test_after_completing_the_person_sees_the_result_only_once_it_is_released(): void
    {
        $this->setUpTask();
        $this->commission($this->jarvis);
        $this->teamHours();
        $sara = User::create(['company_id' => $this->company->id, 'name' => 'Sara Díaz', 'email' => 'sara@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to']);
        foreach ($this->project->services()->get() as $svc) {
            $svc->update(['approval_mode' => 'internal']);
            $svc->internalApprovers()->attach($sara->id);
        }
        $this->complete([], $sara)->assertOk();     // queda por liberar

        $t = $this->getJsonAs($this->jarvis, '/api/me/commissions')[0]['tasks'][0];
        $this->assertSame('in_review', $t['status']);
        $this->assertNull($t['earned']);            // sin liberar no se muestran montos

        CommissionResult::query()->update(['status' => 'released']);
        $t = $this->getJsonAs($this->jarvis, '/api/me/commissions')[0]['tasks'][0];
        $this->assertSame('released', $t['status']);
        $this->assertEquals(70, $t['earned']['total']);
        $this->assertEquals(40, $t['earned']['commission']);
    }

    private function getJsonAs(User $u, string $url): array
    {
        Sanctum::actingAs($u);
        return $this->getJson($url)->assertOk()->json();
    }
}
