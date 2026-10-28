<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Timesheet;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** Lo que hace falta para publicar detrás de un balanceador de AWS, con correo externo y programador disparado desde fuera. */
class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private const KEY = 'una-clave-larga-para-el-programador-0123456789';

    protected function tearDown(): void
    {
        foreach (['TRUSTED_PROXIES', 'CRON_KEY'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        parent::tearDown();
    }

    // ---------------- programador disparado desde fuera ----------------

    public function test_cron_endpoint_does_not_exist_without_a_long_key(): void
    {
        config(['tiempo.cron_key' => null]);
        $this->postJson('/api/cron/run', [], ['X-Cron-Key' => 'x'])->assertNotFound();
        config(['tiempo.cron_key' => 'corta']);
        $this->postJson('/api/cron/run', [], ['X-Cron-Key' => 'corta'])->assertNotFound();      // una clave débil desactiva el acceso
    }

    public function test_cron_endpoint_rejects_a_wrong_or_missing_key(): void
    {
        config(['tiempo.cron_key' => self::KEY]);
        $this->postJson('/api/cron/run')->assertForbidden();
        $this->postJson('/api/cron/run', [], ['X-Cron-Key' => 'otra-clave-distinta-de-treinta-y-dos-caracteres'])->assertForbidden();
        $this->getJson('/api/cron/run', ['X-Cron-Key' => self::KEY])->assertStatus(405);       // solo POST
    }

    public function test_cron_endpoint_runs_the_scheduler_so_a_pending_change_notice_is_sent(): void
    {
        Mail::fake();
        $this->world();
        config(['tiempo.cron_key' => self::KEY]);
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        // Un aviso de cambio pendiente (de antes de que las hojas enviadas se bloquearan): el cron lo manda.
        Timesheet::whereKey($e['timesheet_id'])->update(['version' => 2, 'change_notice_due_at' => now()->subMinute()]);
        Mail::assertSentCount(1);

        app('auth')->forgetGuards();
        $this->postJson('/api/cron/run', [], ['X-Cron-Key' => self::KEY])->assertOk()->assertJsonPath('ok', true);
        Mail::assertSentCount(2);                                                              // salió el aviso de "la hoja cambió"
        $this->assertCount(1, Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.approval-request' && str_contains($m->mailSubject, 'cambió')));
    }

    public function test_cron_endpoint_sends_the_overdue_reminder_only_once_a_day(): void
    {
        Mail::fake();
        $this->world();                                   // miércoles 2026-10-21 10:00 en Puerto Rico
        config(['tiempo.cron_key' => self::KEY]);
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        Timesheet::whereKey($e['timesheet_id'])->update(['submitted_at' => now()->subDays(8)]);    // lleva 8 días sin decisión
        Mail::fake();                                     // se cuentan solo los avisos del programador
        app('auth')->forgetGuards();

        $this->postJson('/api/cron/run', [], ['X-Cron-Key' => self::KEY])->assertOk();
        $this->postJson('/api/cron/run', [], ['X-Cron-Key' => self::KEY])->assertOk();            // otra llamada el mismo día
        $digests = Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.pending-digest');
        $this->assertCount(1, $digests);
        $this->assertTrue($digests->first()->hasTo('jorge@cliente.test'));
    }

    public function test_cron_endpoint_waits_for_the_reminder_hour(): void
    {
        Mail::fake();
        $this->world();
        config(['tiempo.cron_key' => self::KEY]);
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        Timesheet::whereKey($e['timesheet_id'])->update(['submitted_at' => now()->subDays(8)]);
        Mail::fake();
        app('auth')->forgetGuards();
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-21 06:00:00', 'America/Puerto_Rico'));   // antes de las 8:00

        $this->postJson('/api/cron/run', [], ['X-Cron-Key' => self::KEY])->assertOk();
        Mail::assertNotSent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.pending-digest');
    }

    // ---------------- HTTPS detrás del balanceador ----------------

    public function test_links_use_https_when_app_url_is_https_even_without_proxy_headers(): void
    {
        config(['app.url' => 'https://tiempo.cliente.test']);
        (new AppServiceProvider($this->app))->boot();
        $this->assertStringStartsWith('https://', url('/a/abc'));
        URL::forceScheme('http');   // deja el estado como estaba para otras pruebas
    }

    public function test_the_proxy_header_is_only_trusted_when_configured(): void
    {
        // Sin TRUSTED_PROXIES: un encabezado X-Forwarded-Proto no cuenta (no se puede falsear el esquema).
        Route::get('/_esquema', fn () => request()->isSecure() ? 'https' : 'http');
        $this->get('/_esquema', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.5'])->assertSee('http', false)->assertDontSee('https');

        // Con TRUSTED_PROXIES=* (contenedor detrás del balanceador) sí cuenta.
        putenv('TRUSTED_PROXIES=*');
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '*';
        $this->refreshApplication();
        Route::get('/_esquema', fn () => request()->isSecure() ? 'https' : 'http');
        $this->get('/_esquema', ['X-Forwarded-Proto' => 'https'])->assertSee('https', false);
    }

    // ---------------- correo (Brevo u otro) ----------------

    public function test_mail_test_command_reports_success(): void
    {
        Mail::fake();
        $this->artisan('tiempo:probar-correo', ['destino' => 'yo@cerevelo.test'])->expectsOutputToContain('Enviado a yo@cerevelo.test')->assertSuccessful();
    }

    public function test_mail_test_command_explains_a_failure(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
        $this->artisan('tiempo:probar-correo', ['destino' => 'yo@cerevelo.test'])->expectsOutputToContain('No se pudo enviar')->assertFailed();
    }
}
