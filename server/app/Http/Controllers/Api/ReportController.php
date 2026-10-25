<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use Illuminate\Http\Request;

/** Reportes básicos de las propias horas (móvil y web). */
class ReportController extends Controller
{
    public function summary(Request $request)
    {
        $d = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'group' => 'in:project,day,week,customer',
            'project_id' => 'nullable|integer',
        ]);
        $entries = TimeEntry::with('project.customer')->where('user_id', $request->user()->id)
            ->where('running', false)->whereBetween('work_date', [$d['from'], $d['to']])
            ->when(! empty($d['project_id']), fn ($q) => $q->where('project_id', $d['project_id']))->get();

        $group = $d['group'] ?? 'project';
        $rows = $entries->groupBy(function ($e) use ($group) {
            return match ($group) {
                'day' => $e->work_date->toDateString(),
                'week' => \App\Support\Week::ending($e->work_date),
                'customer' => $e->project->customer?->name ?? '(sin cliente)',
                default => $e->project->code.' · '.$e->project->name,
            };
        })->map(fn ($g, $k) => [
            'label' => $k,
            'hours' => round((float) $g->sum('hours'), 2),
            'billable_hours' => round((float) $g->where('billable', true)->sum('hours'), 2),
            'non_billable_hours' => round((float) $g->where('billable', false)->sum('hours'), 2),
        ])->sortKeys()->values();

        return response()->json([
            'from' => $d['from'], 'to' => $d['to'], 'group' => $group,
            'rows' => $rows,
            'total_hours' => round((float) $entries->sum('hours'), 2),
            'billable_hours' => round((float) $entries->where('billable', true)->sum('hours'), 2),
        ]);
    }
}
