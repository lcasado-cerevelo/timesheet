<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Project;
use App\Models\Service;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** Cada servicio dice qué aprobación necesita: none | internal | external | both. */
class ServiceApprovalTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private User $sara;
    private array $svc = [];   // modo => id del servicio

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->world();
        $this->sara = User::create(['company_id' => $this->company->id, 'name' => 'Sara Díaz', 'email' => 'sara@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to', 'approver_id' => $this->approver->id]);
        Sanctum::actingAs($this->owner);
        $this->clientWithApprover($this->project);
        // El servicio "General" es 'none'; se crean uno por cada otro modo.
        $general = Service::where('project_id', $this->project->id)->first();
        $this->putJson('/api/services/'.$general->id, ['approval_mode' => 'none'])->assertOk()->assertJsonPath('approval_mode', 'none');
        $this->svc['none'] = $general->id;
        foreach (['internal', 'external', 'both'] as $mode) {
            $this->svc[$mode] = $this->postJson('/api/projects/'.$this->project->id.'/services', ['name' => 'S-'.$mode, 'approval_mode' => $mode, 'internal_approver_ids' => [$this->sara->id]])
                ->assertCreated()->assertJsonPath('approval_mode', $mode)->json('id');
        }
    }

    private function mails(string $view)
    {
        return Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === $view);
    }

    /** La persona carga una entrada de 2 h por cada modo indicado y envía la hoja. */
    private function submit(array $modes, string $date = '2026-10-20'): Timesheet
    {
        Sanctum::actingAs($this->worker);
        $h = 8;
        $id = null;
        foreach ($modes as $mode) {
            $id = $this->postJson('/api/entries', $this->entry(['work_date' => $date, 'service_id' => $this->svc[$mode], 'start_time' => sprintf('%02d:00', $h), 'end_time' => sprintf('%02d:00', $h + 2)]))
                ->assertCreated()->json('timesheet_id');
            $h += 2;
        }
        $this->postJson('/api/sheets/'.$id.'/submit')->assertOk();
        return Timesheet::find($id);
    }

    public function test_none_approves_automatically_on_submit(): void
    {
        $s = $this->submit(['none']);
        $this->assertSame('approved', $s->status);
        $this->assertTrue($s->isLocked());
        $this->assertNull($s->stage);
        $this->assertContains('auto_approved', $s->events()->pluck('type')->all());
        $this->assertCount(0, $this->mails('mail.approval-request'));
        $this->assertCount(0, $this->mails('mail.internal-approval-request'));
        $decision = $this->mails('mail.sheet-decision');
        $this->assertCount(1, $decision);
        $this->assertTrue($decision->first()->hasTo('ana@cerevelo.test'));
        $j = $this->getJson('/api/sheets/'.$s->id)->assertOk()->json();
        $this->assertSame('approved', $j['status']);
        $this->assertTrue($j['locked']);
        $this->assertSame([], $j['required_stages']);
        $this->assertNull($j['approved_by']);
        // Una hoja aprobada no se edita.
        $this->postJson('/api/entries', $this->entry(['service_id' => $this->svc['none'], 'start_time' => '14:00', 'end_time' => '15:00']))->assertClientError();
    }

    public function test_internal_only_ends_approved_by_the_internal_approver(): void
    {
        $s = $this->submit(['internal']);
        $this->assertSame('internal', $s->stage);
        $this->assertSame(['internal'], $this->getJson('/api/sheets/'.$s->id)->json('required_stages'));
        $this->assertCount(1, $this->mails('mail.internal-approval-request'));
        $this->assertCount(0, $this->mails('mail.approval-request'));

        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/'.$s->id.'/approve', ['version' => $s->version])->assertOk()
            ->assertJsonPath('status', 'approved')->assertJsonPath('approved_by', 'Sara Díaz')->assertJsonPath('locked', true);
        $this->assertCount(0, $this->mails('mail.approval-request'));      // el externo nunca se entera
        $types = $s->events()->pluck('type')->all();
        $this->assertContains('approved', $types);
        $this->assertNotContains('auto_approved', $types);
        $this->assertNotContains('stage_external', $types);
        $this->assertCount(1, $this->mails('mail.sheet-decision'));
        $this->assertSame($this->sara->id, $s->fresh()->decided_by_user_id);
    }

    public function test_external_only_goes_straight_to_the_external_approver(): void
    {
        $s = $this->submit(['external']);
        $this->assertSame('external', $s->stage);
        $this->assertCount(1, $this->mails('mail.approval-request'));
        $this->assertCount(0, $this->mails('mail.internal-approval-request'));
        $this->assertSame(['external'], $this->getJson('/api/sheets/'.$s->id)->json('required_stages'));
    }

    public function test_both_goes_internal_then_external(): void
    {
        $s = $this->submit(['both']);
        $this->assertSame('internal', $s->stage);
        $this->assertSame(['internal', 'external'], $this->getJson('/api/sheets/'.$s->id)->json('required_stages'));
        $this->assertCount(0, $this->mails('mail.approval-request'));
        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/'.$s->id.'/approve', ['version' => $s->version])->assertOk()
            ->assertJsonPath('status', 'submitted')->assertJsonPath('stage', 'external');
        $this->assertCount(1, $this->mails('mail.approval-request'));
    }

    public function test_mixed_services_in_one_sheet_decide_per_entry(): void
    {
        // none + external: solo externa.
        $s = $this->submit(['none', 'external'], '2026-10-13');
        $this->assertSame('external', $s->stage);
        $this->assertSame(['external'], $this->getJson('/api/sheets/'.$s->id)->json('required_stages'));

        // none + internal: solo interna.
        $s2 = $this->submit(['none', 'internal'], '2026-10-06');
        $this->assertSame('internal', $s2->stage);
        $this->assertSame(['internal'], $this->getJson('/api/sheets/'.$s2->id)->json('required_stages'));

        // internal + external: las dos.
        $s3 = $this->submit(['internal', 'external'], '2026-10-20');
        $this->assertSame('internal', $s3->stage);
        $this->assertSame(['internal', 'external'], $this->getJson('/api/sheets/'.$s3->id)->json('required_stages'));
        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/'.$s3->id.'/approve', ['version' => $s3->version])->assertOk()->assertJsonPath('stage', 'external');
    }

    public function test_only_the_internal_services_of_a_project_create_internal_parts(): void
    {
        $s = $this->submit(['none', 'external']);
        $this->assertSame([], $this->getJson('/api/sheets/'.$s->id)->json('internal'));
    }

    public function test_resubmitting_after_a_return_recalculates_everything(): void
    {
        $s = $this->submit(['external']);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$s->id.'/return', ['comment' => 'Revisar'])->assertOk();
        // Cambia la política del servicio y la persona reenvía.
        $this->putJson('/api/services/'.$this->svc['external'], ['approval_mode' => 'none'])->assertOk();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/sheets/'.$s->id.'/submit')->assertOk()->assertJsonPath('status', 'approved');
        $this->assertContains('auto_approved', $s->events()->pluck('type')->all());

        // Y otra devuelta que pasa de 'internal' a 'both' sigue en la etapa interna, ahora con las dos requeridas.
        $s2 = $this->submit(['internal'], '2026-10-13');
        $this->assertSame('internal', $s2->stage);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$s2->id.'/return', ['comment' => 'Revisar'])->assertOk();
        $this->putJson('/api/services/'.$this->svc['internal'], ['approval_mode' => 'both'])->assertOk();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/sheets/'.$s2->id.'/submit')->assertOk()->assertJsonPath('stage', 'internal')->assertJsonPath('required_stages', ['internal', 'external']);
    }

    public function test_changing_a_service_mode_does_not_touch_sheets_already_sent(): void
    {
        $s = $this->submit(['external']);
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/services/'.$this->svc['external'], ['approval_mode' => 'none'])->assertOk();
        $this->assertSame('submitted', $s->fresh()->status);
        $this->assertSame('external', $s->fresh()->stage);
    }

    public function test_internal_modes_need_an_active_internal_approver(): void
    {
        Sanctum::actingAs($this->owner);
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-9', 'name' => 'Otro', 'bill_rate' => 10]);
        $this->clientWithApprover($other);
        $this->postJson('/api/projects/'.$other->id.'/services', ['name' => 'X', 'approval_mode' => 'internal'])
            ->assertStatus(422)->assertJsonValidationErrors('internal_approver_ids');
        $this->postJson('/api/projects/'.$other->id.'/services', ['name' => 'X', 'approval_mode' => 'both'])->assertStatus(422);
        $this->postJson('/api/projects/'.$other->id.'/services', ['name' => 'X', 'approval_mode' => 'both', 'internal_approver_ids' => []])->assertStatus(422);
        $this->postJson('/api/projects/'.$other->id.'/services', ['name' => 'X', 'approval_mode' => 'bogus'])->assertStatus(422)->assertJsonValidationErrors('approval_mode');
        $x = $this->postJson('/api/projects/'.$other->id.'/services', ['name' => 'X', 'approval_mode' => 'none'])->assertCreated()->json('id');
        $this->putJson('/api/services/'.$x, ['approval_mode' => 'both'])->assertStatus(422)->assertJsonValidationErrors('internal_approver_ids');
        $this->assertSame('none', Service::find($x)->approval_mode);

        // Un usuario de otra compañía, o inactivo, tampoco sirve.
        $this->sara->update(['active' => false]);
        $this->putJson('/api/services/'.$x, ['approval_mode' => 'both', 'internal_approver_ids' => [$this->sara->id]])->assertStatus(422)->assertJsonValidationErrors('internal_approver_ids');
        $this->sara->update(['active' => true]);
        $this->putJson('/api/services/'.$x, ['approval_mode' => 'both', 'internal_approver_ids' => [$this->sara->id]])->assertOk()->assertJsonPath('approval_mode', 'both');
        // Y no se puede dejar al servicio sin internos.
        $this->putJson('/api/services/'.$x, ['internal_approver_ids' => []])->assertStatus(422)->assertJsonValidationErrors('internal_approver_ids');
        $this->assertSame([$this->sara->id], Service::find($x)->internalApprovers()->pluck('users.id')->all());

        // Si el servicio deja de pedir aprobación interna, la lista se vacía; desactivarlo no se valida.
        $this->putJson('/api/services/'.$x, ['approval_mode' => 'external'])->assertOk()->assertJsonPath('internal_approver_ids', []);
        $this->putJson('/api/services/'.$x, ['active' => false])->assertOk();
    }

    public function test_project_flag_is_derived_from_the_services(): void
    {
        Sanctum::actingAs($this->owner);
        $get = fn () => collect($this->getJson('/api/projects')->json())->firstWhere('id', $this->project->id);
        $this->assertTrue($get()['requires_internal_approval']);
        $this->assertSame(['none', 'internal', 'external', 'both'], array_column($get()['services'], 'approval_mode'));
        $this->putJson('/api/services/'.$this->svc['internal'], ['approval_mode' => 'external'])->assertOk();
        $this->assertTrue($get()['requires_internal_approval']);                    // aún queda 'both'
        $this->putJson('/api/services/'.$this->svc['both'], ['active' => false])->assertOk();
        $this->assertFalse($get()['requires_internal_approval']);
    }

    public function test_non_owner_roles_also_receive_the_approval_mode(): void
    {
        Sanctum::actingAs($this->worker);
        $p = collect($this->getJson('/api/projects')->assertOk()->json())->firstWhere('id', $this->project->id);
        $this->assertSame(['none', 'internal', 'external', 'both'], array_column($p['services'], 'approval_mode'));
        $this->assertArrayNotHasKey('bill_rate', $p['services'][0]);
    }

    public function test_migration_moves_services_of_internal_projects_to_both(): void
    {
        $flagged = Project::create(['company_id' => $this->company->id, 'code' => 'M-1', 'name' => 'Con interna', 'bill_rate' => 10]);
        $plain = Project::create(['company_id' => $this->company->id, 'code' => 'M-2', 'name' => 'Sin interna', 'bill_rate' => 10]);
        Project::whereKey($flagged->id)->update(['requires_internal_approval' => true]);
        Service::where('project_id', $flagged->id)->update(['approval_mode' => 'external']);
        (require base_path('database/migrations/2026_10_31_000000_add_service_approval_mode.php'))->backfill();
        $this->assertSame(['both'], Service::where('project_id', $flagged->id)->pluck('approval_mode')->unique()->all());
        $this->assertSame(['external'], Service::where('project_id', $plain->id)->pluck('approval_mode')->unique()->all());
    }

    public function test_sheet_with_internal_part_skipped_and_no_external_is_auto_approved(): void
    {
        // Sara es la única interna y quien envía: la parte se omite y no hay externo -> aprobación automática.
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/services/'.$this->svc['internal'], ['approval_mode' => 'internal', 'internal_approver_ids' => [$this->worker->id]])->assertOk();
        $s = $this->submit(['internal']);
        $this->assertSame('approved', $s->status);
        $this->assertContains('internal_skipped', $s->events()->pluck('type')->all());
        $this->assertContains('auto_approved', $s->events()->pluck('type')->all());
    }
}
