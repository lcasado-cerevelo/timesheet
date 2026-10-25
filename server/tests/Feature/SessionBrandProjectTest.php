<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

class SessionBrandProjectTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    // ---- A: cliente obligatorio en proyectos facturables ----

    public function test_billable_project_needs_customer(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $base = ['code' => 'N-1', 'name' => 'Nuevo'];

        $this->postJson('/api/projects', $base + ['billable' => true])->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->postJson('/api/projects', $base)->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->postJson('/api/projects', $base + ['billable' => false])->assertCreated();

        $cid = \App\Models\Customer::create(['company_id' => $this->company->id, 'name' => 'ACME'])->id;
        $this->postJson('/api/projects', ['code' => 'N-2', 'name' => 'Con cliente', 'billable' => true, 'customer_id' => $cid])->assertCreated();
    }

    public function test_update_checks_existing_customer_and_legacy_projects_untouched_until_edited(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $this->assertNull($this->project->customer_id);
        $this->assertTrue((bool) $this->project->fresh()->billable);

        $edit = ['code' => 'P-1', 'name' => 'Planta A'];
        $this->putJson('/api/projects/'.$this->project->id, $edit)->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->putJson('/api/projects/'.$this->project->id, $edit + ['billable' => false])->assertOk();

        $this->putJson('/api/projects/'.$this->project->id, $edit + ['billable' => true])->assertStatus(422);
        $cid = \App\Models\Customer::create(['company_id' => $this->company->id, 'name' => 'ACME'])->id;
        $this->putJson('/api/projects/'.$this->project->id, $edit + ['billable' => true, 'customer_id' => $cid])->assertOk();
        $this->putJson('/api/projects/'.$this->project->id, $edit)->assertOk();
        $this->putJson('/api/projects/'.$this->project->id, $edit + ['customer_id' => null])->assertStatus(422);
    }

    // ---- B: mostrar/esconder el nombre de la marca ----

    public function test_show_name_default_update_history_and_revert(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/brand')->assertJsonPath('show_name', true);
        $this->getJson('/api/me')->assertJsonPath('brand.show_name', true);
        $this->getJson('/api/public-config')->assertJsonPath('brand.show_name', true);

        $this->postJson('/api/brand', ['show_name' => false])->assertOk()->assertJsonPath('show_name', false);
        $this->getJson('/api/me')->assertJsonPath('brand.show_name', false);
        $this->getJson('/api/public-config')->assertJsonPath('brand.show_name', false);

        $this->postJson('/api/brand', ['brand_name' => 'EBVS'])->assertOk()->assertJsonPath('show_name', false);
        $this->postJson('/api/brand', ['show_name' => 'x'])->assertStatus(422);

        $hist = $this->getJson('/api/brand/history')->json();
        $change = collect($hist)->first(fn ($h) => ($h['before']['show_brand_name'] ?? null) === true && ($h['after']['show_brand_name'] ?? null) === false);
        $this->assertNotNull($change);
        $this->postJson('/api/brand/history/'.$change['id'].'/revert')->assertOk()->assertJsonPath('show_name', true);
    }

    // ---- C: sesión deslizante ----

    private function token(int $days = 30): string
    {
        $this->world();
        $this->company->update(['rules' => ['remember_days' => $days]]);
        return $this->worker->createToken('app')->plainTextToken;
    }

    private function me(string $t)
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeader('Authorization', 'Bearer '.$t)->getJson('/api/me');
    }

    public function test_expires_after_n_days_without_use(): void
    {
        $t = $this->token(10);
        $this->me($t)->assertOk()->assertJsonPath('remember_days', 10);
        Carbon::setTestNow(now()->addDays(9));
        $this->me($t)->assertOk();
        Carbon::setTestNow(now()->addDays(11));
        $this->me($t)->assertStatus(401)->assertJsonPath('code', 'session_expired')->assertJsonStructure(['message', 'code']);
        $this->assertSame(0, PersonalAccessToken::count());
        $this->me($t)->assertStatus(401)->assertJsonMissingPath('code');
    }

    public function test_daily_use_keeps_session_alive(): void
    {
        $t = $this->token(3);
        for ($i = 0; $i < 10; $i++) {
            $this->me($t)->assertOk();
            Carbon::setTestNow(now()->addDays(2));
        }
    }

    public function test_zero_days_falls_back_to_12_hours(): void
    {
        $t = $this->token(0);
        $this->me($t)->assertOk()->assertJsonPath('remember_days', 0);
        Carbon::setTestNow(now()->addHours(11));
        $this->me($t)->assertOk();
        Carbon::setTestNow(now()->addHours(13));
        $this->me($t)->assertStatus(401)->assertJsonPath('code', 'session_expired');
    }

    public function test_never_used_token_counts_from_creation_and_rule_change_applies_next_request(): void
    {
        $t = $this->token(30);
        Carbon::setTestNow(now()->addDays(20));
        $this->company->update(['rules' => ['remember_days' => 7]]);
        $this->me($t)->assertStatus(401)->assertJsonPath('code', 'session_expired');

        $t2 = $this->worker->createToken('app')->plainTextToken;
        $this->company->update(['rules' => ['remember_days' => 365]]);
        Carbon::setTestNow(now()->addDays(200));
        $this->me($t2)->assertOk()->assertJsonPath('remember_days', 365);
    }

    public function test_last_used_is_touched_but_not_on_every_request(): void
    {
        $t = $this->token(30);
        $this->me($t)->assertOk();
        $tok = PersonalAccessToken::first();
        $first = $tok->last_used_at;
        $this->assertNotNull($first);
        Carbon::setTestNow(now()->addMinutes(2));
        $this->me($t)->assertOk();
        $this->assertTrue($tok->fresh()->last_used_at->equalTo($first));
        Carbon::setTestNow(now()->addMinutes(10));
        $this->me($t)->assertOk();
        $this->assertTrue($tok->fresh()->last_used_at->gt($first));
    }

    // ---- D: la regla ----

    public function test_remember_days_validation_and_zero_saves(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/company', ['rules' => ['remember_days' => 0]])->assertOk()->assertJsonPath('rules.remember_days', 0);
        $this->assertSame(0, $this->company->fresh()->rules()->get('remember_days'));
        $this->putJson('/api/company', ['rules' => ['remember_days' => 365]])->assertOk();
        $this->putJson('/api/company', ['rules' => ['remember_days' => 366]])->assertStatus(422);
        $this->putJson('/api/company', ['rules' => ['remember_days' => -1]])->assertStatus(422);
    }
}
