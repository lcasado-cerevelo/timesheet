<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ManagerReportService;
use App\Support\Week;
use Illuminate\Http\Request;

/**
 * Reporte de horas para el gerente de proyecto (aprobador con cuenta y la marca): solo los proyectos de los clientes de los que es parte, de
 * todas las personas, sin nombres ni tarifas. Cuentan las horas que ya salieron de la etapa interna (enviadas al cliente o aprobadas), y las
 * que no se cobran solo si el proyecto muestra la cortesía.
 */
class ApproverReportController extends Controller
{
    public function __construct(private ManagerReportService $manager)
    {
    }

    public function summary(Request $request)
    {
        $a = $this->manager->manager($request->user());
        $d = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'group' => 'in:project,day,week,customer',
            'project_id' => 'nullable|integer',
        ]);
        $entries = $this->manager->entries($a, $d['from'], $d['to'], ! empty($d['project_id']) ? (int) $d['project_id'] : null);

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
