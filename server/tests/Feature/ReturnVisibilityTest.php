<?php

namespace Tests\Feature;

use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * Quién ve qué de lo que pasó con una hoja. El dueño y los aprobadores internos ven todo (con el motivo de cada devolución);
 * el aprobador externo solo lo que hicieron los aprobadores externos: una hoja devuelta en la etapa interna nunca le sale
 * (ni en la lista, ni en el detalle, ni en el historial, ni en la exportación).
 */
class ReturnVisibilityTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private User $sara;
    private User $external;

    private function setUpBoth(): void
    {
        Mail::fake();
        $this->world();
        $this->sara = User::create(['company_id' => $this->company->id, 'name' => 'Sara Díaz', 'email' => 'sara@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to']);
        $this->external = User::create(['company_id' => $this->company->id, 'name' => 'Jorge Ramos', 'email' => 'cuenta@cliente.test', 'password' => 'secret-password',
            'role' => 'approver', 'worker_type' => 'employee', 'capture_mode' => 'full_day', 'approver_profile_id' => $this->approver->id]);
        Sanctum::actingAs($this->owner);
        $this->clientWithApprover($this->project);
        foreach ($this->project->services()->get() as $svc) {
            $this->putJson('/api/services/'.$svc->id, ['approval_mode' => 'both', 'internal_approver_ids' => [$this->sara->id]])->assertOk();
        }
    }

    private function submit(?int $sheetId = null): int
    {
        Sanctum::actingAs($this->worker);
        $id = $sheetId ?? $this->postJson('/api/entries', $this->entry())->assertCreated()->json('timesheet_id');
        $this->postJson('/api/sheets/'.$id.'/submit')->assertOk();
        return $id;
    }

    private function internalReturns(int $id, string $why): void
    {
        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/'.$id.'/return', ['comment' => $why, 'version' => Timesheet::find($id)->version])->assertOk();
    }

    private function types(array $history): array
    {
        return array_column($history, 'type');
    }

    public function test_a_sheet_returned_by_the_internal_approver_never_reaches_the_external_approver(): void
    {
        $this->setUpBoth();
        $id = $this->submit();
        $this->internalReturns($id, 'Las horas del martes no cuadran con el parte.');
        $this->assertSame('internal', Timesheet::find($id)->return_stage);

        Sanctum::actingAs($this->external);
        $this->getJson('/api/approver/sheets?status=returned')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/approver/sheets?status=all')->assertOk()->assertJsonCount(0);
        $review = $this->getJson('/api/approver/review?status=all')->assertOk()->json();
        $this->assertSame(0, $review['counts']['returned']);
        $this->assertSame([], $review['sheets']);
        $this->getJson('/api/approver/sheets/'.$id)->assertNotFound();
    }

    public function test_the_owner_and_the_internal_approver_see_the_whole_history_with_every_reason(): void
    {
        $this->setUpBoth();
        $id = $this->submit();
        $this->internalReturns($id, 'Las horas del martes no cuadran con el parte.');
        Sanctum::actingAs($this->owner);
        $h = $this->getJson('/api/sheets/'.$id)->assertOk()->json('history');
        $this->assertSame(['submitted', 'returned'], $this->types($h));
        $this->assertSame('Las horas del martes no cuadran con el parte.', $h[1]['comment']);
        $this->assertSame('internal', $h[1]['stage']);
        $this->assertSame('Sara Díaz', $h[1]['by']);
        Sanctum::actingAs($this->sara);
        $this->assertSame(['submitted', 'returned'], $this->types($this->getJson('/api/internal/sheets/'.$id)->assertOk()->json('history')));
    }

    public function test_an_external_return_is_seen_by_external_approvers_and_by_the_internal_approver_but_the_internal_one_stays_hidden(): void
    {
        $this->setUpBoth();
        $id = $this->submit();
        $this->internalReturns($id, 'Motivo interno que el cliente no debe ver.');
        $this->submit($id);                                                       // la persona corrige y reenvía: el comentario de la hoja se borra
        $this->assertNull(Timesheet::find($id)->return_comment);
        Sanctum::actingAs($this->sara);
        $this->postJson('/api/internal/sheets/'.$id.'/approve', ['version' => Timesheet::find($id)->version])->assertOk();

        Sanctum::actingAs($this->external);
        $this->postJson('/api/approver/sheets/'.$id.'/return', ['version' => Timesheet::find($id)->version, 'comment' => 'Falta la nota del jueves.'])->assertOk();
        $this->assertSame('external', Timesheet::find($id)->return_stage);

        // El externo ve su devolución en Devueltas, y en su historial solo lo que hicieron los externos.
        $list = $this->getJson('/api/approver/sheets?status=returned')->assertOk();
        $list->assertJsonCount(1)->assertJsonPath('0.return_comment', 'Falta la nota del jueves.');
        $h = $this->getJson('/api/approver/sheets/'.$id)->assertOk()->json('history');
        $this->assertSame(['returned'], $this->types($h));
        $this->assertSame('Falta la nota del jueves.', $h[0]['comment']);
        $this->assertStringNotContainsString('Motivo interno', json_encode($h));
        $this->assertStringNotContainsString('Sara', json_encode($h));

        // El interno la ve en Devueltas aunque la devolviera un externo, y todo el historial.
        Sanctum::actingAs($this->sara);
        $this->getJson('/api/internal/review?status=returned')->assertOk()->assertJsonCount(1, 'sheets');
        $types = $this->types($this->getJson('/api/internal/sheets/'.$id)->assertOk()->json('history'));
        $this->assertSame(['submitted', 'returned', 'resubmitted', 'internal_approved', 'returned'], $types);
    }

    public function test_the_exports_carry_the_reason_and_the_external_one_never_lists_an_internal_return(): void
    {
        $this->setUpBoth();
        $id = $this->submit();
        $this->internalReturns($id, 'Motivo interno que el cliente no debe ver.');
        $range = 'from='.now()->subWeeks(2)->toDateString().'&to='.now()->toDateString().'&status=returned';

        Sanctum::actingAs($this->owner);
        $csv = $this->get('/api/tables/sheets/export?format=csv&'.$range)->assertOk();
        $this->assertStringContainsString('Motivo de la devolución', $csv->getContent());
        $this->assertStringContainsString('Motivo interno que el cliente no debe ver.', $csv->getContent());
        foreach (['xlsx', 'pdf'] as $f) {
            $this->get('/api/tables/sheets/export?format='.$f.'&'.$range)->assertOk();
        }

        Sanctum::actingAs($this->external);
        $r = $this->get('/api/tables/sheets/export?format=csv&'.$range)->assertOk();
        $this->assertStringNotContainsString('Motivo interno', $r->getContent());
        $this->assertSame('0', (string) $r->headers->get('X-Export-Rows'));
    }

    public function test_resubmitting_clears_the_current_reason_but_the_history_keeps_it(): void
    {
        $this->setUpBoth();
        $id = $this->submit();
        $this->internalReturns($id, 'Motivo que queda en el historial.');
        $this->submit($id);
        $s = Timesheet::find($id);
        $this->assertNull($s->return_comment);
        $this->assertNull($s->return_stage);
        Sanctum::actingAs($this->owner);
        $this->assertStringContainsString('Motivo que queda en el historial.', json_encode($this->getJson('/api/sheets/'.$id)->json('history')));
    }
}
