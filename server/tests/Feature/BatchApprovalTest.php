<?php

namespace Tests\Feature;

use App\Models\Signature;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** Aprobar varias semanas a la vez: una contraseña, cada hoja con su firma, y lo que falla en una no detiene a las demás. */
class BatchApprovalTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->world();
        $this->sara = User::create(['company_id' => $this->company->id, 'name' => 'Sara Díaz', 'email' => 'sara@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to', 'approver_id' => $this->approver->id]);
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/projects/'.$this->project->id, ['code' => 'P-1', 'name' => 'Planta A', 'billable' => false])->assertOk();
        $this->clientWithApprover($this->project);
        foreach ($this->project->services()->get() as $svc) {
            $this->putJson('/api/services/'.$svc->id, ['approval_mode' => 'both', 'internal_approver_ids' => [$this->sara->id]])->assertOk();
        }
    }

    /** La persona envía una hoja de la semana de $day. */
    private function sent(string $day): Timesheet
    {
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry(['work_date' => $day]))->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        return Timesheet::find($e['timesheet_id']);
    }

    private function items(Timesheet ...$sheets): array
    {
        return array_map(fn ($s) => ['id' => $s->id, 'version' => (int) $s->version], $sheets);
    }

    public function test_an_internal_approver_approves_several_weeks_with_one_password_and_each_gets_its_signature(): void
    {
        $a = $this->sent('2026-10-20');
        $b = $this->sent('2026-10-13');
        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/approve-many', ['sheets' => $this->items($a, $b), 'signature' => 'secret-password'])
            ->assertOk()->assertJsonPath('approved', 2)->assertJsonPath('failed', 0);
        foreach ([$a, $b] as $s) {
            $this->assertSame(Timesheet::STAGE_EXTERNAL, $s->fresh()->stage);   // pasó a la etapa del cliente
            $this->assertSame(1, Signature::where('subject_type', 'timesheet')->where('subject_id', $s->id)->where('action', 'sheet.approve')->count());
        }
    }

    public function test_a_wrong_password_approves_nothing(): void
    {
        $a = $this->sent('2026-10-20');
        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/approve-many', ['sheets' => $this->items($a), 'signature' => 'otra-clave'])->assertStatus(422);
        $this->assertSame(Timesheet::STAGE_INTERNAL, $a->fresh()->stage);
    }

    public function test_one_that_fails_does_not_stop_the_others_and_the_result_says_why(): void
    {
        $a = $this->sent('2026-10-20');
        $b = $this->sent('2026-10-13');
        Sanctum::actingAs($this->sara);
        $stale = ['id' => $b->id, 'version' => (int) $b->version + 5];   // la hoja cambió después de enviarse
        $r = $this->postJson('/api/internal/sheets/approve-many', ['sheets' => [['id' => $a->id, 'version' => (int) $a->version], $stale, ['id' => 999999, 'version' => 1]], 'signature' => 'secret-password'])
            ->assertOk()->assertJsonPath('approved', 1)->assertJsonPath('failed', 2);
        $this->assertTrue($r->json('results.0.ok'));
        $this->assertFalse($r->json('results.1.ok'));
        $this->assertNotEmpty($r->json('results.1.message'));
        $this->assertFalse($r->json('results.2.ok'));
        $this->assertSame(Timesheet::STAGE_EXTERNAL, $a->fresh()->stage);
        $this->assertSame(Timesheet::STAGE_INTERNAL, $b->fresh()->stage);
    }

    public function test_the_limit_is_25_and_nobody_approves_sheets_that_are_not_theirs(): void
    {
        $a = $this->sent('2026-10-20');
        Sanctum::actingAs($this->sara);
        $many = array_map(fn ($i) => ['id' => 1000 + $i, 'version' => 1], range(1, 26));
        $this->postJson('/api/internal/sheets/approve-many', ['sheets' => $many, 'signature' => 'secret-password'])->assertStatus(422);
        Sanctum::actingAs($this->worker);   // quien envió no aprueba lo suyo
        $this->postJson('/api/internal/sheets/approve-many', ['sheets' => $this->items($a), 'signature' => 'secret-password'])->assertForbidden();
    }

    public function test_the_owner_cannot_approve_in_a_batch_what_is_with_the_client_and_the_external_approver_can(): void
    {
        $a = $this->sent('2026-10-20');
        $b = $this->sent('2026-10-13');
        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/approve-many', ['sheets' => $this->items($a, $b), 'signature' => 'secret-password'])->assertOk()->assertJsonPath('approved', 2);

        Sanctum::actingAs($this->owner);   // el dueño no aprueba por el cliente, ni en lote
        $this->postJson('/api/sheets/approve-many', ['sheets' => $this->items($a->fresh(), $b->fresh()), 'signature' => 'secret-password'])
            ->assertOk()->assertJsonPath('approved', 0)->assertJsonPath('failed', 2);
        $this->assertSame(Timesheet::SUBMITTED, $a->fresh()->status);

        $this->approver->update(['can_login' => true, 'require_code' => false]);
        $jorge = User::create(['company_id' => $this->company->id, 'name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'password' => 'secret-password',
            'role' => 'approver', 'approver_profile_id' => $this->approver->id]);
        Sanctum::actingAs($jorge);
        $this->postJson('/api/approver/sheets/approve-many', ['sheets' => $this->items($a->fresh(), $b->fresh()), 'signature' => 'secret-password'])
            ->assertOk()->assertJsonPath('approved', 2)->assertJsonPath('failed', 0);
        $this->assertSame(Timesheet::APPROVED, $a->fresh()->status);
        $this->assertSame(Timesheet::APPROVED, $b->fresh()->status);
    }

    public function test_a_failing_notice_email_does_not_break_an_approval_already_saved(): void
    {
        $a = $this->sent('2026-10-20');
        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/approve-many', ['sheets' => $this->items($a), 'signature' => 'secret-password'])->assertOk();
        $this->approver->update(['can_login' => true, 'require_code' => false]);
        $jorge = User::create(['company_id' => $this->company->id, 'name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'password' => 'secret-password',
            'role' => 'approver', 'approver_profile_id' => $this->approver->id]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('el servidor de correo no responde'));
        Sanctum::actingAs($jorge);
        $this->postJson('/api/approver/sheets/'.$a->id.'/approve', ['version' => (int) $a->fresh()->version, 'signature' => 'secret-password'])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame(Timesheet::APPROVED, $a->fresh()->status);
    }
}
