<?php

namespace App\Services;

use App\Models\Approver;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * El Avance de un proyecto para el gerente de proyecto del cliente (solo lectura).
 * Solo cuenta horas facturables (reg/ot) de hojas aprobadas o enviadas que ya salieron de la etapa interna; nunca borradores.
 * Nunca incluye tarifas, pagos, comisiones, metas de ahorro ni tipos de hora internos.
 */
class ProjectProgressService
{
    public const WEEKS = 8;

    /** Proyectos de los clientes a los que está ligado el gerente. */
    public function projectsFor(Approver $a): Collection
    {
        return Project::with('customer:id,name')->where('company_id', $a->company_id)
            ->whereIn('customer_id', $a->customers()->pluck('customers.id'))->orderByDesc('active')->orderBy('code')->get();
    }

    public function canSee(Approver $a, Project $p): bool
    {
        return $p->company_id === $a->company_id && $a->customers()->where('customers.id', $p->customer_id)->exists();
    }

    public function build(Project $p, Approver $a): array
    {
        $tz = $a->company->timezone ?: 'UTC';
        $today = CarbonImmutable::now($tz)->toDateString();
        $entries = TimeEntry::with('timesheet:id,status,stage', 'user:id,name', 'service:id,name')
            ->where('project_id', $p->id)->where('billable', true)->whereIn('hour_type', TimeEntry::BILLABLE_HOUR_TYPES)->where('running', false)
            ->whereHas('timesheet', fn ($t) => $t->where(fn ($q) => $q->where('status', Timesheet::APPROVED)
                ->orWhere(fn ($w) => $w->where('status', Timesheet::SUBMITTED)->where(fn ($s) => $s->whereNull('stage')->orWhere('stage', '!=', Timesheet::STAGE_INTERNAL)))))
            ->get();
        $approved = $entries->filter(fn ($e) => $e->timesheet->status === Timesheet::APPROVED);
        $pending = $entries->reject(fn ($e) => $e->timesheet->status === Timesheet::APPROVED);

        $hours = fn (Collection $c) => round((float) $c->sum('hours'), 2);
        $budget = $p->budget_hours !== null ? (float) $p->budget_hours : null;
        $done = $hours($approved);

        $dow = (int) $a->company->rules()->get('week_starts_on');
        $weekOf = fn (string $d) => Week::starting(Week::ending($d, $dow));
        $weekStart = $weekOf($today);
        $weeks = [];
        for ($i = self::WEEKS - 1; $i >= 0; $i--) {
            $s = CarbonImmutable::parse($weekStart)->subWeeks($i)->toDateString();
            $in = fn (Collection $c) => $c->filter(fn ($e) => $weekOf($e->work_date->toDateString()) === $s);
            $weeks[] = ['week_starting' => $s, 'hours' => $hours($in($approved)), 'pending' => $hours($in($pending))];
        }
        $thisWeek = $weeks[self::WEEKS - 1];
        $lastWeek = $weeks[self::WEEKS - 2];

        $tasks = Task::where('project_id', $p->id)->orderByDesc('active')->orderBy('estimated_end')->orderBy('id')->get()->map(function (Task $t) use ($approved, $pending, $hours, $today) {
            $used = $hours($approved->where('task_id', $t->id));
            $budgetT = $t->budget_hours !== null ? (float) $t->budget_hours : null;
            $end = $t->estimated_end?->toDateString();
            return [
                'id' => $t->id, 'name' => $t->name, 'status' => $t->active ? 'open' : 'completed',
                'completed_on' => $t->completed_on?->toDateString(), 'estimated_end' => $end,
                'late' => $t->active ? ($end !== null && $end < $today) : ($end !== null && $t->completed_on !== null && $t->completed_on->toDateString() > $end),
                'budget_hours' => $budgetT, 'hours' => $used, 'pending_hours' => $hours($pending->where('task_id', $t->id)),
                'pct' => $budgetT ? round($used / $budgetT * 100, 1) : null,
                'over_budget' => $budgetT !== null && $used > $budgetT,
                'created_on' => $t->created_at?->toDateString(),
            ];
        })->values();

        $group = fn (string $key, callable $name) => $entries->groupBy($key)->map(fn ($c) => [
            'name' => $name($c->first()), 'hours' => $hours($c->filter(fn ($e) => $e->timesheet->status === Timesheet::APPROVED)),
            'pending_hours' => $hours($c->reject(fn ($e) => $e->timesheet->status === Timesheet::APPROVED)),
        ])->sortByDesc('hours')->values();

        $weekAgo = CarbonImmutable::parse($today)->subDays(7)->toDateString();
        return [
            'project' => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'customer' => $p->customer?->name, 'active' => (bool) $p->active],
            'budget_hours' => $budget, 'hours' => $done, 'pending_hours' => $hours($pending),
            'remaining_hours' => $budget !== null ? round($budget - $done, 2) : null,
            'pct' => $budget ? round($done / $budget * 100, 1) : null,
            'this_week' => $thisWeek['hours'], 'last_week' => $lastWeek['hours'],
            'awaiting_you' => app(ExternalApprovalService::class)->pendingSheetsFor($a)
                ->filter(fn ($s) => $entries->contains('timesheet_id', $s->id))->count(),
            'weeks' => $weeks,
            'tasks' => $tasks,
            'completed_this_week' => $tasks->filter(fn ($t) => $t['completed_on'] !== null && $t['completed_on'] > $weekAgo)->count(),
            'new_this_week' => $tasks->filter(fn ($t) => $t['created_on'] !== null && $t['created_on'] > $weekAgo)->count(),
            'by_service' => $group('service_id', fn ($e) => $e->service?->name ?? '—'),
            'by_person' => $group('user_id', fn ($e) => $e->user?->name ?? '—'),
        ];
    }

    /** La misma información como tablas para exportar. */
    public function tables(array $d): array
    {
        $hr = fn ($v) => $v === null ? '' : (float) $v;
        $label = ['open' => __('Abierta'), 'completed' => __('Completada')];
        return [
            'title' => __('Avance').' · '.$d['project']['code'],
            'subtitle' => $d['project']['name'].' · '.$d['project']['customer'],
            'columns' => [['h' => __('Tarea'), 't' => 'text'], ['h' => __('Estado'), 't' => 'text'], ['h' => __('Fecha estimada'), 't' => 'date'], ['h' => __('Completada'), 't' => 'date'],
                ['h' => __('Presupuesto (h)'), 't' => 'hours'], ['h' => __('Horas aprobadas'), 't' => 'hours'], ['h' => __('Por aprobar'), 't' => 'hours'], ['h' => __('Atrasada'), 't' => 'bool']],
            'rows' => collect($d['tasks'])->map(fn ($t) => [$t['name'], $label[$t['status']], (string) $t['estimated_end'], (string) $t['completed_on'],
                $hr($t['budget_hours']), $t['hours'], $t['pending_hours'], (bool) $t['late']])->all(),
            'total' => [__('Total del proyecto'), '', '', '', $hr($d['budget_hours']), $d['hours'], $d['pending_hours'], ''],
        ];
    }
}
