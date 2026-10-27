<?php

namespace Tests\Feature;

use App\Support\Week;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** El día en que empieza la semana es una regla de la compañía: domingo (por defecto) o lunes. */
class WeekStartTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    public function test_the_week_helpers_follow_the_start_day(): void
    {
        $this->assertSame('2026-10-24', Week::ending('2026-10-21', 0));   // miércoles: semana de domingo a sábado
        $this->assertSame('2026-10-24', Week::ending('2026-10-18', 0));   // el domingo abre la semana
        $this->assertSame('2026-10-24', Week::ending('2026-10-24', 0));   // el sábado la cierra
        $this->assertSame('2026-10-25', Week::ending('2026-10-21', 1));
        $this->assertSame('2026-10-18', Week::starting('2026-10-24'));
    }

    public function test_the_rule_defaults_to_sunday_and_is_validated(): void
    {
        $this->world();
        $this->company->update(['rules' => []]);
        $this->assertSame(0, $this->company->refresh()->rules()->weekStart());
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/company', ['rules' => ['week_starts_on' => 3]])->assertStatus(422);
        $this->putJson('/api/company', ['rules' => ['week_starts_on' => 1]])->assertOk()->assertJsonPath('rules.week_starts_on', 1);
    }

    public function test_a_sheet_runs_sunday_to_saturday_and_the_deadline_follows_the_week_end(): void
    {
        $this->world();
        $this->company->update(['rules' => ['week_starts_on' => 0, 'deadline_dow' => 1]]);
        Sanctum::actingAs($this->worker);
        $r = $this->getJson('/api/sheets/week?date=2026-10-21')->assertOk();
        $this->assertSame('2026-10-18', $r->json('week_starting'));
        $this->assertSame('2026-10-24', $r->json('week_ending'));
        $this->assertSame('2026-10-26', $this->company->rules()->deadlineFor('2026-10-24', 'America/Puerto_Rico')->toDateString());
        $this->assertSame('2026-10-26', $this->company->rules()->deadlineFor('2026-10-25', 'America/Puerto_Rico')->toDateString());
    }
}
