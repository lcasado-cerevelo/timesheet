<?php

namespace Tests\Feature;

use App\Models\ContractorTerms;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** El dueño y la oficina que registran horas las facturan como un consultor, y la pantalla dice qué está listo o por qué no. */
class OwnerInvoiceTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function approvedSheetOf(User $u): Timesheet
    {
        $sheet = Timesheet::create(['company_id' => $this->company->id, 'user_id' => $u->id, 'week_ending' => '2026-10-25', 'status' => Timesheet::DRAFT,
            'version' => 1, 'total_hours' => 8]);
        TimeEntry::create(['company_id' => $this->company->id, 'user_id' => $u->id, 'project_id' => $this->project->id,
            'service_id' => $this->project->services()->first()->id, 'timesheet_id' => $sheet->id, 'work_date' => '2026-10-20',
            'start_time' => '08:00', 'end_time' => '16:00', 'hours' => 8, 'billable' => true, 'running' => false]);
        Timesheet::whereKey($sheet->id)->update(['status' => Timesheet::APPROVED]);
        return $sheet;
    }

    public function test_the_owner_sees_why_he_cannot_invoice_and_then_what_is_ready(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->world();
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/contractor-invoices/status')->assertOk()->assertJsonPath('can_invoice', false)->assertJsonPath('reason', 'not_consultant');

        $this->owner->update(['worker_type' => 'consultant']);
        $this->getJson('/api/contractor-invoices/status')->assertJsonPath('reason', 'no_rate');

        ContractorTerms::create(['user_id' => $this->owner->id, 'rate' => 50, 'terms_days' => 30]);
        $this->approvedSheetOf($this->owner);
        $this->getJson('/api/contractor-invoices/status')->assertJsonPath('can_invoice', true)->assertJsonPath('weeks.0.hours', 8)
            ->assertJsonPath('weeks.0.amount', 400);
    }

    public function test_the_owner_invoices_his_own_hours_and_the_office_too(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->world();
        $office = User::create(['company_id' => $this->company->id, 'name' => 'Carlos', 'email' => 'carlos@cerevelo.test', 'password' => 'secret-password',
            'role' => 'office', 'worker_type' => 'consultant', 'capture_mode' => 'from_to']);
        foreach ([$this->owner, $office] as $who) {
            $who->update(['worker_type' => 'consultant']);
            ContractorTerms::create(['user_id' => $who->id, 'rate' => 50, 'terms_days' => 30]);
        }
        $this->approvedSheetOf($this->owner);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/contractor-invoices', ['from' => '2026-10-01', 'to' => '2026-10-31', 'signature' => 'secret-password'])->assertCreated()->assertJsonPath('gross', '400.00');

        Timesheet::query()->where('user_id', $office->id)->delete();
        $s = $this->approvedSheetOf($office);
        Sanctum::actingAs($office);
        $this->postJson('/api/contractor-invoices', ['from' => '2026-10-01', 'to' => '2026-10-31', 'signature' => 'secret-password'])->assertCreated();
        $this->assertNotNull($s->fresh()->contractor_invoice_id);
        $this->getJson('/api/contractor-invoices/status')->assertJsonPath('weeks', []);
    }

    public function test_the_people_list_shows_the_type_of_the_owner(): void
    {
        $this->world();
        $this->owner->update(['worker_type' => 'consultant']);
        Sanctum::actingAs($this->owner);
        $row = collect($this->getJson('/api/accounts')->assertOk()->json())->firstWhere('user_id', $this->owner->id);
        $this->assertSame('consultant', $row['worker_type']);
    }
}
