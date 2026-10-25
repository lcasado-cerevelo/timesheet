<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\ApprovalToken;
use App\Models\Timesheet;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\SheetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

class SheetFlowTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function submitted(): array
    {
        Mail::fake();
        $this->world();
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $sheet = Timesheet::find($e['timesheet_id']);
        $this->postJson('/api/sheets/'.$sheet->id.'/submit')->assertOk()->assertJsonPath('status', 'submitted');
        return [$sheet->refresh(), $e];
    }

    private function lastMailHtml(string $view): string
    {
        $sent = Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === $view)->last();
        return $sent->render();
    }

    public function test_submit_emails_the_approver_with_hours_but_no_rates(): void
    {
        [$sheet] = $this->submitted();
        Mail::assertSent(BrandedMail::class, fn ($m) => $m->hasTo('jorge@cliente.test') && $m->viewName === 'mail.approval-request');
        $html = $this->lastMailHtml('mail.approval-request');
        $this->assertStringContainsString('8.00 h', $html);
        $this->assertStringContainsString('Revisar y decidir', $html);
        $this->assertStringNotContainsString('50.00', $html);   // la tarifa no viaja en el email
        $this->assertStringContainsString('#1f4e79', $html);    // color de la marca
    }

    public function test_opening_the_link_does_not_approve_and_the_button_does(): void
    {
        [$sheet] = $this->submitted();
        $plain = $this->tokenFromMail($this->lastMailHtml('mail.approval-request'));
        $this->assertNotSame('', $plain);

        $this->get('/a/'.$plain)->assertOk()->assertSee('Aprobar')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame('submitted', $sheet->fresh()->status);

        $this->post('/a/'.$plain.'/approve')->assertOk()->assertSee('Hoja aprobada');
        $this->assertSame('approved', $sheet->fresh()->status);
        $this->assertSame('link', $sheet->fresh()->decided_via);
        $this->get('/a/'.$plain)->assertStatus(410);          // el enlace ya no sirve
    }

    public function test_approved_sheet_is_locked_for_update_delete_and_new_entries(): void
    {
        [$sheet, $e] = $this->submitted();
        $plain = $this->tokenFromMail($this->lastMailHtml('mail.approval-request'));
        $this->post('/a/'.$plain.'/approve');

        $this->putJson('/api/entries/'.$e['id'], $this->entry(['end_time' => '17:00']))->assertStatus(423);
        $this->deleteJson('/api/entries/'.$e['id'])->assertStatus(423);
        $this->postJson('/api/entries', $this->entry(['start_time' => '17:00', 'end_time' => '18:00']))->assertStatus(423);
        $this->assertEquals(8.0, (float) $sheet->fresh()->total_hours);

        // Segunda barrera: aunque se llame al modelo directo.
        $this->expectException(\App\Exceptions\SheetLockedException::class);
        TimeEntry::find($e['id'])->update(['hours' => 1]);
    }

    public function test_change_while_submitted_bumps_version_invalidates_link_and_sends_a_changed_notice(): void
    {
        [$sheet, $e] = $this->submitted();
        $old = $this->tokenFromMail($this->lastMailHtml('mail.approval-request'));
        $this->assertSame(1, $sheet->version);

        $this->putJson('/api/entries/'.$e['id'], $this->entry(['end_time' => '15:00']))->assertOk();
        $this->assertSame(2, $sheet->fresh()->version);
        $this->assertSame('submitted', $sheet->fresh()->status);
        $this->get('/a/'.$old)->assertStatus(410);                         // enlace viejo anulado

        // Aún no se avisa: hay una espera de 10 minutos para no mandar un email por cada línea.
        Mail::assertSentCount(1);
        $this->artisan('approvals:notify-changes')->assertSuccessful();
        Mail::assertSentCount(1);

        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('approvals:notify-changes')->assertSuccessful();
        Mail::assertSentCount(2);
        $html = $this->lastMailHtml('mail.approval-request');
        $this->assertStringContainsString('Esta hoja cambió', $html);
        $this->assertStringContainsString('Cambiada', $html);
        $new = $this->tokenFromMail($html);
        $this->assertNotSame($old, $new);
        $this->get('/a/'.$new)->assertOk();
    }

    public function test_several_quick_edits_produce_a_single_notice(): void
    {
        [$sheet, $e] = $this->submitted();
        $this->putJson('/api/entries/'.$e['id'], $this->entry(['end_time' => '15:00']))->assertOk();
        $this->putJson('/api/entries/'.$e['id'], $this->entry(['end_time' => '14:00']))->assertOk();
        $this->postJson('/api/entries', $this->entry(['start_time' => '17:00', 'end_time' => '18:00']))->assertCreated();
        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('approvals:notify-changes');
        $this->artisan('approvals:notify-changes');
        Mail::assertSentCount(2);   // el original + un solo aviso de cambio
    }

    public function test_return_needs_a_comment_then_worker_fixes_and_resubmits(): void
    {
        [$sheet, $e] = $this->submitted();
        $plain = $this->tokenFromMail($this->lastMailHtml('mail.approval-request'));

        $this->post('/a/'.$plain.'/return', ['comment' => ''])->assertSessionHasErrors('comment');
        $this->post('/a/'.$plain.'/return', ['comment' => 'Falta el viernes'])->assertOk()->assertSee('Hoja devuelta');
        $this->assertSame('returned', $sheet->fresh()->status);
        Mail::assertSent(BrandedMail::class, fn ($m) => $m->hasTo('ana@cerevelo.test') && $m->viewName === 'mail.sheet-decision');

        // La persona ve el comentario, corrige y reenvía desde la app (también vale desde el móvil).
        $this->getJson('/api/sheets/'.$sheet->id)->assertJsonPath('return_comment', 'Falta el viernes');
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19']))->assertCreated();
        $this->assertSame('returned', $sheet->fresh()->status);
        $this->postJson('/api/sheets/'.$sheet->id.'/submit')->assertOk()->assertJsonPath('status', 'submitted');
        $this->assertSame(2, $sheet->fresh()->version);
        $html = $this->lastMailHtml('mail.approval-request');
        $this->assertStringContainsString('corregida', $html);
        $this->post('/a/'.$this->tokenFromMail($html).'/approve')->assertOk();
        $this->assertSame('approved', $sheet->fresh()->status);
    }

    public function test_stale_version_cannot_be_approved(): void
    {
        [$sheet, $e] = $this->submitted();
        $this->putJson('/api/entries/'.$e['id'], $this->entry(['end_time' => '15:00']))->assertOk();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SheetService::class)->approve($sheet->fresh(), $this->approver, null, 'link', 1);
    }

    public function test_second_code_is_required_above_the_hours_limit(): void
    {
        [$sheet] = $this->submitted();
        $this->company->update(['rules' => ['second_approval_hours' => 4]]);
        $plain = $this->tokenFromMail($this->lastMailHtml('mail.approval-request'));

        $this->get('/a/'.$plain)->assertOk()->assertSee('Verifica que eres tú');
        $this->post('/a/'.$plain.'/approve')->assertSessionHasErrors('code');
        $this->post('/a/'.$plain.'/code')->assertRedirect();
        preg_match('/>(\d{6})</', $this->lastMailHtml('mail.approval-code'), $m);
        $this->post('/a/'.$plain.'/verify', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/a/'.$plain.'/verify', ['code' => $m[1]])->assertRedirect();
        $this->post('/a/'.$plain.'/approve')->assertOk()->assertSee('Hoja aprobada');
    }

    public function test_owner_without_another_approver_self_approves_and_it_is_recorded(): void
    {
        Mail::fake();
        $this->world();
        $this->owner->update(['approver_id' => null]);
        Sanctum::actingAs($this->owner);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame('self', Timesheet::find($e['timesheet_id'])->decided_via);
        Mail::assertNothingSent();
    }

    public function test_member_without_an_approver_cannot_submit(): void
    {
        Mail::fake();
        $this->world(['approver_id' => null]);
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertStatus(422)->assertJsonValidationErrors('approver');
    }

    public function test_empty_sheet_cannot_be_submitted_and_nobody_approves_their_own_sheet(): void
    {
        Mail::fake();
        $this->world();
        Sanctum::actingAs($this->worker);
        $sheet = app(SheetService::class)->forWeek($this->worker, '2026-10-20');
        $this->postJson('/api/sheets/'.$sheet->id.'/submit')->assertStatus(422);

        $this->postJson('/api/entries', $this->entry())->assertCreated();
        $this->postJson('/api/sheets/'.$sheet->id.'/submit')->assertOk();
        $this->postJson('/api/sheets/'.$sheet->id.'/approve')->assertForbidden();   // no es dueño
        $this->owner->approver_id = null;
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$sheet->id.'/approve')->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_batch_reminder_after_seven_days_requires_a_code_and_skips_changed_or_over_limit(): void
    {
        [$s1, $e1] = $this->submitted();
        $w2 = User::create(['company_id' => $this->company->id, 'name' => 'Pedro', 'email' => 'p@x.test', 'password' => 'secret-password',
            'worker_type' => 'consultant', 'capture_mode' => 'from_to', 'approver_id' => $this->approver->id]);
        $w2->projects()->attach($this->project->id);
        Sanctum::actingAs($w2);
        $e2 = $this->postJson('/api/entries', $this->entry(['start_time' => '06:00', 'end_time' => '17:00']))->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e2['timesheet_id'].'/submit')->assertOk();

        // Antes de 7 días no hay recordatorio.
        $this->artisan('approvals:remind');
        Mail::assertNotSent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.pending-digest');

        Carbon::setTestNow(now()->addDays(8));
        $this->artisan('approvals:remind')->assertSuccessful();
        $this->assertCount(1, Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.pending-digest'));
        $html = $this->lastMailHtml('mail.pending-digest');
        $this->assertStringContainsString('2', $html);
        $plain = $this->tokenFromMail($html, 'b');
        $this->assertNotSame('', $plain);

        // Sin código no se ve nada.
        $this->get('/b/'.$plain)->assertOk()->assertSee('Verifica que eres tú')->assertDontSee('Ana Torres');
        $this->post('/b/'.$plain.'/approve-all', ['items' => [$s1->id.':1']]);
        $this->assertSame('submitted', $s1->fresh()->status);

        $this->post('/b/'.$plain.'/code');
        preg_match('/>(\d{6})</', $this->lastMailHtml('mail.approval-code'), $m);
        $this->post('/b/'.$plain.'/verify', ['code' => $m[1]]);
        $this->get('/b/'.$plain)->assertOk()->assertSee('Ana Torres')->assertSee('Pedro')->assertSee('Aprobar todas (2)');

        // Un límite: Pedro (11 h) queda fuera del bloque; Ana cambia después de verse.
        $this->company->update(['rules' => ['second_approval_hours' => 9]]);
        $this->actingAs($this->worker);
        Sanctum::actingAs($this->worker);
        $this->putJson('/api/entries/'.$e1['id'], $this->entry(['end_time' => '15:00']))->assertOk();   // Ana: versión 2
        $this->post('/b/'.$plain.'/approve-all', ['items' => [$s1->id.':1', $s2 = $e2['timesheet_id'].':1']])
            ->assertOk()->assertSee('Aprobadas: 0');
        $this->assertSame('submitted', $s1->fresh()->status);
        $this->assertSame('submitted', Timesheet::find($e2['timesheet_id'])->status);

        // Sin límite, y con la versión vigente, sí se aprueba en bloque.
        $this->company->update(['rules' => ['second_approval_hours' => null]]);
        $this->post('/b/'.$plain.'/approve-all', ['items' => [$s1->id.':'.$s1->fresh()->version, $e2['timesheet_id'].':1']])
            ->assertOk()->assertSee('Aprobadas: 2');
        $this->assertSame('approved', $s1->fresh()->status);
        $this->assertSame('batch_ok', 'batch_ok');
    }

    public function test_sync_is_idempotent_and_reports_locked_entries(): void
    {
        Mail::fake();
        $this->world();
        Sanctum::actingAs($this->worker);
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $payload = ['entries' => [$this->entry(['client_uuid' => $uuid])]];

        $this->postJson('/api/sync', $payload)->assertOk()->assertJsonPath('results.0.status', 'ok');
        $this->postJson('/api/sync', $payload)->assertOk()->assertJsonPath('results.0.status', 'ok');
        $this->assertSame(1, TimeEntry::where('client_uuid', $uuid)->count());

        $sheet = Timesheet::first();
        $this->postJson('/api/sheets/'.$sheet->id.'/submit');
        $this->owner->approver_id = null;
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$sheet->id.'/approve')->assertOk();

        Sanctum::actingAs($this->worker);
        $edit = ['entries' => [$this->entry(['client_uuid' => $uuid, 'end_time' => '17:00'])]];
        $this->postJson('/api/sync', $edit)->assertOk()->assertJsonPath('results.0.status', 'locked');
        $this->postJson('/api/sync', ['entries' => [['client_uuid' => $uuid, 'deleted' => true]]])
            ->assertOk()->assertJsonPath('results.0.status', 'locked');
        $this->assertSame(1, TimeEntry::where('client_uuid', $uuid)->count());
    }

    public function test_employee_over_weekly_hours_gets_a_warning_only(): void
    {
        Mail::fake();
        $this->world(['worker_type' => 'employee', 'weekly_hours' => 10, 'capture_mode' => 'full_day']);
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', ['project_id' => $this->project->id, 'work_date' => '2026-10-19', 'hours' => 8])->assertCreated();
        $e = $this->postJson('/api/entries', ['project_id' => $this->project->id, 'work_date' => '2026-10-20', 'hours' => 8])->assertCreated()->json();
        $r = $this->getJson('/api/sheets/'.$e['timesheet_id'])->assertOk();
        $r->assertJsonPath('warnings.0.code', 'over_weekly_hours');
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();   // no bloquea
        $this->assertStringContainsString('Pasa de 10', $this->lastMailHtml('mail.approval-request'));
    }

    public function test_other_company_cannot_touch_the_sheet(): void
    {
        [$sheet] = $this->submitted();
        $c2 = \App\Models\Company::create(['name' => 'Otra', 'slug' => 'otra']);
        $u2 = User::create(['company_id' => $c2->id, 'name' => 'Otro', 'email' => 'o@o.test', 'password' => 'secret-password', 'role' => 'owner']);
        Sanctum::actingAs($u2);
        $this->postJson('/api/sheets/'.$sheet->id.'/approve')->assertNotFound();
        $this->getJson('/api/sheets/'.$sheet->id)->assertForbidden();
        $this->assertSame('submitted', $sheet->fresh()->status);
    }
}
