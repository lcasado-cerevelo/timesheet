<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approver;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Services\ProjectProgressService;
use App\Support\Week;
use Illuminate\Http\Request;

/**
 * Reporte de horas para el gerente de proyecto (aprobador con cuenta y la marca): solo los proyectos de los clientes de los que es parte, de
 * todas las personas, sin nombres ni tarifas. Cuentan las horas que ya salieron de la etapa interna (enviadas al cliente o aprobadas), y las
 * que no se cobran solo si el proyecto muestra la cortesía. Sin exportar: es solo para verlo.
 */
class ApproverReportController extends Controller
{
    public function __construct(private ProjectProgressService $progress)
    {
    }

    public function summary(Request $request)
    {
        $a = Approver::with('company')->findOrFail($request->user()->approver_profile_id);
        abort_unless($a->project_manager && $a->active, 403, __('Esta cuenta no es de gerente de proyecto.'));
        $d = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'group' => 'in:project,day,week,customer',
            'project_id' => 'nullable|integer',
        ]);
        $projects = $this->progress->projectsFor($a);
        $ids = $projects->pluck('id');
        $entries = TimeEntry::with('project.customer')->whereIn('project_id', $ids)
            ->when(! empty($d['project_id']), fn ($q) => $q->where('project_id', $d['project_id']))
            ->where('running', false)->whereBetween('work_date', [$d['from'], $d['to']])
            ->whereHas('timesheet', fn ($t) => $t->whereIn('status', [Timesheet::SUBMITTED, Timesheet::APPROVED])->releasedToExternal())
            ->get()
            ->filter(fn ($e) => $e->billable || $e->project->show_courtesy);

        $group = $d['group'] ?? 'project';
        $weekStart = $a->company->rules()->weekStart();
        $rows = $entries->groupBy(fn ($e) => match ($group) {
            'day' => $e->work_date->toDateString(),
            'week' => Week::ending($e->work_date, $weekStart),
            'customer' => $e->project->customer?->name ?? __('(sin cliente)'),
            default => $e->project->code.' · '.$e->project->name,
        })->map(fn ($g, $k) => [
            'label' => $k,
            'hours' => round((float) $g->sum('hours'), 2),
            'billable_hours' => round((float) $g->where('billable', true)->sum('hours'), 2),
            'non_billable_hours' => round((float) $g->where('billable', false)->sum('hours'), 2),
        ])->sortKeys()->values();

        return response()->json([
            'from' => $d['from'], 'to' => $d['to'], 'group' => $group, 'rows' => $rows,
            'total_hours' => round((float) $entries->sum('hours'), 2),
            'billable_hours' => round((float) $entries->where('billable', true)->sum('hours'), 2),
        ]);
    }
}
