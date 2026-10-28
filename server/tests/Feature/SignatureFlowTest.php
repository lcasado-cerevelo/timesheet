<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\ContractorTerms;
use App\Models\Customer;
use App\Models\Signature;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/**
 * Firma digital (fase 4): someter la hoja, aprobarla o devolverla y someter la factura se firman con la contraseña de quien actúa; el
 * aprobador externo que usa el enlace del correo firma con el código de 6 dígitos. Estas pruebas NO usan la firma automática.
 */
class SignatureFlowTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    protected bool $autoSign = false;

    private function ready(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->world();
        $this->owner->update(['approver_id' => null]);
    }

    /** El dueño es aprobador interno del servicio: aprueba su etapa (no aprueba por nadie más). */
    private function ownerApprovesInternally(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/services/'.$this->project->services()->first()->id, ['approval_mode' => 'internal', 'internal_approver_ids' => [$this->owner->id]])->assertOk();
    }

    /** La persona carga 8 h (sin firmar nada todavía). */
    private function entry8(): int
    {
        Sanctum::actingAs($this->worker);
        return $this->postJson('/api/entries', $this->entry())->assertCreated()->json('timesheet_id');
    }

    private function sig(string $password = 'secret-password'): array
    {
        return ['signature' => $password];
    }

    public function test_submitting_a_sheet_needs_the_password_and_records_who_signed_it(): void
    {
        $this->ready();
        $id = $this->entry8();

        $this->postJson('/api/sheets/'.$id.'/submit')->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->postJson('/api/sheets/'.$id.'/submit', $this->sig('mala-clave'))->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->assertSame('draft', Timesheet::find($id)->status);          // sin firma no se envía nada
        $this->assertSame(0, Signature::count());

        $r = $this->postJson('/api/sheets/'.$id.'/submit', $this->sig())->assertOk()->assertJsonPath('status', 'submitted')
            ->assertJsonPath('signatures.0.action', 'sheet.submit')->assertJsonPath('signatures.0.by', 'Ana Torres')->assertJsonPath('signatures.0.method', 'password');
        $sig = Signature::firstOrFail();
        $this->assertSame([$this->worker->id, 'sheet.submit', 'timesheet', $id], [$sig->user_id, $sig->action, $sig->subject_type, $sig->subject_id]);
        $this->assertSame(64, strlen($sig->fingerprint));
        $this->assertSame(12, strlen($r->json('signatures.0.fingerprint')));
    }

    public function test_a_submission_that_fails_for_another_reason_leaves_no_signature(): void
    {
        $this->ready();
        Sanctum::actingAs($this->worker);
        $sheet = Timesheet::create(['company_id' => $this->company->id, 'user_id' => $this->worker->id, 'week_ending' => '2026-10-25', 'status' => 'draft', 'version' => 1]);
        $this->postJson('/api/sheets/'.$sheet->id.'/submit', $this->sig())->assertStatus(422);   // la hoja no tiene horas
        $this->assertSame(0, Signature::count());
    }

    public function test_the_owner_signs_approve_and_return_with_the_password(): void
    {
        $this->ready();
        $this->ownerApprovesInternally();
        $id = $this->entry8();
        $this->postJson('/api/sheets/'.$id.'/submit', $this->sig())->assertOk();

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$id.'/return', ['comment' => 'Falta el viernes'])->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->postJson('/api/sheets/'.$id.'/approve')->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->assertSame('submitted', Timesheet::find($id)->status);

        $this->postJson('/api/sheets/'.$id.'/return', $this->sig() + ['comment' => 'Falta el viernes'])->assertOk()->assertJsonPath('status', 'returned');
        $this->assertSame(['sheet.submit', 'sheet.return'], Signature::orderBy('id')->pluck('action')->all());

        Sanctum::actingAs($this->worker);
        $this->postJson('/api/sheets/'.$id.'/submit', $this->sig())->assertOk();   // corregida: se vuelve a firmar
        Sanctum::actingAs($this->owner);
        $r = $this->postJson('/api/sheets/'.$id.'/approve', $this->sig())->assertOk()->assertJsonPath('status', 'approved')->json();
        $this->assertSame(['sheet.submit', 'sheet.return', 'sheet.submit', 'sheet.approve'], Signature::orderBy('id')->pluck('action')->all());

        // El detalle muestra el ciclo actual: quién envió (otra vez) y quién aprobó.
        $detail = $this->getJson('/api/sheets/'.$id)->assertOk()->json('signatures');
        $this->assertSame(['sheet.submit', 'sheet.approve'], array_column($detail, 'action'));
        $this->assertSame(['Ana Torres', 'Luis'], array_column($detail, 'by'));
    }

    public function test_an_internal_approver_signs_with_the_password(): void
    {
        $this->ready();
        $sara = User::create(['company_id' => $this->company->id, 'name' => 'Sara Díaz', 'email' => 'sara@cerevelo.test', 'password' => 'secret-password',
            'role' => 'member', 'worker_type' => 'employee', 'capture_mode' => 'from_to']);
        foreach ($this->project->services()->get() as $svc) {
            $svc->update(['approval_mode' => 'internal']);
            $svc->internalApprovers()->attach($sara->id);
        }
        $id = $this->entry8();
        $this->postJson('/api/sheets/'.$id.'/submit', $this->sig())->assertOk();

        Sanctum::actingAs($sara);
        $version = Timesheet::find($id)->version;
        $this->postJson('/api/internal/sheets/'.$id.'/approve', ['version' => $version])->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->postJson('/api/internal/sheets/'.$id.'/approve', ['version' => $version] + $this->sig('otra'))->assertStatus(422);
        $this->postJson('/api/internal/sheets/'.$id.'/approve', ['version' => $version] + $this->sig())->assertOk();
        $this->assertSame('Sara Díaz', Signature::where('action', 'sheet.approve')->firstOrFail()->signer_name);
    }

    public function test_an_approver_account_signs_with_its_own_password(): void
    {
        $this->ready();
        $this->clientWithApprover($this->project);
        $account = User::create(['company_id' => $this->company->id, 'name' => 'Jorge', 'email' => 'cuenta@cliente.test', 'password' => 'otra-clave-larga-1',
            'role' => 'approver', 'worker_type' => 'employee', 'capture_mode' => 'full_day', 'approver_profile_id' => $this->approver->id]);
        $id = $this->entry8();
        $this->postJson('/api/sheets/'.$id.'/submit', $this->sig())->assertOk();

        Sanctum::actingAs($account);
        $v = Timesheet::find($id)->version;
        $this->postJson('/api/approver/sheets/'.$id.'/approve', ['version' => $v])->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->postJson('/api/approver/sheets/'.$id.'/approve', ['version' => $v] + $this->sig('secret-password'))->assertStatus(422);   // la de otra persona no sirve
        $this->postJson('/api/approver/sheets/'.$id.'/approve', ['version' => $v] + $this->sig('otra-clave-larga-1'))->assertOk();
        $this->assertSame($account->id, Signature::where('action', 'sheet.approve')->firstOrFail()->user_id);
    }

    public function test_wrong_passwords_lock_the_signature_for_a_while(): void
    {
        $this->ready();
        $id = $this->entry8();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/sheets/'.$id.'/submit', $this->sig('mala'))->assertStatus(422);
        }
        $this->postJson('/api/sheets/'.$id.'/submit', $this->sig())->assertStatus(429);   // ni la correcta firma
        $this->assertSame('draft', Timesheet::find($id)->status);
    }

    public function test_the_link_of_the_email_needs_the_emailed_code_and_the_approval_is_signed_by_that_code(): void
    {
        $this->ready();
        $this->clientWithApprover($this->project);
        $id = $this->entry8();
        $this->postJson('/api/sheets/'.$id.'/submit', $this->sig())->assertOk();
        $mail = Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.approval-request')->last();
        $plain = $this->tokenFromMail($mail->render());

        $this->get('/a/'.$plain)->assertOk()->assertSee('Verifica que eres tú')->assertDontSee('Ana Torres');
        $this->post('/a/'.$plain.'/approve')->assertSessionHasErrors('code');
        $this->assertSame('submitted', Timesheet::find($id)->status);
        $this->post('/a/'.$plain.'/code')->assertRedirect();
        preg_match('/>(\d{6})</', Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.approval-code')->last()->render(), $m);
        $this->post('/a/'.$plain.'/verify', ['code' => $m[1]])->assertRedirect();
        // Con el código verificado ya ve la hoja, con quién la envió y la firma.
        $this->get('/a/'.$plain)->assertOk()->assertSee('Ana Torres')->assertSee('firmada digitalmente');
        $this->post('/a/'.$plain.'/approve')->assertOk()->assertSee('Hoja aprobada');

        $sig = Signature::where('action', 'sheet.approve')->firstOrFail();
        $this->assertSame(['email_code', 'Jorge Ramos', null], [$sig->method, $sig->signer_name, $sig->user_id]);
        $this->assertSame($this->approver->id, (int) $sig->approver_id);
    }

    public function test_the_invoice_is_signed_with_the_password_and_the_pdf_says_who_signed_it(): void
    {
        $this->ready();
        $this->ownerApprovesInternally();
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Advance Logistics']);
        $this->project->update(['customer_id' => $customer->id]);
        ContractorTerms::create(['user_id' => $this->worker->id, 'rate' => 40, 'retention_pct' => 10, 'ivu_agent' => false, 'ivu_pct' => 0, 'terms_days' => 30]);
        $id = $this->entry8();
        $this->postJson('/api/sheets/'.$id.'/submit', $this->sig())->assertOk();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$id.'/approve', $this->sig())->assertOk();

        Sanctum::actingAs($this->worker);
        $body = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        $this->postJson('/api/contractor-invoices', $body)->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->postJson('/api/contractor-invoices', $body + $this->sig('mala'))->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->assertSame(0, \App\Models\ContractorInvoice::count());
        $inv = $this->postJson('/api/contractor-invoices', $body + $this->sig())->assertCreated()->json();

        $s = Signature::where('action', 'invoice.create')->firstOrFail();
        $this->assertSame([$this->worker->id, 'contractorinvoice', $inv['id'], 'Ana Torres'], [$s->user_id, $s->subject_type, $s->subject_id, $s->signer_name]);
        Storage::disk('local')->assertExists($inv['pdf_path']);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($inv['pdf_path']));
    }

    public function test_the_sheets_table_export_lists_the_digital_signatures(): void
    {
        $this->ready();
        $this->ownerApprovesInternally();
        $id = $this->entry8();
        $this->postJson('/api/sheets/'.$id.'/submit', $this->sig())->assertOk();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sheets/'.$id.'/approve', $this->sig())->assertOk();
        $csv = $this->get('/api/tables/sheets/export?format=csv&from=2026-10-01&to=2026-10-31')->assertOk()->getContent();
        $this->assertStringContainsString('Firmas digitales', $csv);
        $this->assertStringContainsString('Enviada: Ana Torres', $csv);
        $this->assertStringContainsString('Aprobada: Luis', $csv);
    }
}
