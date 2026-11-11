<?php

namespace App\Services;

use App\Models\Approver;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Lo que el gerente de proyecto (aprobador con cuenta y la marca) ve en los reportes: las mismas pantallas y opciones que el dueño, pero solo
 * de los proyectos de los clientes de los que es parte. Cuentan las horas que ya salieron de la etapa interna (enviadas al cliente o
 * aprobadas); las que no se cobran, solo si el proyecto muestra la cortesía.
 */
class ManagerReportService
{
    public function __construct(private ProjectProgressService $progress)
    {
    }

    /** El aprobador de esta cuenta, si es gerente de proyecto activo; si no, 403. */
    public function manager(User $user): Approver
    {
        $a = $user->approver_profile_id ? Approver::with('company')->find($user->approver_profile_id) : null;
        abort_unless($a && $a->project_manager && $a->active, 403, __('Esta cuenta no es de gerente de proyecto.'));
        return $a;
    }

    /** @return Collection<int, TimeEntry> */
    public function entries(Approver $a, string $from, string $to, ?int $projectId = null): Collection
    {
        $ids = $this->progress->projectsFor($a)->pluck('id');
        return TimeEntry::with('project.customer', 'user:id,name', 'timesheet:id,status,stage')->whereIn('project_id', $ids)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->where('running', false)->whereBetween('work_date', [$from, $to])
            ->whereHas('timesheet', fn ($t) => $t->whereIn('status', [Timesheet::SUBMITTED, Timesheet::APPROVED])->releasedToExternal())
            ->get()
            ->filter(fn ($e) => $e->billable || $e->project->show_courtesy)->values();
    }
}
