<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

class ClientTimesheetTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private Customer $customer;
    private Project $second;

    /** Cliente con dos proyectos; dos personas con semanas aprobadas, una semana sin aprobar y horas de otro cliente. */
    private function setUpHours(): void
    {
        Mail::fake();
        $this->world();
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Advance Logistics', 'email' => 'cuentas@advance.test']);
        $this->project->update(['customer_id' => $this->customer->id, 'name' => 'Soporte', 'bill_rate' => 50]);
        $this->second = Project::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'code' => 'P-2', 'name' => 'Desarrollo', 'bill_rate' => 75]);
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'X-1', 'name' => 'De otro cliente', 'bill_rate' => 10]);
        $pedro = User::create(['company_id' => $this->company->id, 'name' => 'Pedro Vega', 'email' => 'pedro@x.test', 'password' => 'secret-password',
            'capture_mode' => 'from_to', 'approver_id' => $this->approver->id]);
        foreach ([$this->worker, $pedro] as $u) {
            $u->projects()->sync([$this->project->id, $this->second->id, $other->id]);
        }
        $this->owner->update(['approver_id' => null]);

        $add = function (User $u, array $e, bool $approve = true) {
            Sanctum::actingAs($u);
            $r = $this->postJson('/api/entries', $e)->assertCreated()->json();
            if ($approve) {
                $this->postJson('/api/sheets/'.$r['timesheet_id'].'/submit')->assertOk();
                Sanctum::actingAs($this->owner);
                $this->postJson('/api/sheets/'.$r['timesheet_id'].'/approve')->assertOk();
            }
            return $r;
        };
        // Semana del 19 al 25 (se envía y se aprueba al agregar la última entrada): Ana 8 h en P-1, 2 h en P-2 y 3 h de otro
        // cliente; Pedro 4 h en P-1.
        $add($this->worker, $this->entry(['start_time' => '08:00', 'end_time' => '16:00', 'note' => "Soporte en planta.\nCambio de router."]), false);
        $add($this->worker, $this->entry(['project_id' => $this->second->id, 'start_time' => '16:00', 'end_time' => '18:00', 'note' => 'Reunión de arquitectura']), false);
        $add($this->worker, $this->entry(['project_id' => $other->id, 'work_date' => '2026-10-19', 'start_time' => '08:00', 'end_time' => '11:00', 'note' => 'otro']), true);
        $add($pedro, $this->entry(['start_time' => '09:00', 'end_time' => '13:00', 'note' => 'Apoyo de Pedro']), true);
        // Semana anterior, en borrador (sin enviar ni aprobar): Ana 5 h en P-1.
        $add($this->worker, $this->entry(['work_date' => '2026-10-13', 'start_time' => '08:00', 'end_time' => '13:00', 'note' => 'Semana sin aprobar']), false);
        Mail::fake();   // los avisos de aprobación del armado de datos no cuentan
        Sanctum::actingAs($this->owner);
    }

    public function test_preview_has_hours_by_project_with_descriptions_and_no_amounts_by_default(): void
    {
        $this->setUpHours();
        $d = $this->postJson('/api/client-timesheet/preview', ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertOk()->json();

        $this->assertSame('Advance Logistics', $d['customer']);
        $this->assertEquals(14.0, $d['total_hours']);                       // 8 + 2 + 4; sin la semana sin aprobar ni el otro cliente
        $this->assertCount(2, $d['projects']);
        [$p1, $p2] = $d['projects'];
        $this->assertSame('P-1', $p1['code']);
        $this->assertEquals(12.0, $p1['hours']);
        $this->assertEquals([['worker' => 'Ana Torres', 'hours' => 8], ['worker' => 'Pedro Vega', 'hours' => 4]], array_map(fn ($w) => ['worker' => $w['worker'], 'hours' => $w['hours']], $p1['by_worker']));
        $this->assertSame("Soporte en planta.\nCambio de router.", $p1['lines'][0]['description']);
        $this->assertSame('08:00', $p1['lines'][0]['start']);
        $this->assertEquals(2.0, $p2['hours']);
        $this->assertNull($d['total_amount']);                               // sin tarifas ni importes
        $this->assertNull($p1['lines'][0]['amount']);
        $this->assertFalse($d['has_pending']);
        $this->assertSame(['Jorge Ramos'], $d['approved_by']);
        $this->assertNotContains('Semana sin aprobar', array_column($p1['lines'], 'description'));
    }

    public function test_pending_hours_only_appear_when_asked_and_are_marked(): void
    {
        $this->setUpHours();
        $base = ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'project_ids' => [$this->project->id]];
        $without = $this->postJson('/api/client-timesheet/preview', $base)->assertOk()->json();
        $with = $this->postJson('/api/client-timesheet/preview', $base + ['include_pending' => true])->assertOk()->json();
        $this->assertEquals(12.0, $without['total_hours']);                  // lo aprobado: Ana 8 + Pedro 4
        $this->assertEquals(17.0, $with['total_hours']);                     // + 5 de la semana en borrador
        $this->assertTrue($with['has_pending']);
        $this->assertContains(false, array_column($with['projects'][0]['lines'], 'approved'));
    }

    public function test_filters_by_person_and_amounts_only_when_requested(): void
    {
        $this->setUpHours();
        $r = $this->postJson('/api/client-timesheet/preview', ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31',
            'user_ids' => [User::where('email', 'pedro@x.test')->first()->id], 'show_amounts' => true])->assertOk()->json();
        $this->assertEquals(4.0, $r['total_hours']);
        $this->assertEquals(200.0, $r['total_amount']);                      // 4 h x 50
        $this->assertEquals(200.0, $r['projects'][0]['lines'][0]['amount']);
    }

    public function test_non_billable_hours_are_left_out_unless_included(): void
    {
        $this->setUpHours();
        \App\Models\TimeEntry::where('project_id', $this->project->id)->where('user_id', User::where('email', 'pedro@x.test')->first()->id)
            ->update(['billable' => false]);
        $q = ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'project_ids' => [$this->project->id]];
        $this->assertEquals(8.0, $this->postJson('/api/client-timesheet/preview', $q)->json('total_hours'));   // sin las 4 h de Pedro
        $this->assertEquals(12.0, $this->postJson('/api/client-timesheet/preview', $q + ['include_non_billable' => true])->json('total_hours'));
    }

    public function test_pdf_and_excel_downloads(): void
    {
        $this->setUpHours();
        $q = ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'include_pending' => true];

        $pdf = $this->post('/api/client-timesheet/download', $q + ['format' => 'pdf'], ['Accept' => 'application/pdf']);
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('hoja-de-tiempo-advance-logistics-2026-10-01_2026-10-31.pdf', $pdf->headers->get('Content-Disposition'));

        $x = $this->post('/api/client-timesheet/download', $q + ['format' => 'xlsx']);
        $x->assertOk();
        $copy = tempnam(sys_get_temp_dir(), 'ct').'.xlsx';
        copy($x->baseResponse->getFile()->getPathname(), $copy);
        $sheet = IOFactory::load($copy)->getActiveSheet();
        $this->assertSame('Cerevelo — Hoja de tiempo', $sheet->getCell('A1')->getValue());
        $this->assertSame('Cliente: Advance Logistics', $sheet->getCell('A2')->getValue());
        $rows = [];
        for ($r = 7; $r <= $sheet->getHighestRow(); $r++) {
            if ($sheet->getCell('B'.$r)->getValue()) {
                $rows[] = [$sheet->getCell('B'.$r)->getValue(), $sheet->getCell('C'.$r)->getValue(), (float) $sheet->getCell('F'.$r)->getValue(), $sheet->getCell('G'.$r)->getValue()];
            }
        }
        $this->assertContains(['Ana Torres', 'P-1 · Soporte', 5.0, 'Semana sin aprobar (sin aprobar)'], $rows);
        $total = null;
        for ($r = 7; $r <= $sheet->getHighestRow(); $r++) {
            if ($sheet->getCell('C'.$r)->getValue() === 'TOTAL') {
                $total = (float) $sheet->getCell('F'.$r)->getValue();
            }
        }
        $this->assertEquals(19.0, $total);                                   // 8 + 2 + 4 + 5
        unlink($copy);
    }

    public function test_email_goes_to_the_customer_with_the_pdf_and_only_when_asked(): void
    {
        $this->setUpHours();
        $q = ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'include_pending' => true];
        $this->postJson('/api/client-timesheet/preview', $q)->assertOk();
        $this->post('/api/client-timesheet/download', $q);
        Mail::assertNothingSent();      // generar y ver no envía nada

        $r = $this->postJson('/api/client-timesheet/email', $q + ['message' => 'Adjunto la hoja de octubre.'])->assertOk();
        $this->assertSame('cuentas@advance.test', $r->json('sent_to'));
        $sent = Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.client-timesheet');
        $this->assertCount(1, $sent);
        $m = $sent->first();
        $this->assertTrue($m->hasTo('cuentas@advance.test'));
        $this->assertCount(1, $m->files);
        $this->assertSame('application/pdf', $m->files[0]['mime']);
        $html = $m->render();
        $this->assertStringContainsString('Adjunto la hoja de octubre.', $html);
        $this->assertStringContainsString('Advance Logistics', $html);
        $this->assertStringNotContainsString('$', $html);                    // sin importes

        $this->postJson('/api/client-timesheet/email', $q + ['email_to' => 'otro@cliente.test'])->assertOk()->assertJsonPath('sent_to', 'otro@cliente.test');
    }

    public function test_email_needs_an_address_and_some_hours(): void
    {
        $this->setUpHours();
        $this->customer->update(['email' => null]);
        $q = ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'include_pending' => true];
        $this->postJson('/api/client-timesheet/email', $q)->assertStatus(422)->assertJsonValidationErrors('email_to');
        $this->postJson('/api/client-timesheet/email', ['email_to' => 'x@y.test', 'customer_id' => $this->customer->id, 'from' => '2026-01-01', 'to' => '2026-01-31'])->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_only_the_owner_and_only_their_own_customers(): void
    {
        $this->setUpHours();
        $q = ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31'];
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/client-timesheet/preview', $q)->assertForbidden();
        $this->postJson('/api/client-timesheet/email', $q)->assertForbidden();

        $c2 = \App\Models\Company::create(['name' => 'Otra', 'slug' => 'otra']);
        $u2 = User::create(['company_id' => $c2->id, 'name' => 'Otro', 'email' => 'o@o.test', 'password' => 'secret-password', 'role' => 'owner']);
        Sanctum::actingAs($u2);
        $this->postJson('/api/client-timesheet/preview', $q)->assertNotFound();
        $this->post('/api/client-timesheet/download', $q)->assertNotFound();
    }
}
