<?php

namespace Tests\Feature;

use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** El descanso (en minutos) de una entrada con hora desde y hasta: las horas son hasta − desde − descanso. */
class EntryBreakTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function post(array $extra)
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->worker);
        return $this->postJson('/api/entries', $extra + ['work_date' => '2026-10-20', 'project_id' => $this->project->id, 'start_time' => '08:00', 'end_time' => '17:00']);
    }

    public function test_the_break_is_taken_out_of_the_hours(): void
    {
        $this->world();
        $this->post(['break_minutes' => 60])->assertStatus(201);

        $e = TimeEntry::first();
        $this->assertSame(60, (int) $e->break_minutes);
        $this->assertEquals(8.0, (float) $e->hours);   // 9 h de reloj − 1 h de descanso
        $this->assertSame('08:00', substr($e->start_time, 0, 5));
    }

    public function test_without_a_break_the_entry_works_as_before(): void
    {
        $this->world();
        $this->post([])->assertStatus(201);

        $e = TimeEntry::first();
        $this->assertSame(0, (int) $e->break_minutes);
        $this->assertEquals(9.0, (float) $e->hours);
    }

    public function test_a_break_as_long_as_the_whole_span_or_off_the_increment_is_rejected(): void
    {
        $this->world();
        $this->post(['start_time' => '08:00', 'end_time' => '09:00', 'break_minutes' => 60])->assertStatus(422)->assertJsonValidationErrors('break_minutes');
        $this->post(['break_minutes' => 20])->assertStatus(422)->assertJsonValidationErrors('break_minutes');   // el incremento es de 15 minutos
        $this->post(['break_minutes' => -5])->assertStatus(422);
        $this->assertSame(0, TimeEntry::count());
    }

    public function test_editing_the_times_without_sending_the_break_keeps_it(): void
    {
        $this->world();
        $id = $this->post(['break_minutes' => 30])->assertStatus(201)->json('id');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->worker);
        $this->putJson('/api/entries/'.$id, ['work_date' => '2026-10-20', 'project_id' => $this->project->id, 'start_time' => '08:00', 'end_time' => '16:00'])->assertOk();

        $e = TimeEntry::find($id);
        $this->assertSame(30, (int) $e->break_minutes);
        $this->assertEquals(7.5, (float) $e->hours);   // 8 h − 30 min
    }
}
