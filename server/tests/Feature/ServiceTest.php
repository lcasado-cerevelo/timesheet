<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Service;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    public function test_every_project_gets_a_default_service_and_entries_use_it_automatically(): void
    {
        $this->world();
        $this->assertSame(['General'], $this->project->services()->pluck('name')->all());
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->assertSame($this->project->services()->first()->id, $e['service_id']);
        $this->assertSame('General', $e['service']['name']);
    }

    public function test_with_several_services_the_person_must_choose_one_and_it_must_belong_to_the_project(): void
    {
        $this->world();
        $b = Service::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Therapy']);
        $other = Project::create(['company_id' => $this->company->id, 'code' => 'P-2', 'name' => 'Otra']);
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/entries', $this->entry())->assertStatus(422)->assertJsonValidationErrors('service_id');
        $this->postJson('/api/entries', $this->entry(['service_id' => $other->services()->first()->id]))->assertStatus(422)->assertJsonValidationErrors('service_id');
        $this->postJson('/api/entries', $this->entry(['service_id' => $b->id]))->assertCreated()->assertJsonPath('service.name', 'Therapy');
    }

    public function test_the_service_decides_how_hours_are_entered_and_for_both_the_person_decides(): void
    {
        $this->world();   // la persona prefiere detalle (from_to)
        $mk = fn (string $name, string $mode) => Service::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => $name, 'capture_mode' => $mode]);
        $detail = $mk('Detail', 'detail');
        $total = $mk('Total', 'total');
        $both = $mk('Both', 'both');
        Sanctum::actingAs($this->worker);
        $hours = fn (Service $s, string $day, float $h) => ['project_id' => $this->project->id, 'service_id' => $s->id, 'work_date' => $day, 'hours' => $h];

        // Servicio "total": no admite desde/hasta aunque la persona prefiera detalle.
        $this->postJson('/api/entries', $this->entry(['service_id' => $total->id]))->assertStatus(422)->assertJsonValidationErrors('start_time');
        $this->postJson('/api/entries', $hours($total, '2026-10-20', 6))->assertCreated()->assertJsonPath('hours', 6);
        // Servicio "detalle": exige desde/hasta.
        $this->postJson('/api/entries', $hours($detail, '2026-10-19', 3))->assertStatus(422);
        $this->postJson('/api/entries', $this->entry(['service_id' => $detail->id, 'work_date' => '2026-10-19']))->assertCreated();
        // Servicio "ambos": manda la preferencia de la persona.
        $this->postJson('/api/entries', $hours($both, '2026-10-18', 2))->assertStatus(422);
        $this->worker->update(['capture_mode' => 'full_day']);
        $this->postJson('/api/entries', $hours($both, '2026-10-18', 2))->assertCreated();
        // El payload de proyectos le dice a la app cómo entra cada servicio esa persona.
        $mode = collect($this->getJson('/api/projects')->json()[0]['services'])->pluck('mode', 'name');
        $this->assertSame('full_day', $mode['Both']);
        $this->assertSame('from_to', $mode['Detail']);
        $this->assertSame('full_day', $mode['Total']);
    }

    public function test_rate_changes_only_affect_new_entries(): void
    {
        $this->world();
        $svc = $this->project->services()->first();
        $svc->update(['bill_rate' => 70]);
        Sanctum::actingAs($this->worker);
        $old = $this->postJson('/api/entries', $this->entry())->assertCreated()->json('id');
        $svc->update(['bill_rate' => 90]);
        $new = $this->postJson('/api/entries', $this->entry(['start_time' => '17:00', 'end_time' => '18:00']))->assertCreated()->json('id');
        $this->assertEquals(70, TimeEntry::find($old)->bill_rate);
        $this->assertEquals(90, TimeEntry::find($new)->bill_rate);
        // Sin tarifa propia, el servicio usa la del proyecto.
        $svc->update(['bill_rate' => null]);
        $this->assertEquals(50, $svc->refresh()->effectiveRate());
    }

    public function test_services_are_deactivated_not_deleted_and_a_project_keeps_one_active(): void
    {
        $this->world();
        $b = Service::create(['company_id' => $this->company->id, 'project_id' => $this->project->id, 'name' => 'Therapy']);
        $a = $this->project->services()->where('name', 'General')->first();
        Sanctum::actingAs($this->worker);
        $this->putJson('/api/services/'.$b->id, ['active' => false])->assertForbidden();   // solo el dueño

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/services/'.$b->id, ['active' => false])->assertOk()->assertJsonPath('active', false);
        $this->putJson('/api/services/'.$a->id, ['active' => false])->assertStatus(422);   // quedaría sin servicios activos
        $this->assertNotNull(Service::find($b->id));

        // El servicio desactivado no se ofrece ni se acepta en entradas nuevas.
        Sanctum::actingAs($this->worker);
        $names = collect($this->getJson('/api/projects')->json()[0]['services'])->pluck('name')->all();
        $this->assertSame(['General'], $names);
        $this->postJson('/api/entries', $this->entry(['service_id' => $b->id]))->assertStatus(422)->assertJsonValidationErrors('service_id');
    }

    public function test_the_owner_manages_services_and_others_do_not_see_rates(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $r = $this->postJson('/api/projects/'.$this->project->id.'/services', ['name' => 'Evaluation', 'bill_rate' => 120, 'billable' => true,
            'capture_mode' => 'detail', 'accountisi_item_id' => '7'])->assertCreated()->json();
        $this->assertEquals(120, $r['bill_rate']);
        $this->postJson('/api/projects/'.$this->project->id.'/services', ['name' => 'Evaluation'])->assertStatus(422);   // nombre repetido
        $this->postJson('/api/projects/'.$this->project->id.'/services', ['name' => 'X', 'capture_mode' => 'weird'])->assertStatus(422);
        Sanctum::actingAs($this->worker);
        $svc = collect($this->getJson('/api/projects')->json()[0]['services'])->firstWhere('name', 'Evaluation');
        $this->assertArrayNotHasKey('bill_rate', $svc);
        $this->assertArrayNotHasKey('accountisi_item_id', $svc);
    }
}
