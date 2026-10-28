<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Approver;
use App\Models\ApprovalToken;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\SheetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * Un servicio con aprobación interna Y externa: la hoja va primero a los internos y el aprobador externo no recibe NADA
 * (ni correo de aviso, ni recordatorio, ni enlace, ni la ve en su lista) hasta que la etapa interna termina.
 */
class InternalBeforeExternalTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private User $sara;

    /** $senderIsTheApprover: el único aprobador interno del servicio es la misma persona que envía la hoja. */
    private function setUpBoth(bool $senderIsTheApprover = false): void
    {
        Mail::fake();
        $this->world();
        $this->sara = User::create(['company_id' => $this->company->id, 'name' => 'Sara Díaz', 'email' => 'sara@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to']);
        Sanctum::actingAs($this->owner);
        $this->clientWithApprover($this->project);
        foreach ($this->project->services()->get() as $svc) {
            $this->putJson('/api/services/'.$svc->id, ['approval_mode' => 'both', 'internal_approver_ids' => [$senderIsTheApprover ? $this->worker->id : $this->sara->id]])->assertOk();
        }
    }

    private function submit(): Timesheet
    {
        Sanctum::actingAs($this->worker);
        $id = $this->postJson('/api/entries', $this->entry())->assertCreated()->json('timesheet_id');
        $this->postJson('/api/sheets/'.$id.'/submit')->assertOk();
        return Timesheet::find($id);
    }

    private function mails(string $view, ?string $to = null)
    {
        return Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === $view && ($to === null || $m->hasTo($to)));
    }

    private function externalAccount(): User
    {
        return User::create(['company_id' => $this->company->id, 'name' => 'Jorge', 'email' => 'cuenta@cliente.test', 'password' => 'secret-password',
            'role' => 'approver', 'worker_type' => 'employee', 'capture_mode' => 'full_day', 'approver_profile_id' => $this->approver->id]);
    }

    public function test_the_external_approver_gets_nothing_while_the_sheet_waits_for_the_internal_approver(): void
    {
        $this->setUpBoth();
        $account = $this->externalAccount();
        $sheet = $this->submit();

        $this->assertSame('internal', $sheet->fresh()->stage);
        $this->assertCount(1, $this->mails('mail.internal-approval-request', 'sara@cerevelo.test'));
        $this->assertCount(0, $this->mails('mail.approval-request'));
        $this->assertSame(0, ApprovalToken::where('timesheet_id', $sheet->id)->count());

        // El dueño ve en la hoja que falta la interna y que la externa está esperando.
        Sanctum::actingAs($this->owner);
        $j = $this->getJson('/api/sheets/'.$sheet->id)->assertOk()->json();
        $this->assertSame('internal', $j['stage']);
        $this->assertSame(['pending'], array_column($j['internal'], 'status'));

        // La cuenta del aprobador externo no la ve en su lista ni puede abrirla.
        Sanctum::actingAs($account);
        $this->assertCount(0, $this->getJson('/api/approver/sheets?status=all')->assertOk()->json());
        $this->getJson('/api/approver/sheets/'.$sheet->id)->assertNotFound();

        // La persona no puede cambiar la hoja mientras espera a la interna: no hay cambios que avisar.
        Sanctum::actingAs($this->worker);
        $entry = $sheet->entries()->first();
        $this->putJson('/api/entries/'.$entry->id, $this->entry(['start_time' => '07:00']))->assertStatus(423);
        $this->assertSame(0, app(SheetService::class)->sendDueChangeNotices());
        $this->assertCount(0, $this->mails('mail.approval-request'));
        $this->assertCount(1, $this->mails('mail.internal-approval-request', 'sara@cerevelo.test'));

        // Pasan los días: el recordatorio diario tampoco llega al externo.
        Carbon::setTestNow(now()->addDays(10));
        $this->artisan('approvals:remind')->assertSuccessful();
        $this->assertCount(0, $this->mails('mail.pending-digest'));
        $this->assertCount(0, $this->mails('mail.approval-request'));

        // Y recién cuando Sara aprueba, el externo recibe su aviso (una sola vez).
        Sanctum::actingAs($this->sara);
        $v = $sheet->fresh()->version;
        $this->postJson('/api/internal/sheets/'.$sheet->id.'/approve', ['version' => $v])->assertOk();
        $this->assertSame('external', $sheet->fresh()->stage);
        $this->assertCount(1, $this->mails('mail.approval-request', 'jorge@cliente.test'));
        Sanctum::actingAs($account);
        $this->assertCount(1, $this->getJson('/api/approver/sheets')->assertOk()->json());
    }

    /**
     * CASO LÍMITE: si quien debe aprobar internamente es la misma persona que envía la hoja (nadie aprueba lo suyo) o no queda ningún
     * interno activo, la parte interna se OMITE (R4) y la hoja pasa de inmediato al aprobador externo.
     */
    public function test_when_the_only_internal_approver_is_the_sender_the_internal_part_is_skipped_and_the_external_is_notified(): void
    {
        $this->setUpBoth(true);
        $sheet = $this->submit();

        $this->assertSame('external', $sheet->fresh()->stage);
        $this->assertCount(0, $this->mails('mail.internal-approval-request'));
        $this->assertCount(1, $this->mails('mail.approval-request', 'jorge@cliente.test'));
        $this->assertContains('internal_skipped', $sheet->events()->pluck('type')->all());
        Sanctum::actingAs($this->owner);
        $this->assertSame(['skipped'], array_column($this->getJson('/api/sheets/'.$sheet->id)->json('internal'), 'status'));
    }

    public function test_when_the_only_internal_approver_is_inactive_the_internal_part_is_skipped_too(): void
    {
        $this->setUpBoth();
        $this->sara->update(['active' => false]);
        $sheet = $this->submit();

        $this->assertSame('external', $sheet->fresh()->stage);
        $this->assertCount(1, $this->mails('mail.approval-request', 'jorge@cliente.test'));
        $this->assertContains('internal_skipped', $sheet->events()->pluck('type')->all());
    }
}
