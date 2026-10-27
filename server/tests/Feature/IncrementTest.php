<?php

namespace Tests\Feature;

use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** El incremento de hora se aplica al entrar los datos y se avisa: nada se redondea en silencio. */
class IncrementTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function inc(float $inc, array $worker = []): void
    {
        $this->world($worker);
        $this->company->update(['rules' => ['week_starts_on' => 1, 'increment' => $inc]]);
        Sanctum::actingAs($this->worker);
    }

    public static function steps(): array
    {
        // incremento, paso en minutos, minutos válidos, minutos inválidos
        return [[0.25, 15, '45', '10'], [0.5, 30, '30', '15'], [1.0, 60, '00', '30'], [0.1, 6, '18', '10'], [0.05, 3, '09', '10']];
    }

    #[DataProvider('steps')]
    public function test_start_and_end_minutes_must_be_multiples_of_the_step(float $inc, int $step, string $ok, string $bad): void
    {
        $this->inc($inc);
        $this->postJson('/api/entries', $this->entry(['start_time' => '08:00', 'end_time' => '10:'.$ok]))->assertCreated();
        $r = $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19', 'start_time' => '08:'.$bad, 'end_time' => '12:00']))->assertStatus(422);
        $r->assertJsonValidationErrors('start_time');
        $this->assertStringContainsString($step >= 60 ? '00' : (string) $step, $r->json('errors.start_time.0'));
        $this->postJson('/api/entries', $this->entry(['work_date' => '2026-10-19', 'start_time' => '08:00', 'end_time' => '12:'.$bad]))
            ->assertStatus(422)->assertJsonValidationErrors('end_time');
    }

    public function test_cents_of_an_hour_need_no_alignment(): void
    {
        $this->inc(0.01);
        $this->postJson('/api/entries', $this->entry(['start_time' => '08:07', 'end_time' => '09:11']))->assertCreated();
    }

    public function test_message_names_the_step_in_both_languages(): void
    {
        $this->inc(0.25);
        $this->postJson('/api/entries', $this->entry(['end_time' => '16:10']))->assertJsonPath('errors.end_time.0', 'Los minutos deben ser múltiplos de 15');
        $this->worker->update(['locale' => 'en']);
        $r = $this->postJson('/api/entries', $this->entry(['end_time' => '16:10']))->assertStatus(422);
        $this->assertSame('Minutes must be multiples of 15', $r->json('errors.end_time.0'));
    }

    public function test_totals_must_be_exact_multiples_of_the_increment(): void
    {
        $this->inc(0.25, ['capture_mode' => 'full_day']);
        $this->postJson('/api/entries', ['project_id' => $this->project->id, 'work_date' => '2026-10-20', 'hours' => 7.3])
            ->assertStatus(422)->assertJsonValidationErrors('hours')->assertJsonPath('errors.hours.0', 'Las horas deben ser múltiplos de 0.25');
        $this->postJson('/api/entries', ['project_id' => $this->project->id, 'work_date' => '2026-10-20', 'hours' => 7.25])->assertCreated()->assertJsonPath('hours', 7.25);
        $this->assertSame(1, TimeEntry::count());
    }

    public function test_totals_with_other_increments(): void
    {
        $this->inc(0.1, ['capture_mode' => 'full_day']);
        $this->postJson('/api/entries', ['project_id' => $this->project->id, 'work_date' => '2026-10-20', 'hours' => 1.3])->assertCreated();
        $this->postJson('/api/entries', ['project_id' => $this->project->id, 'work_date' => '2026-10-19', 'hours' => 1.25])
            ->assertStatus(422)->assertJsonPath('errors.hours.0', 'Las horas deben ser múltiplos de 0.1');
    }

    public function test_me_reports_the_minimum_minutes(): void
    {
        $this->inc(0.25);
        $this->getJson('/api/me')->assertJsonPath('rules.increment', 0.25)->assertJsonPath('rules.minimum_minutes', 15);
        $this->company->update(['rules' => ['week_starts_on' => 1, 'increment' => 0.01]]);
        Sanctum::actingAs($this->worker->fresh());
        $this->getJson('/api/me')->assertJsonPath('rules.minimum_minutes', 1);
        $this->company->update(['rules' => ['week_starts_on' => 1, 'increment' => 1]]);
        Sanctum::actingAs($this->worker->fresh());
        $this->getJson('/api/me')->assertJsonPath('rules.minimum_minutes', 60);
    }

    public function test_clock_start_rounds_to_the_nearest_step_and_explicit_at_must_be_aligned(): void
    {
        $this->inc(0.25);
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:08:00', 'America/Puerto_Rico'));
        $this->postJson('/api/clock/start', ['project_id' => $this->project->id])->assertCreated()->assertJsonPath('start_time', '10:15');
        $this->postJson('/api/clock/stop', ['at' => '2026-10-21T10:00:00-04:00'])->assertStatus(422);
        $this->postJson('/api/clock/stop', ['at' => '2026-10-21T11:15:00-04:00'])->assertOk()->assertJsonPath('hours', 1);

        $this->postJson('/api/clock/start', ['project_id' => $this->project->id, 'at' => '2026-10-21T11:20:00-04:00'])
            ->assertStatus(422)->assertJsonValidationErrors('start_time');
    }

    public function test_clock_stop_below_minimum_asks_the_app_to_confirm(): void
    {
        $this->inc(0.25);
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'America/Puerto_Rico'));
        $this->postJson('/api/clock/start', ['project_id' => $this->project->id])->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:05:00', 'America/Puerto_Rico'));
        $this->postJson('/api/clock/stop')->assertStatus(422)->assertJsonPath('code', 'below_minimum')->assertJsonPath('minimum_minutes', 15);
        $this->assertTrue(TimeEntry::where('running', true)->exists());
        // Con la hora de salida explícita y alineada se acepta.
        $this->postJson('/api/clock/stop', ['at' => '2026-10-21T10:15:00-04:00'])->assertOk()->assertJsonPath('hours', 0.25);
    }

    public function test_clock_stop_without_at_and_misaligned_now_asks_to_confirm(): void
    {
        $this->inc(0.25);
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'America/Puerto_Rico'));
        $this->postJson('/api/clock/start', ['project_id' => $this->project->id])->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-10-21 11:20:00', 'America/Puerto_Rico'));
        $this->postJson('/api/clock/stop')->assertStatus(422)->assertJsonPath('code', 'not_aligned')->assertJsonPath('suggested_end_time', '11:15');
        $this->postJson('/api/clock/stop', ['at' => '2026-10-21T11:15:00-04:00'])->assertOk()->assertJsonPath('hours', 1.25);
    }

    public function test_sync_reports_misaligned_entries_as_invalid(): void
    {
        $this->inc(0.25);
        $ok = '11111111-1111-4111-8111-111111111111';
        $bad = '22222222-2222-4222-8222-222222222222';
        $r = $this->postJson('/api/sync', ['entries' => [
            $this->entry(['client_uuid' => $ok]),
            $this->entry(['client_uuid' => $bad, 'work_date' => '2026-10-19', 'end_time' => '16:10']),
        ]])->assertOk();
        $this->assertSame('ok', $r->json('results.0.status'));
        $this->assertSame('invalid', $r->json('results.1.status'));
        $this->assertArrayHasKey('end_time', $r->json('results.1.errors'));
    }
}
