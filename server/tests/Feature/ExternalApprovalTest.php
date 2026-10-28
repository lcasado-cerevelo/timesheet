<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Approver;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Service;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\SheetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * Aprobadores externos ligados a clientes y escogidos por servicio. Una hoja se reparte en partes (una por servicio);
 * cada aprobador ve y decide solo las suyas.
 */
class ExternalApprovalTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private Customer $c1;
    private Customer $c2;
    private Approver $marta;      // también del cliente 1
    private Approver $pablo;      // cliente 2
    private Project $p2;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->world();
        $this->c1 = $this->clientWithApprover($this->project, $this->approver, 'Cliente Uno');
        $this->marta = Approver::create(['company_id' => $this->company->id, 'name' => 'Marta Gil', 'email' => 'marta@cliente.test']);
        $this->marta->customers()->attach($this->c1->id);
        $this->p2 = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Planta B', 'bill_rate' => 60]);
        $this->worker->projects()->attach($this->p2->id);
        $this->pablo = Approver::create(['company_id' => $this->company->id, 'name' => 'Pablo Soto', 'email' => 'pablo@otro.test']);
        $this->c2 = $this->clientWithApprover($this->p2, $this->pablo, 'Cliente Dos');
    }

    private function mails(string $view, ?string $to = null)
    {
        return Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === $view && ($to === null || $m->hasTo($to)));
    }

    /** La persona carga 8 h en P-1 y 2 h en P-2 y envía la hoja. */
    private function submitBoth(string $date = '2026-10-20'): Timesheet
    {
        Sanctum::actingAs($this->worker);
        $id = $this->postJson('/api/entries', $this->entry(['work_date' => $date]))->assertCreated()->json('timesheet_id');
        $this->postJson('/api/entries', $this->entry(['work_date' => $date, 'project_id' => $this->p2->id, 'start_time' => '16:00', 'end_time' => '18:00']))->assertCreated();
        $this->postJson('/api/sheets/'.$id.'/submit')->assertOk();
        return Timesheet::find($id);
    }

    private function submitOne(string $date = '2026-10-20'): Timesheet
    {
        Sanctum::actingAs($this->worker);
        $id = $this->postJson('/api/entries', $this->entry(['work_date' => $date]))->assertCreated()->json('timesheet_id');
        $this->postJson('/api/sheets/'.$id.'/submit')->assertOk();
        return Timesheet::find($id);
    }

    private function link(string $to): string
    {
        return $this->tokenFromMail($this->mails('mail.approval-request', $to)->last()->render());
    }

    private function account(Approver $a): User
    {
        return User::create(['company_id' => $this->company->id, 'name' => $a->name, 'email' => 'cuenta-'.$a->id.'@cliente.test', 'password' => 'secret-password',
            'role' => 'approver', 'worker_type' => 'employee', 'capture_mode' => 'full_day', 'approver_profile_id' => $a->id]);
    }

    private function svc(Project $p): Service
    {
        return $p->services()->first();
    }

    // ---------- configuración del servicio ----------

    public function test_service_payload_shows_chosen_and_effective_approvers_and_the_client(): void
    {
        Sanctum::actingAs($this->owner);
        $s = $this->svc($this->project);
        $r = $this->getJson('/api/projects')->assertOk()->json();
        $svc = collect($r)->firstWhere('id', $this->project->id)['services'][0];
        $this->assertSame(['id' => $this->c1->id, 'name' => 'Cliente Uno'], $svc['client']);
        $this->assertSame([], $svc['external_approver_ids']);
        $this->assertSame(['Jorge Ramos', 'Marta Gil'], array_column($svc['external_effective'], 'name'));    // omitido = todos los del cliente

        $r = $this->putJson('/api/services/'.$s->id, ['approval_mode' => 'external', 'external_approver_ids' => [$this->marta->id]])->assertOk();
        $this->assertSame([$this->marta->id], $r->json('external_approver_ids'));
        $this->assertSame([['id' => $this->marta->id, 'name' => 'Marta Gil']], $r->json('external_approvers'));
        $this->assertSame([['id' => $this->marta->id, 'name' => 'Marta Gil']], $r->json('external_effective'));
        $this->assertSame([], $r->json('internal_approver_ids'));

        // Vaciar la lista vuelve a "todos los del cliente".
        $r = $this->putJson('/api/services/'.$s->id, ['external_approver_ids' => []])->assertOk();
        $this->assertCount(2, $r->json('external_effective'));

        // Los demás roles no ven quiénes aprueban.
        Sanctum::actingAs($this->worker);
        $svc = collect($this->getJson('/api/projects')->json())->firstWhere('id', $this->project->id)['services'][0];
        $this->assertArrayNotHasKey('external_approver_ids', $svc);
        $this->assertArrayNotHasKey('client', $svc);
    }

    public function test_client_without_approvers_or_project_without_client_cannot_have_an_external_service(): void
    {
        Sanctum::actingAs($this->owner);
        $c3 = Customer::create(['company_id' => $this->company->id, 'name' => 'Sin aprobadores']);
        $p3 = Project::create(['company_id' => $this->company->id, 'customer_id' => $c3->id, 'code' => 'P-3', 'name' => 'Tres', 'bill_rate' => 1]);
        $r = $this->postJson('/api/projects/'.$p3->id.'/services', ['name' => 'Ext', 'approval_mode' => 'external'])->assertStatus(422)->assertJsonValidationErrors('external_approver_ids');
        $this->assertStringContainsString('Sin aprobadores', $r->json('errors.external_approver_ids.0'));
        $this->postJson('/api/projects/'.$p3->id.'/services', ['name' => 'Ext', 'approval_mode' => 'both', 'internal_approver_ids' => [$this->owner->id]])->assertStatus(422)->assertJsonValidationErrors('external_approver_ids');
        $none = $this->postJson('/api/projects/'.$p3->id.'/services', ['name' => 'Ninguno', 'approval_mode' => 'none'])->assertCreated()->json('id');
        $this->putJson('/api/services/'.$none, ['approval_mode' => 'external'])->assertStatus(422)->assertJsonValidationErrors('external_approver_ids');
        // Un aprobador inactivo del cliente no cuenta.
        $inactive = Approver::create(['company_id' => $this->company->id, 'name' => 'Inactivo', 'email' => 'in@x.test', 'active' => false]);
        $inactive->customers()->attach($c3->id);
        $this->putJson('/api/services/'.$none, ['approval_mode' => 'external'])->assertStatus(422);

        // Proyecto sin cliente.
        $p4 = Project::create(['company_id' => $this->company->id, 'code' => 'P-4', 'name' => 'Cuatro', 'bill_rate' => 1, 'billable' => false]);
        $this->postJson('/api/projects/'.$p4->id.'/services', ['name' => 'Ext', 'approval_mode' => 'external'])->assertStatus(422)->assertJsonValidationErrors('external_approver_ids');
        // Con 'none' sí.
        $this->postJson('/api/projects/'.$p4->id.'/services', ['name' => 'Libre', 'approval_mode' => 'none'])->assertCreated();
    }

    public function test_an_approver_of_another_client_cannot_be_chosen(): void
    {
        Sanctum::actingAs($this->owner);
        $s = $this->svc($this->project);
        $this->putJson('/api/services/'.$s->id, ['approval_mode' => 'external', 'external_approver_ids' => [$this->pablo->id]])
            ->assertStatus(422)->assertJsonValidationErrors('external_approver_ids');
        $this->putJson('/api/services/'.$s->id, ['approval_mode' => 'external', 'external_approver_ids' => [$this->marta->id, $this->pablo->id]])->assertStatus(422);
        $this->assertSame([], $s->externalApprovers()->pluck('approvers.id')->all());
        // Un aprobador de otra compañía tampoco.
        $other = Company::create(['name' => 'Otra', 'slug' => 'otra', 'locale' => 'es']);
        $foreign = Approver::create(['company_id' => $other->id, 'name' => 'Ajeno', 'email' => 'a@otra.test']);
        $this->putJson('/api/services/'.$s->id, ['approval_mode' => 'external', 'external_approver_ids' => [$foreign->id]])->assertStatus(422);
    }

    public function test_changing_a_services_mode_clears_the_lists_that_no_longer_apply(): void
    {
        Sanctum::actingAs($this->owner);
        $s = $this->svc($this->project);
        $this->putJson('/api/services/'.$s->id, ['approval_mode' => 'external', 'external_approver_ids' => [$this->marta->id]])->assertOk();
        $this->putJson('/api/services/'.$s->id, ['approval_mode' => 'internal', 'internal_approver_ids' => [$this->owner->id]])->assertOk()
            ->assertJsonPath('external_approver_ids', [])->assertJsonPath('external_effective', []);
    }

    public function test_changing_a_project_client_is_blocked_when_chosen_external_approvers_are_not_of_the_new_client(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/services/'.$this->svc($this->project)->id, ['approval_mode' => 'external', 'external_approver_ids' => [$this->marta->id]])->assertOk();
        $base = ['code' => 'P-1', 'name' => 'Planta A', 'billable' => false];
        $this->putJson('/api/projects/'.$this->project->id, $base + ['customer_id' => $this->c2->id])->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->assertSame($this->c1->id, $this->project->fresh()->customer_id);
        // Si el aprobador también sirve al cliente nuevo, se puede.
        $this->marta->customers()->attach($this->c2->id);
        $this->putJson('/api/projects/'.$this->project->id, $base + ['customer_id' => $this->c2->id])->assertOk();
        // Sin aprobadores escogidos (= todos del cliente), cambiar el cliente es libre.
        $this->putJson('/api/projects/'.$this->p2->id, ['code' => 'P-2', 'name' => 'Planta B', 'billable' => false, 'customer_id' => $this->c1->id])->assertOk();
    }

    public function test_approvers_are_linked_to_customers_and_listed_by_customer(): void
    {
        Sanctum::actingAs($this->owner);
        $r = $this->postJson('/api/approvers', ['name' => 'Nuevo', 'email' => 'nuevo@cliente.test', 'customer_ids' => [$this->c1->id, $this->c2->id]])->assertCreated();
        $this->assertEqualsCanonicalizing([$this->c1->id, $this->c2->id], $r->json('customer_ids'));
        $this->assertSame(['Cliente Dos', 'Cliente Uno'], array_column($r->json('customers'), 'name'));
        $id = $r->json('id');

        $names = fn ($q) => array_column($this->getJson('/api/approvers'.$q)->assertOk()->json(), 'name');
        $this->assertSame(['Jorge Ramos', 'Marta Gil', 'Nuevo'], $names('?customer_id='.$this->c1->id));
        $this->assertSame(['Nuevo', 'Pablo Soto'], $names('?customer_id='.$this->c2->id));
        $this->assertCount(4, $names(''));

        // Sin customer_ids en el PUT no se tocan; con la lista, se reemplazan.
        $this->putJson('/api/approvers/'.$id, ['name' => 'Nuevo', 'email' => 'nuevo@cliente.test'])->assertOk();
        $this->assertEqualsCanonicalizing([$this->c1->id, $this->c2->id], $this->getJson('/api/approvers?customer_id='.$this->c1->id)->json('2.customer_ids'));
        $this->putJson('/api/approvers/'.$id, ['name' => 'Nuevo', 'email' => 'nuevo@cliente.test', 'customer_ids' => [$this->c2->id]])->assertOk()->assertJsonPath('customer_ids', [$this->c2->id]);
        // Un cliente de otra compañía no sirve.
        $other = Company::create(['name' => 'Otra', 'slug' => 'otra', 'locale' => 'es']);
        $foreign = Customer::create(['company_id' => $other->id, 'name' => 'Ajeno']);
        $this->putJson('/api/approvers/'.$id, ['name' => 'Nuevo', 'email' => 'nuevo@cliente.test', 'customer_ids' => [$foreign->id]])->assertStatus(422);
    }

    public function test_a_customer_cannot_be_removed_from_an_approver_chosen_in_a_service_of_that_customer(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/services/'.$this->svc($this->project)->id, ['approval_mode' => 'external', 'external_approver_ids' => [$this->marta->id]])->assertOk();
        $body = ['name' => 'Marta Gil', 'email' => 'marta@cliente.test'];
        $r = $this->putJson('/api/approvers/'.$this->marta->id, $body + ['customer_ids' => []])->assertStatus(422)->assertJsonValidationErrors('customer_ids');
        $this->assertStringContainsString('P-1', $r->json('errors.customer_ids.0'));
        $this->assertStringContainsString('General', $r->json('errors.customer_ids.0'));
        $this->assertSame([$this->c1->id], $this->marta->customers()->pluck('customers.id')->all());
        // Jorge no está escogido en ningún servicio: se le puede quitar.
        $this->putJson('/api/approvers/'.$this->approver->id, ['name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'customer_ids' => []])->assertOk();
    }

    public function test_approver_options_for_the_selectors(): void
    {
        Sanctum::actingAs($this->owner);
        $inactive = Approver::create(['company_id' => $this->company->id, 'name' => 'Inactivo', 'email' => 'in@x.test', 'active' => false]);
        $inactive->customers()->attach($this->c1->id);
        $this->account($this->approver);            // las cuentas de aprobador externo no son candidatas a internas
        $r = $this->getJson('/api/projects/'.$this->project->id.'/approver-options')->assertOk()->json();
        $this->assertSame(['id' => $this->c1->id, 'name' => 'Cliente Uno'], $r['client']);
        $this->assertSame(['Jorge Ramos', 'Marta Gil'], array_column($r['external'], 'name'));
        $this->assertEqualsCanonicalizing(['Ana Torres', 'Luis'], array_column($r['internal'], 'name'));
        $p5 = Project::create(['company_id' => $this->company->id, 'code' => 'P-5', 'name' => 'Cinco', 'bill_rate' => 1, 'billable' => false]);
        $this->getJson('/api/projects/'.$p5->id.'/approver-options')->assertOk()->assertJsonPath('client', null)->assertJsonPath('external', []);
        Sanctum::actingAs($this->worker);
        $this->getJson('/api/projects/'.$this->project->id.'/approver-options')->assertForbidden();
    }

    // ---------- flujo ----------

    public function test_omitted_list_sends_the_sheet_to_every_active_approver_of_the_client(): void
    {
        $sheet = $this->submitOne();
        $this->assertSame('external', $sheet->fresh()->stage);
        $this->assertCount(2, $this->mails('mail.approval-request'));
        $this->assertCount(1, $this->mails('mail.approval-request', 'jorge@cliente.test'));
        $this->assertCount(1, $this->mails('mail.approval-request', 'marta@cliente.test'));
        $this->assertCount(0, $this->mails('mail.approval-request', 'pablo@otro.test'));
        $this->assertNotSame($this->link('jorge@cliente.test'), $this->link('marta@cliente.test'));

        $j = $this->getJson('/api/sheets/'.$sheet->id)->json();
        $this->assertEqualsCanonicalizing(['Jorge Ramos', 'Marta Gil'], array_column($j['waiting_for'], 'name'));
        $this->assertSame(['external', 'external'], array_column($j['waiting_for'], 'kind'));
        $this->assertCount(1, $j['external']);
        $this->assertSame('pending', $j['external'][0]['status']);
        $this->assertSame('P-1 · Planta A', $j['external'][0]['project']);
        $this->assertNull($j['external'][0]['service']);                        // un solo servicio: no se nombra
        $this->assertSame($this->svc($this->project)->id, $j['external'][0]['service_id']);
        $this->assertSame(['Jorge Ramos', 'Marta Gil'], array_column($j['external'][0]['approvers'], 'name'));
    }

    public function test_chosen_list_restricts_who_receives_the_sheet(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/services/'.$this->svc($this->project)->id, ['approval_mode' => 'external', 'external_approver_ids' => [$this->marta->id]])->assertOk();
        $this->submitOne();
        $this->assertCount(1, $this->mails('mail.approval-request'));
        $this->assertCount(1, $this->mails('mail.approval-request', 'marta@cliente.test'));
    }

    public function test_a_sheet_with_two_clients_goes_to_each_approver_with_only_their_hours(): void
    {
        $sheet = $this->submitBoth();
        $this->assertCount(3, $this->mails('mail.approval-request'));

        $jorge = $this->mails('mail.approval-request', 'jorge@cliente.test')->first()->render();
        $this->assertStringContainsString('P-1 · Planta A', $jorge);
        $this->assertStringContainsString('8.00 h', $jorge);
        $this->assertStringNotContainsString('P-2', $jorge);
        $this->assertStringNotContainsString('Planta B', $jorge);
        $pablo = $this->mails('mail.approval-request', 'pablo@otro.test')->first()->render();
        $this->assertStringContainsString('P-2 · Planta B', $pablo);
        $this->assertStringContainsString('2.00 h', $pablo);
        $this->assertStringNotContainsString('P-1', $pablo);

        // La página pública también muestra solo lo suyo.
        $page = $this->get('/a/'.$this->link('pablo@otro.test'))->assertOk()->getContent();
        $this->assertStringContainsString('P-2', $page);
        $this->assertStringNotContainsString('P-1', $page);
        $this->assertStringContainsString('2.00', $page);

        // La persona y el dueño ven las dos partes y a todos los que faltan.
        $j = $this->getJson('/api/sheets/'.$sheet->id)->json();
        $this->assertCount(2, $j['external']);
        $this->assertCount(3, $j['waiting_for']);

        // Se completa solo con las dos partes.
        $this->post('/a/'.$this->link('jorge@cliente.test').'/approve')->assertOk();
        $this->assertSame('submitted', $sheet->fresh()->status);
        $this->assertCount(0, $this->mails('mail.sheet-decision'));
        $j = $this->getJson('/api/sheets/'.$sheet->id)->json();
        $this->assertSame(['approved', 'pending'], array_column($j['external'], 'status'));
        $this->assertSame('Jorge Ramos', $j['external'][0]['approved_by']['name']);
        $this->assertSame(['Pablo Soto'], array_column($j['waiting_for'], 'name'));

        $this->post('/a/'.$this->link('pablo@otro.test').'/approve')->assertOk();
        $fresh = $sheet->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($this->pablo->id, $fresh->decided_by_approver_id);
        $this->assertCount(1, $this->mails('mail.sheet-decision'));
        $this->assertSame(2, $sheet->externalApprovals()->count());
        $this->assertSame(['external_approved', 'external_approved', 'approved'], array_values(array_filter($sheet->events()->pluck('type')->all(), fn ($t) => str_contains($t, 'approved'))));
    }

    public function test_one_of_several_external_approvers_is_enough_and_the_others_lose_their_link(): void
    {
        $sheet = $this->submitOne();
        $marta = $this->link('marta@cliente.test');
        $this->post('/a/'.$this->link('jorge@cliente.test').'/approve')->assertOk();
        $this->assertSame('approved', $sheet->fresh()->status);
        $this->get('/a/'.$marta)->assertStatus(410);
        $this->assertSame([$this->approver->id], $sheet->externalApprovals()->pluck('approved_by_approver_id')->all());
    }

    public function test_partial_approval_closes_the_links_of_whoever_has_nothing_left(): void
    {
        $sheet = $this->submitBoth();
        $marta = $this->link('marta@cliente.test');
        $pablo = $this->link('pablo@otro.test');
        $this->post('/a/'.$this->link('jorge@cliente.test').'/approve')->assertOk();
        $this->get('/a/'.$marta)->assertStatus(410);            // su parte ya la aprobó Jorge
        $this->get('/a/'.$pablo)->assertOk();                   // la de Pablo sigue pendiente
        $this->assertSame('submitted', $sheet->fresh()->status);
    }

    public function test_an_external_return_sends_the_whole_sheet_back_and_resubmitting_starts_over(): void
    {
        $sheet = $this->submitBoth();
        $this->post('/a/'.$this->link('pablo@otro.test').'/approve')->assertOk();
        $this->assertSame(1, $sheet->externalApprovals()->count());

        $marta = $this->link('marta@cliente.test');
        $this->post('/a/'.$marta.'/return', ['comment' => 'Faltan horas'])->assertOk();
        $fresh = $sheet->fresh();
        $this->assertSame('returned', $fresh->status);
        $this->assertSame($this->marta->id, $fresh->decided_by_approver_id);
        $this->assertSame('Faltan horas', $fresh->return_comment);
        $m = $this->mails('mail.sheet-decision')->last();
        $this->assertTrue($m->hasTo('ana@cerevelo.test'));
        $this->assertSame('Marta Gil', $sheet->events()->where('type', 'returned')->first()->actor);
        $this->get('/a/'.$this->link('jorge@cliente.test'))->assertStatus(410);        // todos los enlaces mueren

        // Al reenviar empieza de nuevo: la aprobación previa de Pablo ya no cuenta y se le avisa otra vez.
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/sheets/'.$sheet->id.'/submit')->assertOk();
        $this->assertSame(0, $sheet->externalApprovals()->count());
        $this->assertCount(2, $this->mails('mail.approval-request', 'pablo@otro.test'));
        $this->assertStringContainsString('corregida', $this->mails('mail.approval-request', 'pablo@otro.test')->last()->render());
        $this->assertCount(2, $this->mails('mail.approval-request', 'jorge@cliente.test'));
    }

    public function test_a_sheet_with_the_clients_cannot_be_changed_so_approvals_and_links_stay_valid(): void
    {
        $sheet = $this->submitBoth();
        $jorge = $this->link('jorge@cliente.test');
        $this->post('/a/'.$this->link('pablo@otro.test').'/approve')->assertOk();
        $before = $this->mails('mail.approval-request')->count();

        Sanctum::actingAs($this->worker);
        $entry = $sheet->entries()->where('project_id', $this->project->id)->first();
        $this->putJson('/api/entries/'.$entry->id, $this->entry(['start_time' => '07:00']))->assertStatus(423);
        $this->assertSame(1, $sheet->fresh()->version);
        $this->assertSame(1, $sheet->externalApprovals()->count());           // la aprobación de Pablo sigue
        $this->get('/a/'.$jorge)->assertOk();                                  // el enlace sigue sirviendo
        $this->assertSame($before, $this->mails('mail.approval-request')->count());
        $this->post('/a/'.$this->link('jorge@cliente.test').'/approve')->assertOk();
    }

    public function test_each_account_sees_only_the_parts_that_are_theirs(): void
    {
        $sheet = $this->submitBoth();
        $jorge = $this->account($this->approver);
        $pablo = $this->account($this->pablo);

        Sanctum::actingAs($jorge);
        $r = $this->getJson('/api/approver/review')->assertOk()->json();
        $this->assertSame(1, $r['counts']['pending']);
        $row = $r['sheets'][0];
        $this->assertEquals(8, $row['total_hours']);
        $this->assertSame(['P-1 · Planta A'], $row['projects']);
        $this->assertCount(1, $row['external']);
        $this->assertStringNotContainsString('P-2', json_encode($row));
        $d = $this->getJson('/api/approver/sheets/'.$sheet->id)->assertOk()->json();
        $this->assertCount(1, $d['entries']);
        $this->assertEquals(8, $d['total_hours']);
        $this->assertStringNotContainsString('P-2', json_encode($d));
        $this->assertStringNotContainsString('Pablo', json_encode($d));
        $this->assertSame([['id' => $this->approver->id, 'name' => 'Jorge Ramos', 'kind' => 'external']], $d['waiting_for']);

        Sanctum::actingAs($pablo);
        $d = $this->getJson('/api/approver/sheets/'.$sheet->id)->assertOk()->json();
        $this->assertEquals(2, $d['total_hours']);
        $this->assertSame('P-2', $d['entries'][0]['project']['code']);
        $this->assertCount(1, $this->getJson('/api/approver/sheets')->assertOk()->json('0.external'));

        // Cada uno aprueba lo suyo; la hoja se cierra con la última parte.
        Sanctum::actingAs($jorge);
        $this->postJson('/api/approver/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertOk()->assertJsonPath('status', 'submitted')
            ->assertJsonPath('external.0.status', 'approved');
        $this->postJson('/api/approver/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertStatus(422);
        $this->assertSame(0, $this->getJson('/api/approver/review')->json('counts.pending'));
        $this->assertSame(1, $this->getJson('/api/approver/review?status=approved')->json('counts.approved'));
        Sanctum::actingAs($pablo);
        $this->postJson('/api/approver/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame('approved', $sheet->fresh()->status);
    }

    public function test_an_account_with_no_part_in_the_sheet_cannot_open_or_decide_it(): void
    {
        $sheet = $this->submitOne();
        $pablo = $this->account($this->pablo);
        Sanctum::actingAs($pablo);
        $this->getJson('/api/approver/sheets/'.$sheet->id)->assertNotFound();
        $this->postJson('/api/approver/sheets/'.$sheet->id.'/approve', ['version' => 1])->assertNotFound();
        $this->postJson('/api/approver/sheets/'.$sheet->id.'/return', ['version' => 1, 'comment' => 'no'])->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/approver/review')->json('counts.all'));
        // La página de lote por código tampoco.
        $plain = app(\App\Services\ApprovalService::class)->issueBatchToken($this->pablo);
        \App\Models\ApprovalToken::where('scope', 'batch')->update(['code_verified_at' => now()]);
        $this->post('/b/'.$plain.'/sheet/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertNotFound();
        $this->assertSame('submitted', $sheet->fresh()->status);
    }

    public function test_batch_page_lists_only_pending_parts_with_only_their_hours(): void
    {
        $sheet = $this->submitBoth();
        $plain = app(\App\Services\ApprovalService::class)->issueBatchToken($this->pablo);
        $token = \App\Models\ApprovalToken::where('scope', 'batch')->first();
        $token->forceFill(['code_verified_at' => now()])->save();
        $page = $this->get('/b/'.$plain)->assertOk()->getContent();
        $this->assertStringContainsString('P-2', $page);
        $this->assertStringNotContainsString('P-1', $page);
        $this->post('/b/'.$plain.'/approve-all', ['items' => [$sheet->id.':'.$sheet->version]])->assertOk();
        $this->assertSame(1, $sheet->externalApprovals()->count());
        $this->assertSame('submitted', $sheet->fresh()->status);         // falta la parte de Jorge/Marta
    }

    public function test_a_service_without_chosen_approvers_in_a_client_with_none_falls_back_to_the_old_approver(): void
    {
        // Servicio viejo: el cliente perdió a sus aprobadores. Respaldo: el de la persona (users.approver_id).
        DB::table('approver_customer')->delete();
        $sheet = $this->submitOne();
        $this->assertSame('external', $sheet->fresh()->stage);
        $this->assertCount(1, $this->mails('mail.approval-request'));
        $this->assertCount(1, $this->mails('mail.approval-request', 'jorge@cliente.test'));
        $this->post('/a/'.$this->link('jorge@cliente.test').'/approve')->assertOk();
        $this->assertSame('approved', $sheet->fresh()->status);

        // El del proyecto manda sobre el de la persona.
        $this->project->forceFill(['approver_id' => $this->marta->id])->save();
        $s2 = $this->submitOne('2026-10-13');
        $this->assertCount(1, $this->mails('mail.approval-request', 'marta@cliente.test'));
        $this->assertSame(['Marta Gil'], array_column($this->getJson('/api/sheets/'.$s2->id)->json('waiting_for'), 'name'));

        // Sin nadie, una persona común no puede enviar.
        $this->project->forceFill(['approver_id' => null])->save();
        $this->worker->update(['approver_id' => null]);
        Sanctum::actingAs($this->worker);
        $id = $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-06']))->assertCreated()->json('timesheet_id');
        $this->postJson('/api/sheets/'.$id.'/submit')->assertStatus(422)->assertJsonValidationErrors('approver');
    }

    public function test_the_owner_can_still_override_in_the_external_stage(): void
    {
        $sheet = $this->submitBoth();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$sheet->id.'/approve', ['version' => $sheet->version])->assertOk()->assertJsonPath('status', 'approved');
        $this->get('/a/'.$this->link('jorge@cliente.test'))->assertStatus(410);
    }

    public function test_two_services_of_one_project_are_two_parts(): void
    {
        Sanctum::actingAs($this->owner);
        $second = $this->postJson('/api/projects/'.$this->project->id.'/services', ['name' => 'Soporte', 'approval_mode' => 'external', 'external_approver_ids' => [$this->marta->id]])
            ->assertCreated()->json('id');
        $this->putJson('/api/services/'.$this->svc($this->project)->id, ['approval_mode' => 'external', 'external_approver_ids' => [$this->approver->id]])->assertOk();
        Sanctum::actingAs($this->worker);
        $id = $this->postJson('/api/entries', $this->entry(['service_id' => $this->svc($this->project)->id]))->assertCreated()->json('timesheet_id');
        $this->postJson('/api/entries', $this->entry(['service_id' => $second, 'start_time' => '16:00', 'end_time' => '17:00']))->assertCreated();
        $this->postJson('/api/sheets/'.$id.'/submit')->assertOk();
        $j = $this->getJson('/api/sheets/'.$id)->json();
        $this->assertSame(['General', 'Soporte'], array_column($j['external'], 'service'));      // el proyecto tiene >1 servicio: se nombran
        $this->assertStringContainsString('P-1 · Planta A · Soporte', $this->mails('mail.approval-request', 'marta@cliente.test')->first()->render());
        $this->assertStringNotContainsString('Soporte', $this->mails('mail.approval-request', 'jorge@cliente.test')->first()->render());
        $this->post('/a/'.$this->link('marta@cliente.test').'/approve')->assertOk();
        $this->assertSame('submitted', Timesheet::find($id)->status);
        $this->post('/a/'.$this->link('jorge@cliente.test').'/approve')->assertOk();
        $this->assertSame('approved', Timesheet::find($id)->status);
    }

    public function test_internal_parts_are_per_service_each_with_its_own_supervisors(): void
    {
        $sara = User::create(['company_id' => $this->company->id, 'name' => 'Sara Díaz', 'email' => 'sara@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to']);
        $tomas = User::create(['company_id' => $this->company->id, 'name' => 'Tomás Ruiz', 'email' => 'tomas@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to']);
        Sanctum::actingAs($this->owner);
        $a = $this->svc($this->project)->id;
        $this->putJson('/api/services/'.$a, ['approval_mode' => 'internal', 'internal_approver_ids' => [$sara->id]])->assertOk();
        $b = $this->postJson('/api/projects/'.$this->project->id.'/services', ['name' => 'Soporte', 'approval_mode' => 'internal', 'internal_approver_ids' => [$tomas->id]])->assertCreated()->json('id');
        Sanctum::actingAs($this->worker);
        $id = $this->postJson('/api/entries', $this->entry(['service_id' => $a]))->assertCreated()->json('timesheet_id');
        $this->postJson('/api/entries', $this->entry(['service_id' => $b, 'start_time' => '16:00', 'end_time' => '17:00']))->assertCreated();
        $this->postJson('/api/sheets/'.$id.'/submit')->assertOk()->assertJsonPath('stage', 'internal');
        $this->assertCount(2, $this->mails('mail.internal-approval-request'));
        $this->assertStringContainsString('P-1 · Planta A · Soporte', $this->mails('mail.internal-approval-request', 'tomas@cerevelo.test')->first()->render());
        $this->assertStringNotContainsString('Soporte', $this->mails('mail.internal-approval-request', 'sara@cerevelo.test')->first()->render());

        // Cada supervisor ve solo las líneas de su servicio, aunque sean del mismo proyecto.
        Sanctum::actingAs($sara);
        $d = $this->getJson('/api/internal/sheets/'.$id)->assertOk()->json();
        $this->assertCount(1, $d['entries']);
        $this->assertEquals(8, $d['total_hours']);
        $this->assertCount(1, $d['internal']);
        $this->assertSame($a, $d['internal'][0]['service_id']);
        $this->assertSame('General', $d['internal'][0]['service']);
        $this->postJson('/api/internal/sheets/'.$id.'/approve', ['version' => 1])->assertOk()->assertJsonPath('stage', 'internal');
        Sanctum::actingAs($tomas);
        $this->assertEquals(1, $this->getJson('/api/internal/sheets/'.$id)->json('total_hours'));
        $this->postJson('/api/internal/sheets/'.$id.'/approve', ['version' => 1])->assertOk()->assertJsonPath('status', 'approved');   // sin partes externas
    }

    public function test_a_service_created_without_approval_mode_keeps_working_with_the_old_fallback(): void
    {
        Sanctum::actingAs($this->owner);
        $p = Project::create(['company_id' => $this->company->id, 'code' => 'P-7', 'name' => 'Siete', 'bill_rate' => 1, 'billable' => false]);
        $this->postJson('/api/projects/'.$p->id.'/services', ['name' => 'Viejo'])->assertCreated()->assertJsonPath('approval_mode', 'external');
    }

    // ---------- recordatorios ----------

    public function test_reminders_send_one_mail_per_approver_even_when_they_share_a_sheet(): void
    {
        $this->submitBoth();
        $this->artisan('approvals:remind')->assertSuccessful();
        $this->assertCount(0, $this->mails('mail.pending-digest'));

        Carbon::setTestNow(now()->addDays(8));
        $this->artisan('approvals:remind')->assertSuccessful();
        $this->assertCount(3, $this->mails('mail.pending-digest'));                       // Jorge, Marta y Pablo, cada uno uno
        foreach (['jorge@cliente.test', 'marta@cliente.test', 'pablo@otro.test'] as $to) {
            $this->assertCount(1, $this->mails('mail.pending-digest', $to));
        }
        // No se repite antes de "cada N días".
        $this->artisan('approvals:remind');
        $this->assertCount(3, $this->mails('mail.pending-digest'));
        Carbon::setTestNow(now()->addDays(4));
        $this->artisan('approvals:remind');
        $this->assertCount(6, $this->mails('mail.pending-digest'));
    }

    public function test_reminders_skip_approvers_with_nothing_pending(): void
    {
        $sheet = $this->submitBoth();
        $this->post('/a/'.$this->link('jorge@cliente.test').'/approve')->assertOk();
        Carbon::setTestNow(now()->addDays(8));
        $this->artisan('approvals:remind')->assertSuccessful();
        $this->assertCount(1, $this->mails('mail.pending-digest'));
        $this->assertCount(1, $this->mails('mail.pending-digest', 'pablo@otro.test'));
        $this->assertSame('submitted', $sheet->fresh()->status);
    }

    // ---------- migración de datos con hojas en vuelo ----------

    private function runBackfill(): void
    {
        (require base_path('database/migrations/2026_11_01_000000_approvers_by_service.php'))->backfill();
    }

    public function test_data_migration_keeps_in_flight_sheets_working(): void
    {
        // Mundo "viejo": sin clientes con aprobadores, sin listas por servicio; la lista interna es del proyecto y el aprobador, de la persona.
        DB::table('approver_customer')->delete();
        Project::whereKey($this->project->id)->update(['customer_id' => null, 'approver_id' => $this->approver->id]);
        $sara = User::create(['company_id' => $this->company->id, 'name' => 'Sara Díaz', 'email' => 'sara@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to', 'approver_id' => $this->approver->id]);
        DB::table('project_internal_approvers')->insert(['project_id' => $this->project->id, 'user_id' => $sara->id]);
        Service::where('project_id', $this->project->id)->update(['approval_mode' => 'both']);
        $this->assertSame(0, DB::table('service_internal_approvers')->count());

        // Hoja A: en la etapa interna. Hoja B: ya en la externa, con el enlace de Jorge emitido.
        $a = $this->submitOne('2026-10-20');
        $this->assertSame('internal', $a->fresh()->stage);
        Service::where('project_id', $this->project->id)->update(['approval_mode' => 'external']);
        $b = $this->submitOne('2026-10-13');
        $this->assertSame('external', $b->fresh()->stage);
        $this->assertCount(1, $this->mails('mail.approval-request', 'jorge@cliente.test'));
        $oldLink = $this->link('jorge@cliente.test');
        Service::where('project_id', $this->project->id)->update(['approval_mode' => 'both']);

        // Migración: el proyecto ahora tiene cliente y Jorge es su aprobador (señal: projects.approver_id).
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Viejo']);
        Project::whereKey($this->project->id)->update(['customer_id' => $customer->id]);
        $this->runBackfill();
        $this->runBackfill();       // idempotente
        $svc = $this->svc($this->project);
        $this->assertSame([$sara->id], $svc->internalApprovers()->pluck('users.id')->all());
        $this->assertSame([], $svc->externalApprovers()->pluck('approvers.id')->all());
        // Jorge sirve al cliente del proyecto (projects.approver_id) y al de P-2 (la persona asignada allí lo tiene como aprobador).
        $this->assertEqualsCanonicalizing([$customer->id, $this->c2->id], $this->approver->customers()->pluck('customers.id')->all());
        $this->assertSame(2, DB::table('approver_customer')->where('approver_id', $this->approver->id)->count());

        // La hoja A termina su flujo: Sara aprueba (etapa interna) y pasa a Jorge.
        Sanctum::actingAs($sara);
        $this->postJson('/api/internal/sheets/'.$a->id.'/approve', ['version' => $a->version])->assertOk()->assertJsonPath('stage', 'external');
        $this->assertCount(2, $this->mails('mail.approval-request', 'jorge@cliente.test'));
        $this->post('/a/'.$this->link('jorge@cliente.test').'/approve')->assertOk();
        $this->assertSame('approved', $a->fresh()->status);

        // La hoja B también: el enlace que ya tenía Jorge sigue sirviendo.
        $this->post('/a/'.$oldLink.'/approve')->assertOk();
        $this->assertSame('approved', $b->fresh()->status);
    }

    public function test_data_migration_links_approvers_by_the_people_assigned_to_the_project(): void
    {
        DB::table('approver_customer')->delete();
        // Sin projects.approver_id: la señal es users.approver_id de las personas asignadas.
        $this->runBackfill();
        $this->assertEqualsCanonicalizing([$this->c1->id, $this->c2->id], DB::table('approver_customer')->where('approver_id', $this->approver->id)->pluck('customer_id')->all());
        // Un aprobador sin ninguna señal queda sin cliente.
        $this->assertSame(0, DB::table('approver_customer')->where('approver_id', $this->marta->id)->count());
        $this->assertSame(0, DB::table('approver_customer')->where('approver_id', $this->pablo->id)->count());
        Project::whereKey($this->p2->id)->update(['approver_id' => $this->pablo->id]);
        $this->runBackfill();
        $this->assertSame([$this->c2->id], DB::table('approver_customer')->where('approver_id', $this->pablo->id)->pluck('customer_id')->all());
    }

    public function test_old_internal_rows_per_project_still_count_for_the_services_of_that_project(): void
    {
        // Fila vieja de aprobación interna (por proyecto, sin servicio) de una hoja en vuelo.
        Service::where('project_id', $this->project->id)->update(['approval_mode' => 'both']);
        $sara = User::create(['company_id' => $this->company->id, 'name' => 'Sara Díaz', 'email' => 'sara@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to']);
        DB::table('project_internal_approvers')->insert(['project_id' => $this->project->id, 'user_id' => $sara->id]);
        $sheet = $this->submitOne();
        $this->assertSame('internal', $sheet->fresh()->stage);
        DB::table('timesheet_internal_approvals')->insert(['timesheet_id' => $sheet->id, 'project_id' => $this->project->id, 'service_id' => null,
            'version' => $sheet->version, 'approved_by_user_id' => $sara->id, 'skipped' => false, 'approved_at' => now()]);
        app(SheetService::class)->finishInternal($sheet);
        $this->assertSame('external', $sheet->fresh()->stage);
        $j = $this->getJson('/api/sheets/'.$sheet->id)->json();
        $this->assertSame('approved', $j['internal'][0]['status']);
        $this->assertSame('Sara Díaz', $j['internal'][0]['approved_by']['name']);
    }
}
