<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** Con la huella activada, la sesión del teléfono no vence por inactividad (ni con "Recordar por" en 0), pero sí por el tope absoluto. */
class BiometricSessionTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function me(string $plain)
    {
        $this->app['auth']->forgetGuards();
        return $this->withToken($plain)->getJson('/api/me');
    }

    private function token(User $u, int $days, int $idleDays): string
    {
        $plain = $u->createToken('movil')->plainTextToken;
        PersonalAccessToken::findToken($plain)->forceFill(['created_at' => now()->subDays($days), 'last_used_at' => now()->subDays($idleDays)])->save();
        return $plain;
    }

    private function enable(string $plain, bool $on = true)
    {
        $this->app['auth']->forgetGuards();
        return $this->withToken($plain)->postJson('/api/me/biometric', ['enabled' => $on]);
    }

    public function test_without_the_fingerprint_a_zero_remember_session_dies_after_a_day_idle_and_with_it_does_not(): void
    {
        $this->world();
        $this->company->update(['rules' => array_replace($this->company->rules()->all(), ['remember_days' => 0])]);

        $plain = $this->owner->createToken('movil')->plainTextToken;
        $this->enable($plain)->assertOk()->assertJsonPath('enabled', true);
        // Un día sin usarla (el respaldo normal es de 12 horas): con la huella sigue viva.
        PersonalAccessToken::findToken($plain)->forceFill(['last_used_at' => now()->subDay()])->save();
        $this->me($plain)->assertOk();

        $plain2 = $this->token($this->owner, 1, 1);
        $this->me($plain2)->assertStatus(401);
    }

    public function test_the_absolute_cap_still_ends_a_fingerprint_session(): void
    {
        $this->world();
        $plain = $this->owner->createToken('movil')->plainTextToken;
        $this->enable($plain)->assertOk();
        PersonalAccessToken::findToken($plain)->forceFill(['created_at' => now()->subDays(8), 'last_used_at' => now()])->save();   // dueño: tope de 7 días
        $this->me($plain)->assertStatus(401);
    }

    public function test_turning_the_fingerprint_off_restores_the_normal_expiry(): void
    {
        $this->world();
        $this->company->update(['rules' => array_replace($this->company->rules()->all(), ['remember_days' => 0])]);
        $plain = $this->owner->createToken('movil')->plainTextToken;
        $this->enable($plain)->assertOk();
        $this->enable($plain, false)->assertOk()->assertJsonPath('enabled', false);
        PersonalAccessToken::findToken($plain)->forceFill(['last_used_at' => now()->subDay()])->save();
        $this->me($plain)->assertStatus(401);
    }
}
