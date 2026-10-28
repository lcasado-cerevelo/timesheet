<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Timesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** Idioma de lo que genera el servidor: errores, correos, páginas públicas y plantillas de exportación. */
class LocaleTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function futureEntry(): array
    {
        return $this->entry(['work_date' => '2026-10-23']);   // "hoy" en las pruebas es 2026-10-21
    }

    public function test_validation_error_comes_in_the_users_language(): void
    {
        $this->world(['locale' => 'en']);
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->futureEntry())->assertStatus(422)
            ->assertJsonPath('errors.work_date.0', 'Hours cannot be recorded on future dates.');

        $this->worker->update(['locale' => 'es']);
        $this->postJson('/api/entries', $this->futureEntry())->assertStatus(422)
            ->assertJsonPath('errors.work_date.0', 'No se pueden registrar horas en fechas futuras.');
    }

    public function test_user_language_wins_over_company_language_and_company_is_the_fallback(): void
    {
        $this->world();                                   // compañía 'es', persona sin idioma propio
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->futureEntry())->assertJsonPath('errors.work_date.0', 'No se pueden registrar horas en fechas futuras.');
        $this->company->update(['locale' => 'en']);
        $this->worker->refresh()->unsetRelation('company');
        Sanctum::actingAs($this->worker->fresh());
        $this->postJson('/api/entries', $this->futureEntry())->assertJsonPath('errors.work_date.0', 'Hours cannot be recorded on future dates.');
    }

    private function decisionMail(string $workerLocale): BrandedMail
    {
        Mail::fake();
        $this->world(['locale' => $workerLocale]);
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        Sanctum::actingAs($this->owner);
        $this->approveSheet($e['timesheet_id']);
        return Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.sheet-decision')->last();
    }

    public function test_worker_email_in_english_for_an_english_worker(): void
    {
        $sent = $this->decisionMail('en');
        $this->assertSame('Sheet approved: week 2026-10-25', $sent->mailSubject);
        $this->assertStringContainsString('approved</strong>', $sent->render());
        $this->assertStringContainsString('Hello Ana Torres,', $sent->render());
    }

    public function test_worker_email_in_spanish_for_a_spanish_worker(): void
    {
        $sent = $this->decisionMail('es');
        $this->assertSame('Hoja aprobada: semana 2026-10-25', $sent->mailSubject);
        $this->assertStringContainsString('Hola Ana Torres,', $sent->render());
    }

    public function test_external_approver_gets_email_and_page_in_the_company_language(): void
    {
        Mail::fake();
        $this->world(['locale' => 'es']);                 // la persona prefiere español...
        $this->company->update(['locale' => 'en']);       // ...pero la compañía (y el aprobador externo) usan inglés
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();

        $sent = Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.approval-request')->last();
        $this->assertSame('Sheet to approve: Ana Torres, week 2026-10-25', $sent->mailSubject);
        $html = $sent->render();
        $this->assertStringContainsString('Review and decide', $html);

        $plain = $this->tokenFromMail($html);
        $this->get('/a/'.$plain)->assertOk()->assertSee('Daily detail')->assertSee('Return for correction')->assertDontSee('Detalle por día');
        $this->post('/a/'.$plain.'/approve')->assertOk()->assertSee('Sheet approved');
        // El enlace ya usado: la página de error también sale en el idioma de la compañía.
        $this->get('/a/'.$plain)->assertStatus(410)->assertSee('Cannot open');
    }

    public function test_approval_page_in_spanish_when_company_is_spanish(): void
    {
        Mail::fake();
        $this->world(['locale' => 'en']);                 // la persona en inglés, la compañía en español
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        $plain = $this->tokenFromMail(Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.approval-request')->last()->render());
        $this->get('/a/'.$plain)->assertOk()->assertSee('Detalle por día')->assertDontSee('Daily detail');
    }

    public function test_user_can_change_their_language(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);
        $this->putJson('/api/me/locale', ['locale' => 'en'])->assertOk()->assertJsonPath('locale', 'en');
        $this->assertSame('en', $this->worker->fresh()->locale);
        $this->putJson('/api/me/locale', ['locale' => 'fr'])->assertStatus(422);
        $this->getJson('/api/me')->assertJsonPath('locale', 'en')->assertJsonPath('company_locale', 'es');
    }

    public function test_login_screen_errors_follow_the_requested_language(): void
    {
        $this->world();
        $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => 'mala'], ['X-Locale' => 'en'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Incorrect email or password.');
        $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => 'mala'], ['X-Locale' => 'es'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Correo o contraseña incorrectos.');
    }

    public function test_consultorio_1500_stays_in_spanish_and_other_system_templates_follow_the_language(): void
    {
        $this->world();
        $this->company->update(['locale' => 'en']);
        $this->owner->update(['locale' => 'en']);
        Sanctum::actingAs($this->owner->fresh());
        $tpls = collect($this->getJson('/api/export-templates')->assertOk()->json());

        $c = $tpls->firstWhere('slug', 'consultorio-1500');
        $this->assertSame('Consultorio 1500', $c['name']);
        $this->assertSame('Atendidos {year}', $c['config']['sheet_name']);
        $this->assertSame(
            ['Nombre del asegurado', 'Contrato', 'Plan medico', 'Facturada con plan', 'Facturada sin plan', 'Fecha', 'Descripcion'],
            array_column($c['config']['columns'], 'header')
        );

        $h = $tpls->firstWhere('slug', 'horas-por-dia');
        $this->assertSame('Hours per day (detail)', $h['name']);
        $this->assertSame(['Date', 'Person', 'Project', 'From', 'To', 'Hours', 'Note'], array_column($h['config']['columns'], 'header'));

        // Con español salen los nombres originales (en la base siempre están en español).
        $this->owner->update(['locale' => 'es']);
        Sanctum::actingAs($this->owner->fresh());
        $es = collect($this->getJson('/api/export-templates')->json())->firstWhere('slug', 'horas-por-dia');
        $this->assertSame('Horas por día (detalle)', $es['name']);
        $this->assertSame('Fecha', $es['config']['columns'][0]['header']);
        $this->assertSame('Horas por día (detalle)', \App\Models\ExportTemplate::where('slug', 'horas-por-dia')->value('name'));
    }

    public function test_en_json_is_valid_and_covers_every_translatable_string_in_the_code(): void
    {
        $raw = file_get_contents(base_path('lang/en.json'));
        $en = json_decode($raw, true);
        $this->assertIsArray($en, 'lang/en.json no es JSON válido: '.json_last_error_msg());
        $this->assertNotEmpty($en);

        // JSON con claves repetidas: json_decode se queda con la última, así que se cuentan a mano.
        preg_match_all('/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*:/m', $raw, $m);
        $keys = array_map(fn ($k) => json_decode('"'.$k.'"'), $m[1]);
        $this->assertSame(count($keys), count(array_unique($keys)), 'hay claves duplicadas en lang/en.json: '.implode(' | ', array_keys(array_filter(array_count_values($keys), fn ($n) => $n > 1))));

        $used = [];
        foreach (['app', 'routes', 'resources/views'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                if (preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", file_get_contents($file->getPathname()), $mm)) {
                    foreach ($mm[1] as $k) {
                        $used[] = str_replace("\\'", "'", $k);
                    }
                }
            }
        }
        $used = array_unique($used);
        $this->assertGreaterThan(100, count($used));
        $this->assertSame([], array_values(array_diff($used, array_keys($en))), 'textos con __() sin traducción en lang/en.json');
        $this->assertSame([], array_values(array_diff(array_keys($en), $used)), 'claves de lang/en.json que ya no se usan en el código');
        foreach ($en as $k => $v) {
            $this->assertNotSame('', trim((string) $v), "traducción vacía: $k");
            // Los marcadores :nombre deben conservarse en la traducción.
            preg_match_all('/:[a-z]+/', $k, $a);
            foreach ($a[0] as $ph) {
                $this->assertStringContainsString($ph, $v, "falta el marcador $ph en la traducción de: $k");
            }
        }
    }
}
