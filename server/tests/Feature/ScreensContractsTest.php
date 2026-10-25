<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Approver;
use App\Models\Service;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Week;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** Contratos para las pantallas nuevas: número de empleado, tipo de hora, cuentas, invitaciones y listas de revisión. */
class ScreensContractsTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function employee(): User
    {
        $u = User::create([
            'company_id' => $this->company->id, 'name' => 'Beto Ríos', 'email' => 'beto@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to', 'weekly_hours' => 40,
            'approver_id' => $this->approver->id, 'employee_number' => 'E-77',
        ]);
        $u->projects()->attach($this->project->id);
        return $u;
    }

    // ---------- Número de empleado ----------

    public function test_employee_number_is_editable_unique_per_company_and_shown_everywhere(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $body = ['name' => 'Beto', 'email' => 'beto@cerevelo.test', 'worker_type' => 'employee', 'capture_mode' => 'from_to', 'employee_number' => 'E-1'];
        Mail::fake();
        $id = $this->postJson('/api/users', $body)->assertCreated()->assertJsonPath('employee_number', 'E-1')->json('id');
        $this->postJson('/api/users', ['email' => 'otro@cerevelo.test', 'name' => 'Otro'] + $body)->assertStatus(422)->assertJsonValidationErrors('employee_number');
        // Sin número: varios nulos conviven.
        $this->postJson('/api/users', ['employee_number' => null, 'email' => 'c@cerevelo.test', 'name' => 'C'] + $body)->assertCreated();
        $this->postJson('/api/users', ['employee_number' => '', 'email' => 'd@cerevelo.test', 'name' => 'D'] + $body)->assertCreated();
        // Se puede guardar a sí mismo sin chocar; y cambiarlo.
        $this->putJson('/api/users/'.$id, $body)->assertOk()->assertJsonPath('employee_number', 'E-1');
        $this->putJson('/api/users/'.$id, ['employee_number' => 'E-2'] + $body)->assertOk()->assertJsonPath('employee_number', 'E-2');
        $this->getJson('/api/users')->assertOk()->assertJsonFragment(['employee_number' => 'E-2']);
        $this->getJson('/api/accounts')->assertJsonFragment(['employee_number' => 'E-2']);

        Sanctum::actingAs(User::find($id));
        $this->getJson('/api/me')->assertOk()->assertJsonPath('employee_number', 'E-2');
    }

    // ---------- Tipo de hora ----------

    public function test_hour_type_for_employees_forces_non_billable_except_reg_and_ot(): void
    {
        $this->world();
        $emp = $this->employee();
        Sanctum::actingAs($emp);

        $reg = $this->postJson('/api/entries', $this->entry())->assertCreated()->assertJsonPath('hour_type', 'reg')->assertJsonPath('billable', true)->json();
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19', 'hour_type' => 'ot']))->assertCreated()
            ->assertJsonPath('hour_type', 'ot')->assertJsonPath('billable', true);
        foreach (['hol' => '2026-10-15', 'sick' => '2026-10-16', 'vac' => '2026-10-14', 'nb' => '2026-10-13'] as $t => $d) {
            $this->postJson('/api/entries', $this->entry(['work_date' => $d, 'hour_type' => $t, 'billable' => true]))->assertCreated()
                ->assertJsonPath('hour_type', $t)->assertJsonPath('billable', false);
        }
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-12', 'hour_type' => 'bogus']))->assertStatus(422);

        // Cambiar el tipo de una entrada existente; sin mandar hour_type se conserva.
        $this->putJson('/api/entries/'.$reg['id'], $this->entry(['hour_type' => 'vac']))->assertOk()->assertJsonPath('hour_type', 'vac')->assertJsonPath('billable', false);
        $this->putJson('/api/entries/'.$reg['id'], $this->entry())->assertOk()->assertJsonPath('hour_type', 'vac');
        $this->putJson('/api/entries/'.$reg['id'], $this->entry(['hour_type' => 'reg']))->assertOk()->assertJsonPath('billable', true);
        $this->getJson('/api/entries?from=2026-10-01&to=2026-10-31')->assertJsonPath('entries.0.hour_type', 'nb');
    }

    public function test_hour_type_is_ignored_for_consultants_and_works_in_sync(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry(['hour_type' => 'vac']))->assertCreated()->assertJsonPath('hour_type', 'reg')->assertJsonPath('billable', true);

        $emp = $this->employee();
        Sanctum::actingAs($emp);
        $uuid = (string) Str::uuid();
        $this->postJson('/api/sync', ['entries' => [$this->entry(['client_uuid' => $uuid, 'hour_type' => 'sick'])]])->assertOk()->assertJsonPath('results.0.status', 'ok');
        $this->assertDatabaseHas('time_entries', ['client_uuid' => $uuid, 'hour_type' => 'sick', 'billable' => false]);
        $bad = (string) Str::uuid();
        $this->postJson('/api/sync', ['entries' => [$this->entry(['client_uuid' => $bad, 'hour_type' => 'zzz'])]])->assertOk()->assertJsonPath('results.0.status', 'invalid');
    }

    public function test_sheet_payload_splits_billable_hours_and_carries_worker_and_approver(): void
    {
        $this->world();
        $emp = $this->employee();
        Sanctum::actingAs($emp);
        $this->postJson('/api/entries', $this->entry(['start_time' => '08:00', 'end_time' => '12:00']))->assertCreated();                 // 4 reg
        $this->postJson('/api/entries', $this->entry(['start_time' => '13:00', 'end_time' => '15:00', 'hour_type' => 'ot']))->assertCreated(); // 2 ot
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19', 'hour_type' => 'hol']))->assertCreated();             // 8 hol
        $w = $this->getJson('/api/sheets/week?date=2026-10-20')->assertOk()
            ->assertJsonPath('billable_hours', 6)->assertJsonPath('non_billable_hours', 8)->assertJsonPath('total_hours', 14)
            ->assertJsonPath('worker.name', 'Beto Ríos')->assertJsonPath('worker.worker_type', 'employee')->assertJsonPath('worker.employee_number', 'E-77')
            ->json();
        // Sin enviar todavía no hay aprobador asignado: se resuelve al enviar.
        $this->assertNull($w['approver']);
        $this->assertEqualsCanonicalizing(['reg', 'ot', 'hol'], collect($w['entries'])->pluck('hour_type')->all());
    }

    public function test_sheet_detail_has_entry_service_and_approver_after_submit(): void
    {
        Mail::fake();
        $this->world();
        $emp = $this->employee();
        Sanctum::actingAs($emp);
        $id = $this->postJson('/api/entries', $this->entry())->assertCreated()->json('timesheet_id');
        $this->postJson('/api/sheets/'.$id.'/submit')->assertOk()->assertJsonPath('status', 'submitted');
        $this->getJson('/api/sheets/'.$id)->assertOk()->assertJsonPath('approver.name', 'Jorge Ramos')->assertJsonPath('entries.0.service.name', Service::first()->name)
            ->assertJsonPath('entries.0.hour_type', 'reg')->assertJsonPath('worker.id', $emp->id);
    }

    public function test_ot_and_reg_still_bill_but_other_hour_types_do_not(): void
    {
        $this->world();
        $emp = $this->employee();
        Sanctum::actingAs($emp);
        $this->postJson('/api/entries', $this->entry())->assertCreated();
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19', 'hour_type' => 'ot']))->assertCreated();
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-16', 'hour_type' => 'vac']))->assertCreated();
        $this->assertSame(16.0, (float) TimeEntry::where('billable', true)->sum('hours'));
        $this->assertSame(8.0, (float) TimeEntry::where('billable', false)->sum('hours'));
    }

    // ---------- Cuentas e invitaciones ----------

    public function test_accounts_lists_everyone_with_filters(): void
    {
        $this->world();
        $this->employee();
        Approver::create(['company_id' => $this->company->id, 'name' => 'Árbol Sin Cuenta', 'email' => 'arbol@cliente.test']);
        Sanctum::actingAs($this->owner);

        // Jorge (aprobador de la base de pruebas, sin cuenta) también cuenta.
        $all = $this->getJson('/api/accounts')->assertOk()->assertJsonCount(5)->json();
        $this->assertSame(['Ana Torres', 'Árbol Sin Cuenta', 'Beto Ríos', 'Jorge Ramos', 'Luis'], collect($all)->pluck('name')->all());
        $this->assertSame(['id', 'user_id', 'approver_id', 'name', 'email', 'role', 'user_role', 'approver_kind', 'worker_type', 'employee_number', 'two_step', 'status', 'can_login'], array_keys($all[0]));
        $by = collect($all)->keyBy('email');
        $this->assertSame(['worker', 'consultant', 'active', true], [$by['ana@cerevelo.test']['role'], $by['ana@cerevelo.test']['worker_type'], $by['ana@cerevelo.test']['status'], $by['ana@cerevelo.test']['can_login']]);
        $this->assertSame(['backoffice', null], [$by['luis@cerevelo.test']['role'], $by['luis@cerevelo.test']['worker_type']]);
        $this->assertSame(['approver', null, false, false], [$by['arbol@cliente.test']['role'], $by['arbol@cliente.test']['user_id'], $by['arbol@cliente.test']['can_login'], $by['arbol@cliente.test']['two_step']]);
        $this->assertNotNull($by['arbol@cliente.test']['approver_id']);

        $this->getJson('/api/accounts?q=arbol')->assertJsonCount(1)->assertJsonPath('0.name', 'Árbol Sin Cuenta');
        $this->getJson('/api/accounts?q=RIOS')->assertJsonCount(1)->assertJsonPath('0.email', 'beto@cerevelo.test');
        $this->getJson('/api/accounts?role=backoffice')->assertJsonCount(1);
        $this->getJson('/api/accounts?role=approver')->assertJsonCount(2);
        $this->getJson('/api/accounts?role=worker&worker_type=employee')->assertJsonCount(1)->assertJsonPath('0.employee_number', 'E-77');

        Sanctum::actingAs($this->worker);
        $this->getJson('/api/accounts')->assertForbidden();
    }

    public function test_invitations_create_each_kind_of_account_and_email_them(): void
    {
        Mail::fake();
        $this->world();
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/invitations', ['name' => 'Nuevo W', 'email' => 'w@x.test', 'role' => 'worker'])->assertStatus(422)->assertJsonValidationErrors('worker_type');
        $w = $this->postJson('/api/invitations', ['name' => 'Nuevo W', 'email' => 'w@x.test', 'role' => 'worker', 'worker_type' => 'employee', 'employee_number' => 'E-9'])
            ->assertCreated()->assertJsonPath('emailed_to', 'w@x.test')->assertJsonPath('account.role', 'worker')->assertJsonPath('account.worker_type', 'employee')
            ->assertJsonPath('account.status', 'invited')->assertJsonPath('account.employee_number', 'E-9')->json('account');
        $this->assertSame('member', User::find($w['user_id'])->role);

        $b = $this->postJson('/api/invitations', ['name' => 'Back', 'email' => 'b@x.test', 'role' => 'backoffice'])->assertCreated()->assertJsonPath('account.role', 'backoffice')->json('account');
        $this->assertSame('owner', User::find($b['user_id'])->role);

        $a = $this->postJson('/api/invitations', ['name' => 'Apro', 'email' => 'a@x.test', 'role' => 'approver'])->assertCreated()
            ->assertJsonPath('account.role', 'approver')->assertJsonPath('account.can_login', true)->assertJsonPath('account.two_step', true)->json('account');
        $this->assertSame('approver', User::find($a['user_id'])->role);
        $this->assertDatabaseHas('approvers', ['email' => 'a@x.test', 'can_login' => true]);
        $this->assertNotNull($a['approver_id']);
        foreach (['w@x.test', 'b@x.test', 'a@x.test'] as $to) {
            Mail::assertSent(BrandedMail::class, fn ($m) => $m->hasTo($to) && $m->viewName === 'mail.account-invite');
        }

        // Aprobador ya existente sin cuenta: se reutiliza.
        $before = Approver::count();
        $this->postJson('/api/invitations', ['name' => 'Jorge', 'email' => 'jorge@cliente.test', 'role' => 'approver'])->assertCreated()->assertJsonPath('account.approver_id', $this->approver->id);
        $this->assertSame($before, Approver::count());

        // Correo repetido y número repetido.
        $this->postJson('/api/invitations', ['name' => 'X', 'email' => 'w@x.test', 'role' => 'backoffice'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/invitations', ['name' => 'X', 'email' => 'z@x.test', 'role' => 'worker', 'worker_type' => 'employee', 'employee_number' => 'E-9'])
            ->assertStatus(422)->assertJsonValidationErrors('employee_number');
        $this->assertSame(0, User::where('email', 'z@x.test')->count());

        Sanctum::actingAs($this->worker);
        $this->postJson('/api/invitations', ['name' => 'X', 'email' => 'q@x.test', 'role' => 'backoffice'])->assertForbidden();
    }

    // ---------- Listas de revisión ----------

    private function sheet(User $u, string $end, string $status, ?Approver $ap = null): Timesheet
    {
        $s = Timesheet::create(['company_id' => $this->company->id, 'user_id' => $u->id, 'week_ending' => $end,
            'status' => 'draft', 'version' => 1, 'approver_id' => ($ap ?? $this->approver)->id, 'submitted_at' => now(),
            'decided_at' => $status === 'submitted' ? null : now()]);
        $svc = Service::where('project_id', $this->project->id)->first();
        TimeEntry::create(['company_id' => $this->company->id, 'user_id' => $u->id, 'project_id' => $this->project->id, 'service_id' => $svc->id,
            'timesheet_id' => $s->id, 'work_date' => Week::starting($end), 'hours' => 8, 'billable' => true]);
        $s->recalcTotal();
        $s->update(['status' => $status]);
        return $s;
    }

    public function test_review_lists_filter_and_count_with_the_pending_always_rule(): void
    {
        $this->world();
        $emp = $this->employee();
        $old = $this->sheet($this->worker, '2026-08-02', 'submitted');            // pendiente fuera de fechas
        $p2 = $this->sheet($emp, '2026-10-18', 'submitted');
        $ap = $this->sheet($this->worker, '2026-10-11', 'approved');
        $ap2 = $this->sheet($this->worker, '2026-09-06', 'approved');             // fuera del periodo
        $ret = $this->sheet($emp, '2026-10-04', 'returned');
        $this->sheet($emp, '2026-10-25', 'draft');                               // los borradores no cuentan

        Sanctum::actingAs($this->owner);
        $r = $this->getJson('/api/review/sheets')->assertOk()->assertJsonStructure(['sheets', 'counts' => ['pending', 'approved', 'returned', 'all']]);
        $this->assertSame(['pending' => 2, 'approved' => 2, 'returned' => 1, 'all' => 5], $r->json('counts'));
        $this->assertSame([$old->id, $p2->id], collect($r->json('sheets'))->pluck('id')->all());   // más antiguas primero
        $first = $r->json('sheets.0');
        $this->assertSame(['consultant', null, ['P-1 · Planta A']], [$first['worker_type'], $first['employee_number'], $first['projects']]);
        $this->assertEquals([8, 0, 8], [$first['billable_hours'], $first['non_billable_hours'], $first['total_hours']]);
        $this->assertSame('E-77', $r->json('sheets.1.employee_number'));
        $this->assertSame('Jorge Ramos', $r->json('sheets.0.approver.name'));

        // Periodo: pendiente sigue completo; aprobadas/devueltas/todas se recortan; en Todas lo pendiente se queda.
        $q = '?from=2026-10-01&to=2026-10-31';
        $r = $this->getJson('/api/review/sheets'.$q)->assertOk();
        $this->assertSame(['pending' => 2, 'approved' => 1, 'returned' => 1, 'all' => 4], $r->json('counts'));
        $this->assertCount(2, $r->json('sheets'));
        $this->assertSame([$ap->id], collect($this->getJson('/api/review/sheets'.$q.'&status=approved')->json('sheets'))->pluck('id')->all());
        $this->assertSame([$ret->id], collect($this->getJson('/api/review/sheets'.$q.'&status=returned')->json('sheets'))->pluck('id')->all());
        $all = collect($this->getJson('/api/review/sheets'.$q.'&status=all')->json('sheets'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$old->id, $p2->id, $ap->id, $ret->id], $all);
        $this->assertSame([$old->id, $p2->id], array_slice($all, 0, 2));
        $this->assertNotContains($ap2->id, $all);

        // q (sin tildes) y type recortan también los conteos, sin tocar status.
        $r = $this->getJson('/api/review/sheets?q=rios&status=returned')->assertOk();
        $this->assertSame(['pending' => 1, 'approved' => 0, 'returned' => 1, 'all' => 2], $r->json('counts'));
        $r = $this->getJson('/api/review/sheets?type=consultant');
        $this->assertSame(['pending' => 1, 'approved' => 2, 'returned' => 0, 'all' => 3], $r->json('counts'));
        $this->getJson('/api/review/sheets?status=nope')->assertStatus(422);

        // Los endpoints viejos siguen igual.
        $this->getJson('/api/sheets-pending')->assertOk()->assertJsonCount(2);

        Sanctum::actingAs($this->worker);
        $this->getJson('/api/review/sheets')->assertForbidden();
    }

    public function test_approver_review_only_shows_their_sheets(): void
    {
        Mail::fake();
        $this->world();
        $emp = $this->employee();
        $acct = User::create(['company_id' => $this->company->id, 'name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'password' => 'x-secret-pass',
            'role' => 'approver', 'worker_type' => 'employee', 'capture_mode' => 'full_day', 'approver_profile_id' => $this->approver->id]);
        $other = Approver::create(['company_id' => $this->company->id, 'name' => 'Otro', 'email' => 'otro@cliente.test']);
        $mine = $this->sheet($emp, '2026-10-18', 'submitted');
        $this->sheet($emp, '2026-10-11', 'approved');
        $this->sheet($this->worker, '2026-10-04', 'submitted', $other);

        Sanctum::actingAs($acct);
        $r = $this->getJson('/api/approver/review')->assertOk();
        $this->assertSame(['pending' => 1, 'approved' => 1, 'returned' => 0, 'all' => 2], $r->json('counts'));
        $this->assertSame([$mine->id], collect($r->json('sheets'))->pluck('id')->all());
        $this->assertSame(['employee', 'E-77', ['P-1 · Planta A']], [$r->json('sheets.0.worker_type'), $r->json('sheets.0.employee_number'), $r->json('sheets.0.projects')]);
        $this->assertEquals(8, $r->json('sheets.0.total_hours'));
        $this->assertArrayHasKey('needs_code', $r->json('sheets.0'));
        $this->getJson('/api/approver/review?status=all&q=beto&type=employee')->assertJsonCount(2, 'sheets');
        $this->getJson('/api/approver/review?status=all&type=consultant')->assertJsonCount(0, 'sheets');
        $this->getJson('/api/approver/sheets')->assertJsonCount(1);          // el endpoint viejo no cambia

        $this->getJson('/api/approver/sheets/'.$mine->id)->assertOk()->assertJsonPath('worker.name', 'Beto Ríos')->assertJsonPath('approver.name', 'Jorge Ramos')
            ->assertJsonPath('entries.0.service.id', Service::first()->id)->assertJsonPath('entries.0.hour_type', 'reg');

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/approver/review')->assertForbidden();
    }
}
