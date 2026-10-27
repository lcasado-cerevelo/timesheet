<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Approver;
use App\Models\Service;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * Cómo ven y deciden los aprobadores (externo por enlace, externo con cuenta, supervisor interno) una semana enviada por partes,
 * y el caso del día devuelto que se reenvía junto con un día nuevo.
 */
class ApproverPartsTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->world();
        $this->company->update(['rules' => ['week_starts_on' => 1, 'partial_submission' => true]]);
        $this->clientWithApprover($this->project);
    }

    private function mails(string $view, ?string $to = null)
    {
        return Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === $view && ($to === null || $m->hasTo($to)));
    }

    private function lastLink(string $to = 'jorge@cliente.test'): string
    {
        return $this->tokenFromMail($this->mails('mail.approval-request', $to)->last()->render());
    }

    private function sendDay(string $date): void
    {
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['work_date' => $date]))->assertCreated();
        $this->postJson('/api/sheets/submit-days', ['through' => $date])->assertOk();
    }

    /** Lunes, martes y miércoles enviados por separado: tres partes, cada una con su correo al aprobador. */
    private function threeParts(): void
    {
        foreach (['2026-10-19', '2026-10-20', '2026-10-21'] as $d) {
            $this->sendDay($d);
        }
    }

    public function test_the_approver_gets_one_request_per_part_and_each_page_shows_its_own_days_only(): void
    {
        $this->threeParts();
        $this->assertCount(3, $this->mails('mail.approval-request', 'jorge@cliente.test'), 'un correo por parte');

        $mails = $this->mails('mail.approval-request', 'jorge@cliente.test');
        $subjects = $mails->map(fn ($m) => $m->envelope()->subject)->all();
        $this->assertStringContainsString('parte 1', $subjects[0]);
        $this->assertStringContainsString('2026-10-19', $subjects[0]);
        $this->assertStringContainsString('parte 2', $subjects[1]);
        $this->assertStringContainsString('2026-10-20', $subjects[1]);

        $page = $this->get('/a/'.$this->tokenFromMail($mails[1]->render()))->assertOk()->getContent();
        $this->assertStringContainsString('parte 2', $page);
        $this->assertStringContainsString('8', $page);
        $this->assertStringNotContainsString('24', $page, 'solo las horas de esa parte, no las de toda la semana');
    }

    public function test_approving_one_part_leaves_the_other_parts_pending(): void
    {
        $this->threeParts();
        $mails = $this->mails('mail.approval-request', 'jorge@cliente.test');
        $this->post('/a/'.$this->tokenFromMail($mails[0]->render()).'/approve')->assertOk();

        $st = Timesheet::orderBy('part')->pluck('status')->all();
        $this->assertSame(['approved', 'submitted', 'submitted'], $st);
    }

    public function test_the_returned_tuesday_is_resent_and_gets_a_new_request_while_monday_and_wednesday_stay_as_they_were(): void
    {
        $this->threeParts();
        $mails = $this->mails('mail.approval-request', 'jorge@cliente.test');
        $monday = $this->tokenFromMail($mails[0]->render());
        $tuesday = $this->tokenFromMail($mails[1]->render());
        $wednesday = $this->tokenFromMail($mails[2]->render());
        $this->post('/a/'.$monday.'/approve')->assertOk();
        $this->post('/a/'.$tuesday.'/return', ['comment' => 'Revisa el martes'])->assertOk();

        $parts = Timesheet::orderBy('part')->get();
        $this->assertSame(['approved', 'returned', 'submitted'], $parts->pluck('status')->all());
        $this->assertSame(1, (int) $parts[2]->version, 'el miércoles no se toca');
        $this->get('/a/'.$tuesday)->assertStatus(410);                  // el enlace del martes devuelto ya no sirve
        $this->get('/a/'.$wednesday)->assertOk();                       // el del miércoles sigue vivo

        // Jueves: la persona corrige el martes y manda el jueves; el servidor reenvía el martes y crea la parte del jueves.
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00', 'America/Puerto_Rico'));
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-22']))->assertCreated();
        $id = TimeEntry::where('work_date', '2026-10-20')->value('id');
        $this->putJson("/api/entries/$id", $this->entry(['work_date' => '2026-10-20', 'start_time' => '08:00', 'end_time' => '15:00']))->assertOk();
        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-22'])->assertOk();

        $all = Timesheet::orderBy('part')->get();
        $this->assertSame(['approved', 'submitted', 'submitted', 'submitted'], $all->pluck('status')->all());
        $this->assertSame(2, (int) $all[1]->version, 'el martes se reenvía en su misma hoja');
        $this->assertNull($all[1]->return_comment);

        // Dos correos nuevos: el martes corregido y el jueves nuevo.
        $new = $this->mails('mail.approval-request', 'jorge@cliente.test')->slice(3);
        $this->assertCount(2, $new);
        $kinds = $new->map(fn ($m) => $m->envelope()->subject)->all();
        $this->assertStringContainsString('2026-10-20', implode(' ', $kinds));
        $this->assertStringContainsString('2026-10-22', implode(' ', $kinds));
        $tuesdayLink = $this->tokenFromMail($new->first(fn ($m) => str_contains($m->envelope()->subject, '2026-10-20'))->render());
        $this->get('/a/'.$tuesdayLink)->assertOk();
        $this->post('/a/'.$tuesdayLink.'/approve')->assertOk();
        $this->assertSame('approved', $all[1]->refresh()->status);
        $this->assertSame('submitted', $all[3]->refresh()->status, 'el jueves sigue esperando');
    }

    public function test_an_approver_account_sees_each_part_with_its_period_and_only_its_lines(): void
    {
        $this->threeParts();
        $acct = User::create(['company_id' => $this->company->id, 'name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'password' => 'secret-password',
            'role' => 'approver', 'worker_type' => 'employee', 'capture_mode' => 'full_day', 'approver_profile_id' => $this->approver->id]);
        Sanctum::actingAs($acct);

        $list = $this->getJson('/api/approver/sheets?status=pending')->assertOk()->json();
        $this->assertCount(3, $list);
        $tuesday = collect($list)->firstWhere('part', 2);
        $this->assertTrue($tuesday['is_part']);
        $this->assertSame('2026-10-20', $tuesday['period_starting']);
        $this->assertSame('2026-10-20', $tuesday['period_ending']);
        $this->assertSame('2026-10-25', $tuesday['week_ending']);

        $d = $this->getJson('/api/approver/sheets/'.$tuesday['id'])->assertOk()->json();
        $this->assertCount(1, $d['entries']);
        $this->assertSame('2026-10-20', $d['entries'][0]['work_date']);
        $this->assertEquals(8.0, $d['total_hours']);

        // Devuelve el martes desde la cuenta: la lista de devueltas solo trae esa parte.
        $this->postJson('/api/approver/sheets/'.$tuesday['id'].'/return', ['version' => $d['version'], 'comment' => 'Falta detalle'])->assertOk();
        $this->assertCount(2, $this->getJson('/api/approver/sheets?status=pending')->json());
        $returned = $this->getJson('/api/approver/sheets?status=returned')->json();
        $this->assertCount(1, $returned);
        $this->assertSame(2, $returned[0]['part']);
    }

    public function test_the_review_list_of_the_owner_shows_the_part_of_each_sheet(): void
    {
        $this->threeParts();
        Sanctum::actingAs($this->owner);
        $res = $this->getJson('/api/review/sheets?status=pending')->assertOk()->json();
        $rows = $res['sheets'] ?? $res['items'] ?? $res['rows'] ?? $res;
        $parts = collect($rows)->pluck('part')->sort()->values()->all();
        $this->assertSame([1, 2, 3], $parts);
    }

    public function test_internal_supervisor_approves_and_returns_parts_one_by_one(): void
    {
        $sup = User::create(['company_id' => $this->company->id, 'name' => 'Sara Soto', 'email' => 'sara@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'full_day']);
        $svc = Service::where('project_id', $this->project->id)->firstOrFail();
        $svc->update(['approval_mode' => 'both']);
        $svc->internalApprovers()->sync([$sup->id]);
        $this->threeParts();

        $parts = Timesheet::orderBy('part')->get();
        $this->assertSame(['submitted', 'submitted', 'submitted'], $parts->pluck('status')->all());
        $this->assertSame(['internal', 'internal', 'internal'], $parts->pluck('stage')->all());
        $this->assertCount(0, $this->mails('mail.approval-request', 'jorge@cliente.test'), 'el externo no recibe nada hasta que el interno apruebe');

        Sanctum::actingAs($sup);
        $this->postJson('/api/internal/sheets/'.$parts[0]->id.'/approve', ['version' => 1])->assertOk();
        $this->postJson('/api/internal/sheets/'.$parts[1]->id.'/return', ['version' => 1, 'comment' => 'Mal el martes'])->assertOk();

        $parts = Timesheet::orderBy('part')->get();
        $this->assertSame(['external', null, 'internal'], $parts->pluck('stage')->all());
        $this->assertSame(['submitted', 'returned', 'submitted'], $parts->pluck('status')->all());
        $this->assertCount(1, $this->mails('mail.approval-request', 'jorge@cliente.test'), 'solo la parte aprobada pasa al externo');
        $this->assertStringContainsString('2026-10-19', $this->mails('mail.approval-request', 'jorge@cliente.test')->first()->envelope()->subject);
    }

    public function test_the_week_total_is_what_counts_for_the_weekly_hours_warning(): void
    {
        // Un empleado con límite de 20 h por semana: dos partes de 16 h cada una pasan del límite juntas, aunque ninguna lo pase sola.
        $this->worker->update(['worker_type' => 'employee', 'weekly_hours' => 20, 'capture_mode' => 'from_to']);
        $this->sendDay('2026-10-19');
        $this->sendDay('2026-10-20');
        $this->sendDay('2026-10-21');
        Sanctum::actingAs($this->worker);
        $w = $this->getJson('/api/sheets/week?date=2026-10-21')->assertOk()->json();
        $codes = collect($w['warnings'])->pluck('code')->all();
        $this->assertContains('over_weekly_hours', $codes, 'la advertencia mira toda la semana, no solo la parte');
    }
}
