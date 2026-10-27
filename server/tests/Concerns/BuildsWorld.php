<?php

namespace Tests\Concerns;

use App\Models\Approver;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;

trait BuildsWorld
{
    protected Company $company;
    protected User $owner;
    protected User $worker;
    protected Approver $approver;
    protected Project $project;

    /** Miércoles 2026-10-21; la semana termina el domingo 2026-10-25. */
    protected function world(array $workerOverrides = []): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'America/Puerto_Rico'));
        $this->company = Company::create(['name' => 'Cerevelo', 'slug' => 'cerevelo', 'locale' => 'es']);
        $this->company->update(['rules' => ['week_starts_on' => 1]]);   // las pruebas históricas cuentan la semana de lunes a domingo
        $this->approver = Approver::create(['company_id' => $this->company->id, 'name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test']);
        $this->owner = User::create([
            'company_id' => $this->company->id, 'name' => 'Luis', 'email' => 'luis@cerevelo.test',
            'password' => 'secret-password', 'role' => 'owner', 'capture_mode' => 'from_to',
        ]);
        $this->project = Project::create([
            'company_id' => $this->company->id, 'code' => 'P-1', 'name' => 'Planta A', 'bill_rate' => 50,
        ]);
        $this->worker = User::create($workerOverrides + [
            'company_id' => $this->company->id, 'name' => 'Ana Torres', 'email' => 'ana@cerevelo.test',
            'password' => 'secret-password', 'role' => 'member', 'worker_type' => 'consultant',
            'capture_mode' => 'from_to', 'approver_id' => $this->approver->id,
        ]);
        $this->worker->projects()->attach($this->project->id);
    }

    /** Le pone cliente al proyecto (si no tiene) y liga al aprobador Jorge a ese cliente. Devuelve el cliente. */
    protected function clientWithApprover(Project $project, ?Approver $approver = null, string $name = 'Cliente SA'): Customer
    {
        $customer = $project->customer_id ? Customer::find($project->customer_id) : Customer::create(['company_id' => $this->company->id, 'name' => $name]);
        $project->forceFill(['customer_id' => $customer->id])->save();
        ($approver ?? $this->approver)->customers()->syncWithoutDetaching([$customer->id]);
        return $customer;
    }

    protected function entry(array $over = []): array
    {
        return $over + [
            'project_id' => $this->project->id, 'work_date' => '2026-10-20',
            'start_time' => '08:00', 'end_time' => '16:00',
        ];
    }

    protected function tokenFromMail(string $mailHtml, string $prefix = 'a'): string
    {
        preg_match('#/'.$prefix.'/([A-Za-z0-9]{48})#', $mailHtml, $m);
        return $m[1] ?? '';
    }
}
