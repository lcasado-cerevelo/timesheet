<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\PasswordToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** Cabeceras de seguridad, tope absoluto de la sesión, código por correo del dueño y reglas de contraseña. */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function realMail(): void
    {
        config(['mail.default' => 'smtp']);   // en las pruebas el correo es "array": el código solo se exige si hay correo de verdad
        Mail::fake();
    }

    // ---------------------------------------------------------------- cabeceras

    public function test_every_response_carries_the_security_headers(): void
    {
        $this->world();
        $r = $this->getJson('/api/public-config')->assertOk();
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $r->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $r->headers->get('Referrer-Policy'));
        $csp = (string) $r->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("script-src 'self' https://challenges.cloudflare.com", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'), 'la API no se guarda en cachés');
        $this->assertNull($r->headers->get('Strict-Transport-Security'), 'HSTS solo con HTTPS');
    }

    public function test_hsts_is_sent_over_https_and_the_pages_keep_their_own_headers(): void
    {
        $this->world();
        $r = $this->getJson('https://localhost/api/public-config')->assertOk();
        $this->assertStringContainsString('max-age=', (string) $r->headers->get('Strict-Transport-Security'));
        // La página de aprobación trae su propia cabecera de marcos y no se pisa.
        $this->get('/a/'.str_repeat('x', 48))->assertStatus(410);
    }

    // ---------------------------------------------------------------- tope de sesión

    /** GET /api/me con ese token, como una petición nueva (el guardia de autenticación de la prueba recuerda al usuario anterior). */
    private function meWith(string $plain)
    {
        $this->app['auth']->forgetGuards();
        return $this->withToken($plain)->getJson('/api/me');
    }

    private function tokenAged(User $u, int $days, int $touchedDaysAgo = 0): string
    {
        $plain = $u->createToken('web')->plainTextToken;
        $t = PersonalAccessToken::findToken($plain);
        $t->forceFill(['created_at' => now()->subDays($days), 'last_used_at' => now()->subDays($touchedDaysAgo)])->save();
        return $plain;
    }

    public function test_a_session_dies_at_its_absolute_limit_even_if_it_was_used_today(): void
    {
        $this->world();
        // Dueño: 7 días; el resto: 14.
        $young = $this->tokenAged($this->owner, 6);
        $this->meWith($young)->assertOk();
        $old = $this->tokenAged($this->owner, 8);
        $r = $this->meWith($old)->assertStatus(401);
        $this->assertSame('session_expired', $r->json('code'));
        $this->assertStringContainsString('duración máxima', $r->json('message'));
        $this->assertNull(PersonalAccessToken::findToken($old), 'el token se borra');

        $workerOk = $this->tokenAged($this->worker, 10);
        $this->meWith($workerOk)->assertOk();
        $workerOld = $this->tokenAged($this->worker, 15);
        $this->meWith($workerOld)->assertStatus(401);
    }

    public function test_the_limits_are_rules_and_zero_means_no_limit(): void
    {
        $this->world();
        $this->company->update(['rules' => ['session_max_days_office' => 0, 'session_max_days' => 3]]);
        $this->meWith($this->tokenAged($this->owner, 60))->assertOk();            // 0 = sin tope
        $this->meWith($this->tokenAged($this->worker, 4))->assertStatus(401);       // 3 días

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/company', ['rules' => ['session_max_days' => 400]])->assertStatus(422);
        $this->putJson('/api/company', ['rules' => ['session_max_days' => 5, 'session_max_days_office' => 2, 'two_step_office' => false]])->assertOk()
            ->assertJsonPath('rules.session_max_days', 5)->assertJsonPath('rules.two_step_office', false);
    }

    public function test_inactivity_still_applies_together_with_the_limit(): void
    {
        $this->world();
        $this->company->update(['rules' => ['remember_days' => 1]]);
        $this->meWith($this->tokenAged($this->worker, 3, 2))->assertStatus(401);   // 2 días sin usar con límite de 1
    }

    // ---------------------------------------------------------------- código por correo del dueño

    public function test_the_owner_needs_the_emailed_code_on_the_web_and_on_the_phone(): void
    {
        $this->world();
        $this->realMail();
        foreach (['/api/login/web', '/api/login'] as $path) {
            $r = $this->postJson($path, ['email' => 'luis@cerevelo.test', 'password' => 'secret-password'])->assertStatus(202);
            $this->assertSame('code', $r->json('step'));
            $this->assertNull($r->json('token'));
        }
        Mail::assertSent(BrandedMail::class, 2);
    }

    public function test_the_code_completes_the_login_and_a_remembered_device_skips_it_later(): void
    {
        $this->world();
        $this->realMail();
        $r = $this->postJson('/api/login', ['email' => 'luis@cerevelo.test', 'password' => 'secret-password', 'device' => 'phone'])->assertStatus(202)->json();
        $html = Mail::sent(BrandedMail::class)->last()->render();
        preg_match('/\b(\d{6})\b/', $html, $m);
        $code = $m[1];
        $ok = $this->postJson('/api/login/code', ['challenge' => $r['challenge'], 'code' => $code, 'remember' => true, 'device' => 'phone'])->assertOk()->json();
        $this->assertNotEmpty($ok['token']);
        $this->assertNotEmpty($ok['device_token']);
        $this->assertSame('luis@cerevelo.test', $ok['user']['email']);

        // Con el equipo recordado no vuelve a pedir el código.
        $this->postJson('/api/login', ['email' => 'luis@cerevelo.test', 'password' => 'secret-password', 'device_token' => $ok['device_token']])->assertOk()->assertJsonStructure(['token']);
        // Un código equivocado no entra.
        $r2 = $this->postJson('/api/login/web', ['email' => 'luis@cerevelo.test', 'password' => 'secret-password'])->assertStatus(202)->json();
        $this->postJson('/api/login/code', ['challenge' => $r2['challenge'], 'code' => '000000'])->assertStatus(422);
    }

    public function test_the_remembered_device_is_not_valid_longer_than_the_session_of_that_account(): void
    {
        $this->world();
        $this->realMail();
        $this->company->update(['rules' => ['remember_days' => 30, 'session_max_days_office' => 7]]);
        $r = $this->postJson('/api/login', ['email' => 'luis@cerevelo.test', 'password' => 'secret-password'])->assertStatus(202)->json();
        preg_match('/\b(\d{6})\b/', Mail::sent(BrandedMail::class)->last()->render(), $m);
        $this->postJson('/api/login/code', ['challenge' => $r['challenge'], 'code' => $m[1], 'remember' => true])->assertOk();
        $dev = \App\Models\TrustedDevice::firstOrFail();
        $this->assertLessThanOrEqual(7, (int) ceil(now()->floatDiffInDays($dev->expires_at)));
    }

    public function test_workers_do_not_need_the_code_and_the_rule_can_turn_it_off(): void
    {
        $this->world();
        $this->realMail();
        $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => 'secret-password'])->assertOk()->assertJsonStructure(['token']);
        $this->company->update(['rules' => ['two_step_office' => false]]);
        $this->postJson('/api/login/web', ['email' => 'luis@cerevelo.test', 'password' => 'secret-password'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_without_real_mail_the_owner_can_still_get_in(): void
    {
        $this->world();   // el correo de las pruebas es "array": sin correo no se puede enviar el código y no se exige
        $this->postJson('/api/login/web', ['email' => 'luis@cerevelo.test', 'password' => 'secret-password'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_the_command_turns_the_owner_code_on_and_off(): void
    {
        $this->world();
        $this->artisan('tiempo:two-step off')->assertSuccessful();
        $this->assertFalse((bool) $this->company->refresh()->rules()->get('two_step_office'));
        $this->artisan('tiempo:two-step on')->assertSuccessful();
        $this->assertTrue((bool) $this->company->refresh()->rules()->get('two_step_office'));
        $this->artisan('tiempo:two-step quizas')->assertFailed();
    }

    // ---------------------------------------------------------------- contraseñas

    public function test_common_passwords_and_obvious_patterns_are_refused_when_changing_the_password(): void
    {
        $this->world(['email' => 'anatorres@cerevelo.test']);
        Sanctum::actingAs($this->worker);
        foreach (['password123', '1234567890', 'aaaaaaaaaaaa', '0123456789', 'abcabcabcabc', 'Qwertyuiop', 'anatorres@cerevelo.test', 'xxanatorresxx'] as $weak) {
            $this->postJson('/api/password/change', ['current_password' => 'secret-password', 'password' => $weak])->assertStatus(422)->assertJsonValidationErrors('password');
        }
        $this->postJson('/api/password/change', ['current_password' => 'secret-password', 'password' => 'Tr0mpeta-Azul-Lejana'])->assertOk();
    }

    public function test_a_password_cannot_contain_the_email_name(): void
    {
        $this->world(['email' => 'anatorres@cerevelo.test']);
        Sanctum::actingAs($this->worker);
        $r = $this->postJson('/api/password/change', ['current_password' => 'secret-password', 'password' => 'my-anatorres-pass-99'])->assertStatus(422);
        $this->assertStringContainsString('contener tu correo', $r->json('errors.password.0'));
    }

    public function test_the_same_rules_apply_when_creating_the_first_password_from_the_invitation_link(): void
    {
        $this->world();
        $plain = app(\App\Services\AccountService::class)->issueToken($this->worker, 'setup');
        $this->postJson('/api/password/setup', ['token' => $plain, 'password' => 'password1234'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/password/setup', ['token' => $plain, 'password' => 'Un-Nuevo-Café-Frío-77'])->assertOk();
    }

    private function hibp($response): void
    {
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        \Illuminate\Support\Facades\Http::fake(['api.pwnedpasswords.com/*' => $response]);
    }

    public function test_a_leaked_password_is_detected_and_a_service_that_does_not_answer_blocks_nobody(): void
    {
        $this->hibp(\Illuminate\Support\Facades\Http::response(substr(strtoupper(sha1('Filtrada-Pero-Larga-1')), 5).":4821
0018A45C4D1DEF81644B54AB7F969B88D65:0
"));
        $this->assertTrue(\App\Support\PasswordPolicy::pwned('Filtrada-Pero-Larga-1'));
        $this->assertFalse(\App\Support\PasswordPolicy::pwned('Otra-Que-No-Esta-1'));
        $this->hibp(\Illuminate\Support\Facades\Http::response('error', 500));
        $this->assertFalse(\App\Support\PasswordPolicy::pwned('Filtrada-Pero-Larga-1'));
        $this->hibp(fn () => throw new \Illuminate\Http\Client\ConnectionException('sin red'));
        $this->assertFalse(\App\Support\PasswordPolicy::pwned('Filtrada-Pero-Larga-1'));
    }
}
