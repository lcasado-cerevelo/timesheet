<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

class LoginProtectionTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private const OK = ['email' => 'ana@cerevelo.test', 'password' => 'secret-password'];

    private function turnstileOn(): void
    {
        config(['services.turnstile.site_key' => '1x00000000000000000000AA', 'services.turnstile.secret' => '1x0000000000000000000000000000000AA']);
    }

    // ---------------- bloqueo por intentos fallidos ----------------

    public function test_five_failures_lock_that_email_from_that_ip_and_a_success_clears_the_count(): void
    {
        $this->world();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => 'mala'])->assertStatus(422);
        }
        $this->postJson('/api/login', self::OK)->assertOk();                                     // un acierto reinicia la cuenta
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => 'mala'])->assertStatus(422);
        }
        // Ya bloqueada: ni la clave correcta pasa mientras dure el bloqueo.
        $r = $this->postJson('/api/login', self::OK, ['X-Locale' => 'es'])->assertStatus(429)->assertJsonPath('code', 'locked');
        $this->assertStringContainsString('minuto', $r->json('message'));
    }

    public function test_the_web_login_shares_the_lock_with_the_mobile_login(): void
    {
        $this->world();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => 'mala'])->assertStatus(422);
        }
        $this->postJson('/api/login/web', self::OK)->assertStatus(429)->assertJsonPath('code', 'locked');
    }

    public function test_the_route_also_limits_requests_per_ip_per_minute(): void
    {
        $this->world();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['email' => 'quien'.$i.'@x.test', 'password' => 'mala']);
        }
        $this->postJson('/api/login', self::OK)->assertStatus(429);                              // la capa de la ruta, aunque este correo no falló
    }

    public function test_the_lock_is_per_email_and_per_ip_so_it_cannot_lock_out_the_real_owner_elsewhere(): void
    {
        $this->world();
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => 'mala']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/login', self::OK)->assertStatus(429);   // el atacante, bloqueado
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->postJson('/api/login', self::OK)->assertOk();          // ella, desde su sitio, entra
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/login', ['email' => 'luis@cerevelo.test', 'password' => 'secret-password'])->assertOk();   // otro correo, no afectado
    }

    public function test_unknown_users_and_inactive_users_fail_the_same_way_and_also_count(): void
    {
        $this->world();
        $this->worker->update(['active' => false]);
        $a = $this->postJson('/api/login', ['email' => 'nadie@x.test', 'password' => 'secret-password'])->assertStatus(422);
        $b = $this->postJson('/api/login', self::OK)->assertStatus(422);                         // existe pero está desactivada
        $this->assertSame($a->json('errors.email'), $b->json('errors.email'));                   // mismo mensaje: no delata cuáles existen
        RateLimiter::clear('login:nadie@x.test|127.0.0.1');
    }

    // ---------------- Cloudflare Turnstile en el login web ----------------

    public function test_without_turnstile_keys_the_web_login_works_and_reports_no_site_key(): void
    {
        $this->world();
        $this->getJson('/api/public-config')->assertOk()->assertJsonPath('turnstile_site_key', null);
        $this->postJson('/api/login/web', self::OK)->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_with_turnstile_the_web_login_needs_a_valid_token(): void
    {
        $this->world();
        $this->turnstileOn();
        $this->getJson('/api/public-config')->assertOk()->assertJsonPath('turnstile_site_key', '1x00000000000000000000AA');

        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
        $this->postJson('/api/login/web', self::OK)->assertStatus(422)->assertJsonValidationErrors('turnstile');       // sin token
        Http::assertNothingSent();

        $this->postJson('/api/login/web', self::OK + ['turnstile_token' => 'token-bueno'])->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'siteverify') && $r['response'] === 'token-bueno'
            && $r['secret'] === '1x0000000000000000000000000000000AA');
    }

    public function test_a_rejected_token_or_cloudflare_being_down_does_not_let_anyone_in(): void
    {
        $this->world();
        $this->turnstileOn();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
        $this->postJson('/api/login/web', self::OK + ['turnstile_token' => 'falso'])->assertStatus(422)->assertJsonValidationErrors('turnstile');

        Http::fake(['challenges.cloudflare.com/*' => Http::response('boom', 500)]);
        $this->postJson('/api/login/web', self::OK + ['turnstile_token' => 'x'])->assertStatus(422)->assertJsonValidationErrors('turnstile');

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('sin red'));
        $this->postJson('/api/login/web', self::OK + ['turnstile_token' => 'x'])->assertStatus(422)->assertJsonValidationErrors('turnstile');
    }

    public function test_a_wrong_password_with_a_valid_turnstile_token_is_still_refused_and_counts_toward_the_lock(): void
    {
        $this->world();
        $this->turnstileOn();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login/web', ['email' => 'ana@cerevelo.test', 'password' => 'mala', 'turnstile_token' => 't'])->assertStatus(422)->assertJsonValidationErrors('email');
        }
        $this->postJson('/api/login/web', self::OK + ['turnstile_token' => 't'])->assertStatus(429);
    }

    public function test_the_mobile_login_keeps_working_when_turnstile_is_on(): void
    {
        $this->world();
        $this->turnstileOn();
        Http::fake();
        $this->postJson('/api/login', self::OK)->assertOk()->assertJsonStructure(['token']);   // la app móvil no puede mostrar el desafío
        Http::assertNothingSent();
    }
}
