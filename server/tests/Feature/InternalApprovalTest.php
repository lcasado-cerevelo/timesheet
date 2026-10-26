<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\SheetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

class InternalApprovalTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private User $sara;
    private User $tomas;
    private Project $second;

    private function supervisor(string $name, string $email): User
    {
        return User::create(['company_id' => $this->company->id, 'name' => $name, 'email' => $email, 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to', 'approver_id' => $this->approver->id]);
    }

    /** P-1 exige aprobación interna de Sara (y de Tomás si $both). */
    private function setUpInternal(bool $both = false): void
    {
        Mail::fake();
        $this->world();
        $this->sara = $this->supervisor('Sara Díaz', 'sara@cerevelo.test');
        $this->tomas = $this->supervisor('Tomás Ruiz', 'tomas@cerevelo.test');
        Sanctum::actingAs($this->owner);
        $this->makeInternal($this->project, 'P-1', 'Planta A', $both ? [$this->sara->id, $this->tomas->id] : [$this->sara->id]);
    }

    /** Todos los servicios del proyecto llevan esos aprobadores internos y piden aprobación interna y externa (el cliente lleva a Jorge). */
    private function makeInternal(Project $project, string $code, string $name, array $ids, string $mode = 'both'): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/projects/'.$project->id, ['code' => $code, 'name' => $name, 'billable' => false])->assertOk();
        $this->clientWithApprover($project);
        foreach ($project->services()->get() as $svc) {
            $this->putJson('/api/services/'.$svc->id, ['approval_mode' => $mode, 'internal_approver_ids' => $ids])->assertOk();
        }
    }

    /** Segundo proyecto con su propio interno (Tomás). */
    private function addSecondProject(): void
    {
        $this->second = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Planta B', 'bill_rate' => 60]);
        $this->worker->projects()->attach($this->second->id);
        Sanctum::actingAs($this->owner);
        $this->makeInternal($this->second, 'P-2', 'Planta B', [$this->tomas->id]);
    }

    /** La persona carga 8 h en P-1 (y 2 h en P-2 si existe) y envía la hoja. */
    private function submit(): Timesheet
    {
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        if (isset($this->second)) {
            $this->postJson('/api/entries', $this->entry(['project_id' => $this->second->id, 'start_time' => '16:00', 'end_time' => '18:00']))->assertCreated();
        }
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        return Timesheet::find($e['timesheet_id']);
    }

    private function mails(string $view)
    {
        return Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === $view);
    }

    public function test_project_without_internal_approval_keeps_the_current_flow(): void
    {
        Mail::fake();
        $this->world();
        $sheet = $this->submit();
        $this->assertSame('external', $sheet->fresh()->stage);
        $this->assertCount(1, $this->mails('mail.approval-request'));
        $this->assertCount(0, $this->mails('mail.internal-approval-request'));
        $j = $this->getJson('/api/sheets/'.$sheet->id)->assertOk()->json();
        $this->assertSame('external', $j['stage']);
        $this->assertSame([['id' => $this->approver->id, 'name' => 'Jorge Ramos', 'kind' => 'external']], $j['waiting_for']);
        $this->assertSame([], $j['internal']);
    }

    public function test_internal_stage_then_external_gets_the_request_after_the_internal_approves(): void
    {
        $this->setUpInternal();
        $sheet = $this->submit();

        $this->assertSame('internal', $sheet->fresh()->stage);
        $this->assertCount(0, $this->mails('mail.approval-request'));            // el externo todavía no se entera
        $m = $this->mails('mail.internal-approval-request');
        $this->assertCount(1, $m);
        $this->assertTrue($m->first()->hasTo('sara@cerevelo.test'));
        $html = $m->first()->render();
        $this->assertStringContainsString('Ana Torres', $html);
        $this->assertStringContainsString('8.00 h', $html);
        $this->assertStringContainsString('P-1 · Planta A', $html);

        // La persona ve a quién espera.
        $j = $this->getJson('/api/sheets/'.$sheet->id)->assertOk()->json();
        $this->assertSame('internal', $j['stage']);
        $this->assertSame([['id' => $this->sara->id, 'name' => 'Sara Díaz', 'kind' => 'internal']], $j['waiting_for']);
        $this->assertSame('pending', $j['internal'][0]['status']);
        $this->assertSame('P-1 · Planta A', $j['internal'][0]['project']);

        // El dueño ve la etapa en su lista de revisión.
        Sanctum::actingAs($this->owner);
        $this->assertSame('internal', $this->getJson('/api/review/sheets')->json('sheets.0.stage'));

        Sanctum::actingAs($this->sara);
        $this->getJson('/api/me')->assertJsonPath('internal_approver', true);
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertOk()
            ->assertJsonPath('stage', 'external')->assertJsonPath('internal.0.status', 'approved')->assertJsonPath('internal.0.approved_by.name', 'Sara Díaz');

        $this->assertCount(1, $this->mails('mail.approval-request'));
        $this->assertTrue($this->mails('mail.approval-request')->first()->hasTo('jorge@cliente.test'));
        $this->assertContains('internal_approved', $sheet->events()->pluck('type')->all());
        $this->assertContains('stage_external', $sheet->events()->pluck('type')->all());

        // El externo certifica al final, con el enlace de siempre.
        $plain = $this->tokenFromMail($this->mails('mail.approval-request')->first()->render());
        $this->post('/a/'.$plain.'/approve')->assertOk();
        $this->assertSame('approved', $sheet->fresh()->status);
        $this->assertNull($sheet->fresh()->stage);
    }

    public function test_one_of_two_internals_is_enough(): void
    {
        $this->setUpInternal(true);
        $sheet = $this->submit();
        $this->assertCount(2, $this->mails('mail.internal-approval-request'));

        Sanctum::actingAs($this->tomas);
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertOk()->assertJsonPath('stage', 'external');
        // Sara ya no tiene nada pendiente en esa hoja.
        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertStatus(422);
        $this->assertSame(0, $this->getJson('/api/internal/review')->json('counts.pending'));
    }

    public function test_two_projects_need_each_their_own_internal_approval(): void
    {
        $this->setUpInternal();
        $this->addSecondProject();
        $sheet = $this->submit();
        $this->assertCount(2, $this->mails('mail.internal-approval-request'));

        // Cada interno solo ve las horas de lo suyo.
        Sanctum::actingAs($this->sara);
        $d = $this->getJson('/api/internal/sheets/'.$sheet->id)->assertOk()->json();
        $this->assertCount(1, $d['entries']);
        $this->assertSame('P-1', $d['entries'][0]['project']['code']);
        $this->assertEquals(8, $d['total_hours']);
        $this->assertCount(1, $d['internal']);
        $this->assertStringNotContainsString('P-2', json_encode($d));

        $this->postJson('/api/internal/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertOk()
            ->assertJsonPath('stage', 'internal');
        $this->assertCount(0, $this->mails('mail.approval-request'));

        Sanctum::actingAs($this->worker);
        $j = $this->getJson('/api/sheets/'.$sheet->id)->json();
        $this->assertSame(['approved', 'pending'], array_column($j['internal'], 'status'));
        $this->assertSame([['id' => $this->tomas->id, 'name' => 'Tomás Ruiz', 'kind' => 'internal']], $j['waiting_for']);

        Sanctum::actingAs($this->tomas);
        $this->assertEquals(2, $this->getJson('/api/internal/sheets/'.$sheet->id)->json('total_hours'));
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertOk()
            ->assertJsonPath('stage', 'external');
        $this->assertCount(1, $this->mails('mail.approval-request'));
    }

    public function test_internal_return_sends_the_whole_sheet_back_and_resubmitting_starts_over(): void
    {
        $this->setUpInternal();
        $this->addSecondProject();
        $sheet = $this->submit();

        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertOk();
        Sanctum::actingAs($this->tomas);
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/return', ['version' => $sheet->version])->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/return', ['version' => $sheet->version, 'comment' => 'Faltan horas del martes'])
            ->assertOk()->assertJsonPath('status', 'returned');

        $fresh = $sheet->fresh();
        $this->assertSame('returned', $fresh->status);
        $this->assertNull($fresh->stage);
        $this->assertSame('Faltan horas del martes', $fresh->return_comment);
        $m = $this->mails('mail.sheet-decision')->last();
        $this->assertTrue($m->hasTo('ana@cerevelo.test'));
        $html = $m->render();
        $this->assertStringContainsString('Tomás Ruiz', $html);
        $this->assertStringContainsString('Faltan horas del martes', $html);
        $this->assertCount(0, $this->mails('mail.approval-request'));
        $this->assertSame('Tomás Ruiz', $sheet->events()->where('type', 'returned')->first()->actor);

        // Al reenviar empieza de nuevo: la aprobación previa de Sara ya no cuenta.
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/sheets/'.$sheet->id.'/submit')->assertOk()->assertJsonPath('stage', 'internal');
        $j = $this->getJson('/api/sheets/'.$sheet->id)->json();
        $this->assertSame(['pending', 'pending'], array_column($j['internal'], 'status'));
        $this->assertCount(4, $this->mails('mail.internal-approval-request'));    // 2 al enviar + 2 al reenviar
    }

    public function test_nobody_approves_their_own_hours(): void
    {
        $this->setUpInternal();
        // Ana es la única interna de P-1: esa parte se omite y la hoja va directo al externo.
        Sanctum::actingAs($this->owner);
        $this->makeInternal($this->project, 'P-1', 'Planta A', [$this->worker->id]);
        $sheet = $this->submit();
        $this->assertSame('external', $sheet->fresh()->stage);
        $this->assertCount(0, $this->mails('mail.internal-approval-request'));
        $this->assertCount(1, $this->mails('mail.approval-request'));
        $this->assertContains('internal_skipped', $sheet->events()->pluck('type')->all());
        $j = $this->getJson('/api/sheets/'.$sheet->id)->json();
        $this->assertSame('skipped', $j['internal'][0]['status']);

        // Ana no puede entrar a aprobar lo suyo aunque sea interna del proyecto.
        $this->getJson('/api/internal/sheets/'.$sheet->id)->assertNotFound();
    }

    public function test_sender_among_several_internals_does_not_count(): void
    {
        $this->setUpInternal();
        Sanctum::actingAs($this->owner);
        $this->makeInternal($this->project, 'P-1', 'Planta A', [$this->worker->id, $this->sara->id]);
        $sheet = $this->submit();
        $this->assertSame('internal', $sheet->fresh()->stage);
        $this->assertCount(1, $this->mails('mail.internal-approval-request'));
        $this->assertTrue($this->mails('mail.internal-approval-request')->first()->hasTo('sara@cerevelo.test'));
        $this->assertSame(['Sara Díaz'], array_column($this->getJson('/api/sheets/'.$sheet->id)->json('internal.0.approvers'), 'name'));
    }

    public function test_a_change_during_the_internal_stage_invalidates_approvals_and_notifies_again(): void
    {
        $this->setUpInternal();
        $this->addSecondProject();
        $sheet = $this->submit();
        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertOk();
        $before = $this->mails('mail.internal-approval-request')->count();

        Sanctum::actingAs($this->worker);
        $entry = $sheet->entries()->where('project_id', $this->project->id)->first();
        $this->putJson('/api/entries/'.$entry->id, $this->entry(['start_time' => '07:00']))->assertOk();
        $this->assertSame(2, $sheet->fresh()->version);
        $this->assertSame(['pending', 'pending'], array_column($this->getJson('/api/sheets/'.$sheet->id)->json('internal'), 'status'));
        $this->assertContains('internal_invalidated', $sheet->events()->pluck('type')->all());

        // La versión vieja ya no se aprueba.
        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/approve', ['version' => 1])->assertStatus(422)->assertJsonValidationErrors('version');

        // Aviso diferido igual que con el externo.
        $this->assertSame($before, $this->mails('mail.internal-approval-request')->count());
        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertSame(1, app(SheetService::class)->sendDueChangeNotices());
        $this->assertSame($before + 2, $this->mails('mail.internal-approval-request')->count());
        $this->assertStringContainsString('cambió', $this->mails('mail.internal-approval-request')->last()->render());
    }

    public function test_owner_can_override_in_the_internal_stage(): void
    {
        $this->setUpInternal();
        $sheet = $this->submit();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertOk()->assertJsonPath('status', 'approved');
        $types = $sheet->events()->pluck('type')->all();
        $this->assertContains('override', $types);
        $this->assertSame('internal', $sheet->events()->where('type', 'override')->first()->data['stage']);

        // Devolver también.
        $this->assertCount(1, $this->mails('mail.internal-approval-request'));
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-13']))->assertCreated();
        $s2 = Timesheet::where('week_ending', '2026-10-18')->orderByDesc('id')->first();
        $this->postJson('/api/sheets/'.$s2->id.'/submit')->assertOk()->assertJsonPath('stage', 'internal');
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$s2->id.'/return', ['comment' => 'Revisar'])->assertOk()->assertJsonPath('status', 'returned');
        $this->assertContains('override', $s2->events()->pluck('type')->all());
    }

    public function test_external_approver_cannot_decide_during_the_internal_stage(): void
    {
        $this->setUpInternal();
        $sheet = $this->submit();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SheetService::class)->approve($sheet, $this->approver, null, 'link', 1);
    }

    public function test_only_internals_use_the_internal_endpoints_and_only_for_their_projects(): void
    {
        $this->setUpInternal();
        $this->addSecondProject();
        $sheet = $this->submit();

        Sanctum::actingAs($this->worker);
        $this->getJson('/api/internal/review')->assertForbidden();
        $this->getJson('/api/me')->assertJsonPath('internal_approver', false);
        $nobody = $this->supervisor('Nadie', 'nadie@cerevelo.test');
        Sanctum::actingAs($nobody);
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/approve', ['version' => 1])->assertForbidden();

        // Un interno de otro proyecto que no está en esta hoja no la ve ni la aprueba.
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-3', 'name' => 'Otra', 'bill_rate' => 10]);
        $outsider = $this->supervisor('Fuera', 'fuera@cerevelo.test');
        Sanctum::actingAs($this->owner);
        $this->makeInternal($other, 'P-3', 'Otra', [$outsider->id]);
        Sanctum::actingAs($outsider);
        $this->getJson('/api/internal/sheets/'.$sheet->id)->assertNotFound();
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/approve', ['version' => 1])->assertNotFound();
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/return', ['version' => 1, 'comment' => 'no'])->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/internal/review')->json('counts.all'));
    }

    public function test_internal_review_lists_pending_approved_and_returned_with_filters(): void
    {
        $this->setUpInternal();
        $sheet = $this->submit();
        Sanctum::actingAs($this->sara);
        $r = $this->getJson('/api/internal/review')->assertOk()->json();
        $this->assertSame(['pending' => 1, 'approved' => 0, 'returned' => 0, 'all' => 1], $r['counts']);
        $row = $r['sheets'][0];
        $this->assertSame($sheet->id, $row['id']);
        $this->assertSame('Ana Torres', $row['user']['name']);
        $this->assertSame('internal', $row['stage']);
        $this->assertEquals(8, $row['total_hours']);
        $this->assertSame(['P-1 · Planta A'], $row['projects']);

        $this->getJson('/api/internal/review?q=zzz')->assertJsonPath('counts.all', 0);
        $this->getJson('/api/internal/review?status=bogus')->assertStatus(422);

        $this->postJson('/api/internal/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertOk();
        $r = $this->getJson('/api/internal/review?status=approved')->json();
        $this->assertSame(['pending' => 0, 'approved' => 1, 'returned' => 0, 'all' => 1], $r['counts']);
        $this->assertCount(1, $r['sheets']);
        // Periodo: aplica a Aprobadas, no a Pendientes.
        $this->assertCount(0, $this->getJson('/api/internal/review?status=approved&from=2026-11-01')->json('sheets'));
    }

    public function test_reminders_also_reach_the_internal_approvers(): void
    {
        $this->setUpInternal();
        $this->submit();
        $this->artisan('approvals:remind')->assertSuccessful();
        $n = $this->mails('mail.internal-approval-request')->count();

        Carbon::setTestNow(now()->addDays(8));
        $this->artisan('approvals:remind')->assertSuccessful();
        $this->assertSame($n + 1, $this->mails('mail.internal-approval-request')->count());
        $last = $this->mails('mail.internal-approval-request')->last();
        $this->assertTrue($last->hasTo('sara@cerevelo.test'));
        $this->assertStringContainsString('Ana Torres', $last->render());
        $this->assertCount(0, $this->mails('mail.pending-digest'));          // el externo aún no la ve

        // Antes de "cada N días" no se repite.
        $this->artisan('approvals:remind');
        $this->assertSame($n + 1, $this->mails('mail.internal-approval-request')->count());
        Carbon::setTestNow(now()->addDays(4));
        $this->artisan('approvals:remind');
        $this->assertSame($n + 2, $this->mails('mail.internal-approval-request')->count());
    }

    public function test_accounts_show_internal_workers_as_approvers_and_keep_the_real_role(): void
    {
        $this->setUpInternal();
        Sanctum::actingAs($this->owner);
        $rows = collect($this->getJson('/api/accounts')->assertOk()->json());
        $sara = $rows->firstWhere('email', 'sara@cerevelo.test');
        $this->assertSame('approver', $sara['role']);
        $this->assertSame('worker', $sara['user_role']);
        $this->assertSame('internal', $sara['approver_kind']);
        $this->assertFalse($sara['two_step']);
        $tomas = $rows->firstWhere('email', 'tomas@cerevelo.test');
        $this->assertSame('worker', $tomas['role']);
        $this->assertNull($tomas['approver_kind']);
        $this->assertSame('backoffice', $rows->firstWhere('email', 'luis@cerevelo.test')['role']);
        $jorge = $rows->firstWhere('email', 'jorge@cliente.test');
        $this->assertSame('external', $jorge['approver_kind']);
        $this->assertSame('approver', $jorge['role']);

        $approvers = collect($this->getJson('/api/accounts?role=approver')->json())->pluck('email')->all();
        $this->assertContains('sara@cerevelo.test', $approvers);
        $this->assertNotContains('tomas@cerevelo.test', $approvers);
    }

    public function test_internal_services_require_at_least_one_active_internal_approver(): void
    {
        $this->setUpInternal();
        $svc = $this->project->services()->first();
        // Un servicio internal/both no puede quedar sin supervisores: ni vaciando la lista ni dejando solo inactivos.
        $this->putJson('/api/services/'.$svc->id, ['approval_mode' => 'both', 'internal_approver_ids' => []])->assertStatus(422)->assertJsonValidationErrors('internal_approver_ids');
        $this->putJson('/api/services/'.$svc->id, ['internal_approver_ids' => []])->assertStatus(422)->assertJsonValidationErrors('internal_approver_ids');
        $this->sara->update(['active' => false]);
        $this->putJson('/api/services/'.$svc->id, ['approval_mode' => 'both', 'internal_approver_ids' => [$this->sara->id]])->assertStatus(422)->assertJsonValidationErrors('internal_approver_ids');

        $r = $this->putJson('/api/services/'.$svc->id, ['approval_mode' => 'both', 'internal_approver_ids' => [$this->tomas->id]])->assertOk();
        $this->assertSame([$this->tomas->id], $r->json('internal_approver_ids'));
        $this->assertSame([['id' => $this->tomas->id, 'name' => 'Tomás Ruiz']], $r->json('internal_approvers'));
        $p = collect($this->getJson('/api/projects')->json())->firstWhere('id', $this->project->id);
        $this->assertTrue($p['requires_internal_approval']);       // derivada de los servicios
        $this->assertArrayNotHasKey('internal_approver_ids', $p);   // la lista ya no es del proyecto
        $this->assertSame([$this->tomas->id], $p['services'][0]['internal_approver_ids']);

        // El proyecto ignora la lista vieja y la casilla vieja.
        $base = ['code' => 'P-1', 'name' => 'Planta A', 'billable' => false];
        $r = $this->putJson('/api/projects/'.$this->project->id, $base + ['internal_approver_ids' => [$this->sara->id], 'requires_internal_approval' => false])->assertOk();
        $this->assertArrayNotHasKey('internal_approver_ids', $r->json());
        $this->assertTrue($r->json('requires_internal_approval'));
        $this->assertSame([$this->tomas->id], $svc->fresh()->internalApprovers()->pluck('users.id')->all());
        $this->postJson('/api/projects', ['code' => 'N-1', 'name' => 'Nuevo', 'billable' => false, 'requires_internal_approval' => true])->assertCreated()
            ->assertJsonPath('requires_internal_approval', false);
    }
}
