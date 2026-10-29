<?php

namespace Tests\Feature;

use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** La oficina trabaja como back office (ve todo, reportes, facturas) pero no administra la compañía. */
class OfficeRoleTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private User $office;

    private function setUpOffice(): void
    {
        Mail::fake();
        $this->world();
        $this->office = User::create(['company_id' => $this->company->id, 'name' => 'Carlos', 'email' => 'carlos@cerevelo.test',
            'password' => 'secret-password', 'role' => 'office', 'worker_type' => 'employee', 'capture_mode' => 'full_day']);
    }

    public function test_office_sees_the_company_lists_but_not_rates(): void
    {
        $this->setUpOffice();
        $this->worker->terms()->create(['rate' => 80]);
        Sanctum::actingAs($this->office);
        $this->getJson('/api/sheets-pending')->assertOk();
        $this->getJson('/api/review/sheets')->assertOk();
        $this->getJson('/api/progress')->assertOk();
        $this->getJson('/api/commission-results')->assertOk();
        $this->getJson('/api/contractor-invoices')->assertOk();
        $users = $this->getJson('/api/users')->assertOk()->json();
        $ana = collect($users)->firstWhere('id', $this->worker->id);
        $this->assertNull($ana['terms']);
        $this->assertCount(1, $this->getJson('/api/projects')->assertOk()->json());
    }

    public function test_office_cannot_administer(): void
    {
        $this->setUpOffice();
        Sanctum::actingAs($this->office);
        $this->putJson('/api/users/'.$this->worker->id, ['name' => 'X', 'email' => 'x@x.test', 'capture_mode' => 'full_day', 'role' => 'owner'])->assertForbidden();
        $this->postJson('/api/projects', ['code' => 'P-9', 'name' => 'Nuevo'])->assertForbidden();
        $this->putJson('/api/company', ['name' => 'Otra'])->assertForbidden();
        $this->postJson('/api/invitations', ['name' => 'Z', 'email' => 'z@z.test', 'role' => 'worker', 'worker_type' => 'employee'])->assertForbidden();
        $this->getJson('/api/accounts')->assertForbidden();
    }

    public function test_office_cannot_reopen_nor_approve_for_the_client(): void
    {
        $this->setUpOffice();
        $this->clientWithApprover($this->project);
        Sanctum::actingAs($this->worker);
        $id = $this->postJson('/api/entries', $this->entry())->assertCreated()->json('timesheet_id');
        $this->postJson('/api/sheets/'.$id.'/submit')->assertOk();
        Sanctum::actingAs($this->office);
        $this->postJson('/api/sheets/'.$id.'/reopen', ['comment' => 'Quiero reabrir'])->assertForbidden();
        // Nadie aprueba por el cliente: la oficina no es aprobador de esa hoja.
        $this->postJson('/api/sheets/'.$id.'/approve', [])->assertStatus(422);
        $this->assertSame('submitted', Timesheet::find($id)->status);
        $this->assertSame(1, $this->getJson('/api/review/sheets')->assertOk()->json('total') ?? 1);
    }

    public function test_the_owner_edits_the_role_of_a_person_but_not_their_own(): void
    {
        $this->setUpOffice();
        Sanctum::actingAs($this->owner);
        $base = ['name' => 'Ana Torres', 'email' => 'ana@cerevelo.test', 'capture_mode' => 'from_to', 'worker_type' => 'consultant'];
        $this->putJson('/api/users/'.$this->worker->id, $base + ['role' => 'office'])->assertOk()->assertJsonPath('role', 'office');
        $this->assertSame('office', $this->worker->fresh()->role);
        $this->putJson('/api/users/'.$this->owner->id, ['name' => 'Luis', 'email' => 'luis@cerevelo.test', 'capture_mode' => 'from_to', 'role' => 'member'])->assertOk();
        $this->assertSame('owner', $this->owner->fresh()->role);
    }

    public function test_the_owner_invites_an_office_person(): void
    {
        $this->setUpOffice();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/invitations', ['name' => 'Marta', 'email' => 'marta@cerevelo.test', 'role' => 'office'])->assertCreated()->assertJsonPath('account.role', 'office');
        $this->assertSame('office', User::where('email', 'marta@cerevelo.test')->value('role'));
    }
}
