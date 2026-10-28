<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * Envío por partes: la semana tiene varias hojas (partes), cada una con sus días corridos; las notas por línea y la nota del envío.
 * Hoy en la prueba: miércoles 2026-10-21. La semana va del lunes 2026-10-19 al domingo 2026-10-25.
 */
class PartialSubmissionTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function enable(bool $on = true): void
    {
        $this->company->update(['rules' => ['week_starts_on' => 1, 'partial_submission' => $on]]);
    }

    private function hours(string $date, ?string $note = null, array $over = []): array
    {
        return $this->postJson('/api/entries', $this->entry(['work_date' => $date] + ($note !== null ? ['note' => $note] : []) + $over))->assertCreated()->json();
    }

    private function noApproval(): void
    {
        Service::where('project_id', $this->project->id)->update(['approval_mode' => 'none']);
    }

    public function test_it_is_off_by_default(): void
    {
        $this->world();
        $this->noApproval();
        Sanctum::actingAs($this->worker);
        $this->hours('2026-10-19');
        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-19'])->assertStatus(422);
    }

    public function test_days_are_sent_in_order_and_each_block_is_its_own_sheet(): void
    {
        $this->world();
        $this->noApproval();
        $this->enable();
        Sanctum::actingAs($this->worker);
        $this->hours('2026-10-19');
        $this->hours('2026-10-20');
        $this->hours('2026-10-21');

        $r = $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-20'])->assertOk()->json();
        // Lunes y martes pasaron a una hoja (parte 1) con su periodo; el miércoles sigue abierto en otra.
        $sent = Timesheet::where('user_id', $this->worker->id)->where('status', Timesheet::APPROVED)->get();
        $this->assertCount(1, $sent);
        $this->assertSame('2026-10-19', $sent[0]->periodStarting());
        $this->assertSame('2026-10-20', $sent[0]->periodEnding());
        $this->assertSame(16.0, (float) $sent[0]->total_hours);
        $days = collect($r['days'])->keyBy('date');
        $this->assertTrue($days['2026-10-19']['locked']);
        $this->assertTrue($days['2026-10-21']['open']);
        $this->assertEquals(8.0, Timesheet::where('status', Timesheet::DRAFT)->value('total_hours'), 'el borrador solo cuenta lo que le queda');

        // El miércoles es otra parte.
        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-21'])->assertOk();
        $parts = Timesheet::where('user_id', $this->worker->id)->orderBy('part')->get();
        $this->assertCount(2, $parts);
        $this->assertSame([1, 2], $parts->pluck('part')->all());
        $this->assertSame('2026-10-21', $parts[1]->periodStarting());
        $this->assertSame('2026-10-21', $parts[1]->periodEnding());
        $this->assertSame(0, Timesheet::where('status', Timesheet::DRAFT)->count(), 'no queda un borrador vacío');
    }

    public function test_days_without_hours_are_included_and_stay_closed(): void
    {
        $this->world();
        $this->noApproval();
        $this->enable();
        Sanctum::actingAs($this->worker);
        $this->hours('2026-10-19');
        $this->hours('2026-10-21');
        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-21'])->assertOk();

        $sheet = Timesheet::where('user_id', $this->worker->id)->firstOrFail();
        $this->assertSame(1, Timesheet::count(), 'lunes a miércoles seguidos: una sola hoja');
        $this->assertSame('2026-10-19', $sheet->periodStarting());
        $this->assertSame('2026-10-21', $sheet->periodEnding());
        // El martes (en 0) quedó cerrado: ya no se le pueden agregar horas, porque la hoja está aprobada.
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-20']))->assertStatus(423);
    }

    public function test_the_returned_day_is_resent_in_its_own_sheet_together_with_the_new_day(): void
    {
        $this->world();
        $this->enable();
        $this->clientWithApprover($this->project);
        Sanctum::actingAs($this->worker);
        // Lunes, martes y miércoles se envían por separado.
        foreach (['2026-10-19', '2026-10-20', '2026-10-21'] as $d) {
            $this->hours($d);
            $this->postJson('/api/sheets/submit-days', ['through' => $d])->assertOk();
        }
        $parts = Timesheet::where('user_id', $this->worker->id)->orderBy('part')->get();
        $this->assertCount(3, $parts);
        $this->assertSame(['2026-10-19', '2026-10-20', '2026-10-21'], $parts->map->periodStarting()->all());
        $tuesday = $parts[1];

        // El aprobador devuelve el martes.
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/sheets/{$tuesday->id}/return", ['comment' => 'Revisa el martes'])->assertOk();

        // Pasa el jueves y la persona manda el jueves y el martes devuelto de una vez, aunque el miércoles quede en medio.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-22 10:00:00', 'America/Puerto_Rico'));
        Sanctum::actingAs($this->worker);
        $this->hours('2026-10-22');
        $this->putJson("/api/entries/".TimeEntry::where('work_date', '2026-10-20')->value('id'), ['project_id' => $this->project->id, 'work_date' => '2026-10-20',
            'start_time' => '08:00', 'end_time' => '15:00'])->assertOk();   // corrige el martes
        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-22'])->assertOk();

        $all = Timesheet::where('user_id', $this->worker->id)->orderBy('part')->get();
        $this->assertCount(4, $all, 'el martes se reenvía en su misma hoja y el jueves es una hoja nueva');
        $this->assertSame($tuesday->id, $all[1]->id);
        $this->assertSame(Timesheet::SUBMITTED, $all[1]->status);
        $this->assertSame(2, (int) $all[1]->version, 'reenviada: versión 2');
        $this->assertSame('2026-10-22', $all[3]->periodStarting());
        $this->assertSame(Timesheet::SUBMITTED, $all[3]->status);
        // El lunes y el miércoles no se tocaron.
        $this->assertSame(1, (int) $all[0]->refresh()->version);
        $this->assertSame(1, (int) $all[2]->refresh()->version);
    }

    public function test_a_day_cannot_be_skipped_the_open_days_before_it_go_with_it(): void
    {
        $this->world();
        $this->noApproval();
        $this->enable();
        Sanctum::actingAs($this->worker);
        $this->hours('2026-10-19');
        $this->hours('2026-10-21');
        // Mandar hasta el miércoles incluye el lunes y el martes abiertos: nunca se envía el miércoles solo.
        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-21'])->assertOk();
        $this->assertSame('2026-10-19', Timesheet::firstOrFail()->periodStarting());
    }

    public function test_future_days_and_empty_ranges_are_refused(): void
    {
        $this->world();
        $this->noApproval();
        $this->enable();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-22'])->assertStatus(422);   // jueves: futuro
        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-21'])->assertStatus(422);   // no hay horas
    }

    public function test_the_week_screen_gets_all_parts_lines_and_the_state_of_each_day(): void
    {
        $this->world();
        $this->noApproval();
        $this->enable();
        Sanctum::actingAs($this->worker);
        $this->hours('2026-10-19');
        $this->hours('2026-10-21');
        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-19'])->assertOk();

        $w = $this->getJson('/api/sheets/week?date=2026-10-21')->assertOk()->json();
        $this->assertTrue($w['partial_submission']);
        $this->assertCount(2, $w['entries'], 'las líneas de todas las partes');
        $this->assertCount(2, $w['parts']);
        $this->assertCount(7, $w['days']);
        $this->assertSame('approved', $w['days'][0]['status']);
        $this->assertTrue($w['days'][2]['open']);
        $this->assertEquals(16.0, $w['week_total_hours']);
    }

    public function test_the_whole_week_send_still_works_and_covers_the_week(): void
    {
        $this->world();
        $this->noApproval();
        Sanctum::actingAs($this->worker);
        $e = $this->hours('2026-10-19');
        $this->postJson("/api/sheets/{$e['timesheet_id']}/submit")->assertOk();
        $sheet = Timesheet::firstOrFail();
        $this->assertNull($sheet->period_start);
        $this->assertSame('2026-10-19', $sheet->periodStarting());
        $this->assertSame('2026-10-25', $sheet->periodEnding());
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-20']))->assertStatus(423);
    }

    public function test_the_draft_submit_button_sends_the_open_blocks_when_the_week_already_has_parts(): void
    {
        $this->world();
        $this->noApproval();
        $this->enable();
        Sanctum::actingAs($this->worker);
        $this->hours('2026-10-19');
        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-19'])->assertOk();
        $e = $this->hours('2026-10-20');
        $this->postJson("/api/sheets/{$e['timesheet_id']}/submit")->assertOk();
        $this->assertSame(2, Timesheet::where('status', Timesheet::APPROVED)->count());
    }

    public function test_the_submission_note_fills_empty_lines_and_is_removed_when_the_sheet_is_returned_and_resent(): void
    {
        $this->world();
        $this->clientWithApprover($this->project);
        Sanctum::actingAs($this->worker);
        $a = $this->hours('2026-10-19', 'Instalé el equipo');
        $b = $this->hours('2026-10-20');
        $this->postJson("/api/sheets/{$a['timesheet_id']}/submit", ['note' => 'Semana de instalación'])->assertOk();

        $this->assertSame('Instalé el equipo', TimeEntry::find($a['id'])->note, 'la nota propia no se pisa');
        $copied = TimeEntry::find($b['id']);
        $this->assertSame('Semana de instalación', $copied->note);
        $this->assertTrue($copied->note_from_submission);
        $this->assertSame('Semana de instalación', Timesheet::first()->submission_note);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$a['timesheet_id'].'/return', ['comment' => 'Falta detalle'])->assertOk();
        Sanctum::actingAs($this->worker);
        $this->postJson("/api/sheets/{$a['timesheet_id']}/submit", ['note' => 'Otra nota'])->assertOk();
        $this->assertSame('Otra nota', TimeEntry::find($b['id'])->note);
        $this->assertSame('Instalé el equipo', TimeEntry::find($a['id'])->note);
    }

    public function test_a_service_that_requires_a_note_blocks_the_send_until_each_line_has_one_or_the_submission_has(): void
    {
        $this->world();
        $this->clientWithApprover($this->project);
        Service::where('project_id', $this->project->id)->update(['require_note' => true]);
        Sanctum::actingAs($this->worker);
        $a = $this->hours('2026-10-19', 'Hecho');
        $this->hours('2026-10-20');

        $r = $this->postJson("/api/sheets/{$a['timesheet_id']}/submit")->assertStatus(422);
        $this->assertStringContainsString('10-20', $r->json('errors.note.0'));
        $this->assertSame(Timesheet::DRAFT, Timesheet::first()->status, 'no se envió nada');

        $this->postJson("/api/sheets/{$a['timesheet_id']}/submit", ['note' => 'Trabajo de la semana'])->assertOk();
        $this->assertSame(Timesheet::SUBMITTED, Timesheet::first()->status);
    }

    public function test_editing_a_copied_note_makes_it_the_persons_own(): void
    {
        $this->world();
        $this->clientWithApprover($this->project);
        Sanctum::actingAs($this->worker);
        $a = $this->hours('2026-10-19');
        $this->postJson("/api/sheets/{$a['timesheet_id']}/submit", ['note' => 'Del envío'])->assertOk();
        $id = $a['id'];
        $this->assertTrue(TimeEntry::find($id)->note_from_submission);
        Timesheet::whereKey($a['timesheet_id'])->update(['status' => 'returned']);   // enviada no se toca: devuelta, sí
        // Si manda de vuelta la misma nota, sigue marcada; si la cambia, es suya.
        $this->putJson("/api/entries/$id", $this->entry(['note' => 'Del envío']))->assertOk();
        $this->assertTrue(TimeEntry::find($id)->note_from_submission);
        $this->putJson("/api/entries/$id", $this->entry(['note' => 'Mi nota']))->assertOk();
        $this->assertFalse(TimeEntry::find($id)->note_from_submission);
    }

    public function test_two_parts_of_the_same_week_are_approved_and_invoiced_apart(): void
    {
        $this->world();
        $this->noApproval();
        $this->enable();
        Sanctum::actingAs($this->worker);
        $this->hours('2026-10-19');
        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-19'])->assertOk();
        $this->hours('2026-10-21');
        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-21'])->assertOk();
        $this->assertSame(2, Timesheet::where('user_id', $this->worker->id)->where('week_ending', '2026-10-25')->count());
        $this->assertSame([8.0, 8.0], Timesheet::orderBy('part')->get()->map(fn ($s) => (float) $s->total_hours)->all());
    }

    public function test_submitting_a_week_that_is_still_running_sends_only_through_today_when_parts_are_allowed(): void
    {
        $this->world();                                               // hoy: miércoles 2026-10-21; la semana termina el domingo 25
        $this->company->update(['rules' => ['week_starts_on' => 1, 'partial_submission' => true]]);
        $this->clientWithApprover($this->project);
        Sanctum::actingAs($this->worker);
        $a = $this->hours('2026-10-19');
        $this->postJson("/api/sheets/{$a['timesheet_id']}/submit")->assertOk();

        $s = Timesheet::find(TimeEntry::find($a['id'])->timesheet_id);          // la hoja de la parte (la del borrador original se vació)
        $this->assertSame('2026-10-19', $s->period_start->toDateString());
        $this->assertSame('2026-10-21', $s->period_end->toDateString());          // hasta hoy, no hasta el domingo
        $this->assertSame(1, (int) $s->part);
        $days = $this->getJson('/api/sheets/week?date=2026-10-21')->assertOk()->json('days');
        $this->assertSame([true, true, true, false, false, false, false], array_column($days, 'locked'));   // lo que falta de la semana sigue abierto
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-21']))->assertStatus(423);     // lo enviado no se toca
    }

    public function test_without_parts_allowed_a_running_week_is_sent_whole_as_always(): void
    {
        $this->world();
        $this->clientWithApprover($this->project);
        Sanctum::actingAs($this->worker);
        $a = $this->hours('2026-10-19');
        $this->postJson("/api/sheets/{$a['timesheet_id']}/submit")->assertOk();
        $this->assertNull(Timesheet::find(TimeEntry::find($a['id'])->timesheet_id)->period_start);
    }

    public function test_resubmitting_a_returned_whole_week_through_a_day_makes_it_a_part_and_leaves_the_rest_open(): void
    {
        $this->world();                                               // hoy: miércoles 2026-10-21; la semana va del lunes 19 al domingo 25
        $this->clientWithApprover($this->project);
        Sanctum::actingAs($this->worker);
        $mon = $this->hours('2026-10-19');
        $this->hours('2026-10-20');
        $wed = $this->hours('2026-10-21');
        $sheetId = $mon['timesheet_id'];
        $this->postJson("/api/sheets/$sheetId/submit")->assertOk();                    // sin partes: se envió la semana entera
        $this->assertNull(Timesheet::find($sheetId)->period_start);
        app(\App\Services\SheetService::class)->return(Timesheet::find($sheetId), $this->approver, null, 'link', 'Reenvíala por partes.', 1);
        $this->company->update(['rules' => ['week_starts_on' => 1, 'partial_submission' => true]]);
        Sanctum::actingAs($this->worker->fresh());

        $this->postJson('/api/sheets/submit-days', ['through' => '2026-10-20'])->assertOk();
        $s = Timesheet::find($sheetId);
        $this->assertSame('submitted', $s->status);
        $this->assertSame('2026-10-19', $s->period_start->toDateString());
        $this->assertSame('2026-10-20', $s->period_end->toDateString());             // ahora es una parte hasta el martes
        $this->assertSame($sheetId, TimeEntry::find($mon['id'])->timesheet_id);
        $later = Timesheet::find(TimeEntry::find($wed['id'])->timesheet_id);
        $this->assertNotSame($sheetId, $later->id);                                    // lo del miércoles pasó a un borrador
        $this->assertSame('draft', $later->status);
        $days = $this->getJson('/api/sheets/week?date=2026-10-21')->assertOk()->json('days');
        $this->assertSame([true, true, false, false, false, false, false], array_column($days, 'locked'));
        $this->putJson('/api/entries/'.$wed['id'], $this->entry(['work_date' => '2026-10-21', 'end_time' => '15:00']))->assertOk();   // el día abierto se sigue editando
    }
}
