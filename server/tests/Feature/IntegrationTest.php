<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\ContractorTerms;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private Customer $customer;

    /** Dos semanas aprobadas: 8 h el 20/10 y 4 h el 13/10 en el proyecto P-1 (tarifa 50). */
    private function approvedHours(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->world();
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Advance Logistics', 'accountisi_id' => '77']);
        $this->project->update(['customer_id' => $this->customer->id]);
        ContractorTerms::create(['user_id' => $this->worker->id, 'rate' => 40, 'retention_pct' => 10, 'ivu_agent' => true,
            'ivu_pct' => 4, 'terms_days' => 30, 'accountisi_vendor_id' => '9']);
        $this->owner->update(['approver_id' => null]);

        foreach (['2026-10-20', '2026-10-13'] as $i => $day) {
            Sanctum::actingAs($this->worker);
            $e = $this->postJson('/api/entries', $this->entry(['work_date' => $day, 'start_time' => '08:00', 'end_time' => $i ? '12:00' : '16:00',
                'note' => 'Trabajo '.$day]))->assertCreated()->json();
            $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
            Sanctum::actingAs($this->owner);
            $this->approveSheet($e['timesheet_id']);
        }
        Sanctum::actingAs($this->owner);
    }

    private function configure(array $over = []): void
    {
        $this->putJson('/api/integrations/accountisi', $over + [
            'enabled' => true, 'base_url' => 'http://localhost:5099', 'auth_mode' => 'login', 'login' => 'servicio',
            'password' => 'clave-secreta', 'company_id' => 1, 'default_item_id' => 5, 'default_service_item_id' => 6,
        ])->assertOk();
    }

    private function fakeAccountisi(int $invoiceStatus = 0): void
    {
        Cache::flush();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            '*/api/auth/login' => Http::response(['token' => '3f1c0000-0000-4000-8000-000000000001', 'expiresAt' => now()->addHour()]),
            '*/api/me' => Http::response(['login' => 'servicio']),
            '*/api/setup/customers*' => Http::response(['documentos' => [
                ['customerId' => 77, 'code' => 'ADV', 'name' => 'Advance Logistics', 'email' => 'a@adv.test', 'total' => 2],
                ['customerId' => 78, 'code' => 'INN', 'name' => 'InnovaSyst', 'email' => null, 'total' => 2],
            ]]),
            '*/api/invoices' => Http::response(['invoiceId' => 501], 201),
            '*/api/invoices/501' => Http::response(['cabecera' => ['customerId' => 77, 'status' => $invoiceStatus, 'description' => 'Previa']]),
            '*/api/invoices/501/lines' => Http::response(['ok' => true], 201),
            '*/api/bills' => Http::response(['billId' => 801], 201),
            '*/api/bills/801/lines' => Http::response(['ok' => true], 201),
        ]);
    }

    public function test_credentials_are_never_returned(): void
    {
        $this->approvedHours();
        $this->configure();
        $r = $this->getJson('/api/integrations/accountisi')->assertOk()->assertJsonPath('has_password', true);
        $this->assertStringNotContainsString('clave-secreta', $r->getContent());
        $this->assertNotSame('clave-secreta', Integration::first()->getRawOriginal('config'));   // cifrada en la base
        $this->configure(['password' => null]);                                              // se conserva
        $this->assertSame('clave-secreta', Integration::first()->config['password']);
        Sanctum::actingAs($this->worker);
        $this->getJson('/api/integrations/accountisi')->assertForbidden();
    }

    public function test_connection_test_syncs_customers_by_name_and_creates_the_missing_ones(): void
    {
        $this->approvedHours();
        $this->customer->update(['accountisi_id' => null]);
        $this->configure();
        $this->fakeAccountisi();

        $this->postJson('/api/integrations/accountisi/test')->assertOk()->assertJsonPath('ok', true);
        $this->postJson('/api/integrations/accountisi/sync-customers')->assertOk()->assertJsonPath('linked', 1)->assertJsonPath('created', 1);
        $this->assertSame('77', $this->customer->fresh()->accountisi_id);
        $this->assertDatabaseHas('customers', ['name' => 'InnovaSyst', 'accountisi_id' => '78']);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer 3f1c0000-0000-4000-8000-000000000001'));
    }

    public function test_sales_invoice_has_project_in_comment_and_hours_are_marked_billed(): void
    {
        $this->approvedHours();
        $this->configure();
        $this->fakeAccountisi();
        $body = ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31'];

        $p = $this->postJson('/api/integrations/accountisi/sales-preview', $body)->assertOk()
            ->assertJsonPath('total_hours', 12)->assertJsonPath('amount', 600)->json();
        $this->assertSame('Proyecto: P-1 Planta A', $p['comment']);
        Http::assertNothingSent();   // la vista previa no toca Accountisi

        $this->postJson('/api/integrations/accountisi/sales-invoice', $body + ['comment' => 'Octubre'])->assertCreated()
            ->assertJsonPath('accountisi_invoice_id', '501');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://localhost:5099/api/invoices'
            && $r['CustomerId'] === 77 && str_contains($r['Description'], 'Proyecto: P-1 Planta A') && str_contains($r['Description'], 'Octubre'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/invoices/501/lines') && $r['Quantity'] == 8 && $r['UnitPrice'] == 50 && $r['ItemId'] === 5);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/invoices/501/lines') && $r['Quantity'] == 4 && $r['UnitPrice'] == 50 && $r['ItemId'] === 5);
        $this->assertSame(0, TimeEntry::whereNull('sales_sync_id')->count());

        // No se vuelve a facturar lo ya facturado.
        $this->postJson('/api/integrations/accountisi/sales-invoice', $body)->assertStatus(422)->assertJsonValidationErrors('from');
    }

    public function test_invoice_detail_follows_how_the_hours_were_entered(): void
    {
        $this->approvedHours();
        $this->configure();
        $body = ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31'];

        // Entradas hora por hora: una línea por entrada, con desde–hasta. El ajuste viejo del proyecto ya no cuenta.
        $this->project->update(['invoice_detail' => 'hours_total']);
        $lines = $this->postJson('/api/integrations/accountisi/sales-preview', $body)->json('lines');
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('2026-10-13 08:00–12:00', $lines[0]['description']);
        $this->assertStringContainsString('2026-10-20 08:00–16:00', $lines[1]['description']);
        $this->assertEquals([4, 8], [$lines[0]['quantity'], $lines[1]['quantity']]);
        $this->assertEquals([50, 50], [$lines[0]['unit_price'], $lines[1]['unit_price']]);

        // Entradas por total del día: una línea por día con el total de ese día (dos entradas del mismo día se suman).
        $e = TimeEntry::where('work_date', '2026-10-20')->first();
        \Illuminate\Support\Facades\DB::table('time_entries')->insert(array_merge($e->getAttributes(), ['id' => null, 'hours' => 2, 'start_time' => null, 'end_time' => null]));
        TimeEntry::where('id', $e->id)->update(['start_time' => null, 'end_time' => null]);
        $lines = $this->postJson('/api/integrations/accountisi/sales-preview', $body)->json('lines');
        $this->assertCount(2, $lines);                                   // 13/10 por horario + 20/10 por total
        $this->assertStringContainsString('2026-10-13 08:00–12:00', $lines[0]['description']);
        $this->assertStringEndsWith('2026-10-20', $lines[1]['description']);
        $this->assertEquals(10, $lines[1]['quantity']);                  // 8 + 2
        $this->assertCount(2, $lines[1]['entry_ids']);
    }

    public function test_append_to_an_open_invoice_of_the_same_customer_only(): void
    {
        $this->approvedHours();
        $this->configure();
        $this->fakeAccountisi();
        $body = ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'mode' => 'append', 'accountisi_invoice_id' => '501'];
        $this->postJson('/api/integrations/accountisi/sales-invoice', $body)->assertCreated();
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === 'http://localhost:5099/api/invoices');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && str_contains($r['Description'], 'Previa'));

        // Factura ya posteada: se rechaza y las horas siguen sin facturar.
        $this->fakeAccountisi(1);
        TimeEntry::query()->update(['sales_sync_id' => null]);
        $this->postJson('/api/integrations/accountisi/sales-invoice', $body)->assertStatus(422);
        $this->assertSame(2, TimeEntry::whereNull('sales_sync_id')->count());
    }

    public function test_failure_midway_does_not_mark_hours_as_billed(): void
    {
        $this->approvedHours();
        $this->configure();
        Http::fake([
            '*/api/auth/login' => Http::response(['token' => 'tok']),
            '*/api/invoices' => Http::response(['invoiceId' => 600], 201),
            '*/api/invoices/600/lines' => Http::response(['title' => 'Artículo inválido'], 400),
        ]);
        $this->postJson('/api/integrations/accountisi/sales-invoice', ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertStatus(422)->assertJsonValidationErrors('accountisi');
        $this->assertSame(2, TimeEntry::whereNull('sales_sync_id')->count());
        $this->assertDatabaseHas('sales_invoice_syncs', ['status' => 'failed', 'accountisi_invoice_id' => '600']);
    }

    public function test_project_without_rate_blocks_invoicing_and_unlinked_customer_is_refused(): void
    {
        $this->approvedHours();
        $this->configure();
        $this->project->update(['bill_rate' => null]);
        \App\Models\TimeEntry::query()->update(['bill_rate' => null]);   // la tarifa también se guarda en cada entrada
        $this->postJson('/api/integrations/accountisi/sales-invoice', ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertStatus(422)->assertJsonValidationErrors('project_ids');
        $this->customer->update(['accountisi_id' => null]);
        $this->postJson('/api/integrations/accountisi/sales-invoice', ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }

    public function test_integration_off_gives_a_clear_error(): void
    {
        $this->approvedHours();
        $this->postJson('/api/integrations/accountisi/sales-invoice', ['customer_id' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertStatus(422)->assertJsonValidationErrors('integration');
    }

    public function test_consultant_invoice_pdf_email_and_draft_bill(): void
    {
        $this->approvedHours();
        $this->configure();
        $this->fakeAccountisi();
        Sanctum::actingAs($this->worker);

        $inv = $this->postJson('/api/contractor-invoices', ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertCreated()
            ->assertJsonPath('gross', '480.00')->assertJsonPath('retention', '48.00')->assertJsonPath('ivu', '19.20')
            ->assertJsonPath('net', '412.80')->assertJsonPath('accountisi_bill_id', '801')->assertJsonPath('accountisi_status', 'draft')
            ->assertJsonPath('status', 'sent')->json();   // 12 h x 40
        $this->assertSame('INV-2026-0001', $inv['number']);
        Storage::disk('local')->assertExists($inv['pdf_path']);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($inv['pdf_path']));
        Mail::assertSent(BrandedMail::class, fn ($m) => $m->hasTo('luis@cerevelo.test') && $m->viewName === 'mail.contractor-invoice');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'http://localhost:5099/api/bills' && $r['VendorId'] === 9 && $r['VendorInvoiceNumber'] === 'INV-2026-0001');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/bills/801/lines') && $r['Quantity'] == 12 && $r['UnitPrice'] == 40 && $r['ItemId'] === 6);
        $this->assertSame(0, Timesheet::whereNull('contractor_invoice_id')->count());

        $this->postJson('/api/contractor-invoices', ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertStatus(422);   // ya facturado
        $this->get('/api/contractor-invoices/'.$inv['id'].'/pdf')->assertOk();
    }

    public function test_employees_cannot_generate_invoices(): void
    {
        $this->approvedHours();
        $this->worker->update(['worker_type' => 'employee']);
        Sanctum::actingAs($this->worker->fresh());
        $this->postJson('/api/contractor-invoices', ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertStatus(422)->assertJsonValidationErrors('user');
    }

    public function test_accountisi_outage_keeps_the_invoice_and_allows_retry(): void
    {
        $this->approvedHours();
        $this->configure();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response('boom', 500)]);
        Sanctum::actingAs($this->worker);
        $inv = $this->postJson('/api/contractor-invoices', ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertCreated()
            ->assertJsonPath('accountisi_status', 'error')->json();
        $this->assertNull($inv['accountisi_bill_id']);

        $this->fakeAccountisi();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/contractor-invoices/'.$inv['id'].'/push-accountisi')->assertOk()->assertJsonPath('accountisi_bill_id', '801');
    }

    public function test_other_company_cannot_see_the_invoice(): void
    {
        $this->approvedHours();
        Sanctum::actingAs($this->worker);
        $inv = $this->postJson('/api/contractor-invoices', ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertCreated()->json();
        $c2 = \App\Models\Company::create(['name' => 'Otra', 'slug' => 'otra', 'locale' => 'es']);
        $u2 = \App\Models\User::create(['company_id' => $c2->id, 'name' => 'Otro', 'email' => 'o@o.test', 'password' => 'secret-password', 'role' => 'owner']);
        Sanctum::actingAs($u2);
        $this->get('/api/contractor-invoices/'.$inv['id'].'/pdf')->assertForbidden();
        $this->assertSame([], $this->getJson('/api/contractor-invoices')->json());
    }
}
