<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionResult;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Cálculo de las comisiones por productividad (ver diseno.md, v1.11). Se calcula al completar una tarea, solo con horas de hojas
 * APROBADAS, y el resultado se guarda tal cual (no se recalcula).
 *
 * Condiciones del equipo (las mismas para todos): (1) el total de horas de la tarea no pasa de la meta (presupuesto × (1 − ahorro%))
 * más la tolerancia de la comisión; (2) la tarea se completa a más tardar en su fecha estimada. Si falla alguna, no hay bono para nadie.
 * - Bono del equipo: con las dos condiciones, toda persona con comisión en la tarea que trabajó al menos 1 hora lo gana. Se paga sobre las
 *   horas de la META (p. ej. 16 h de una tarea de 20 h con meta de ahorro del 20%), no sobre las horas ahorradas:
 *   horas de la meta × parte del bono (% fijo, o el % que le correspondía) × monto por hora.
 * - Comisión por hora (individual): además, sus horas deben ser al menos su % mínimo del total trabajado; gana sus horas × extra por hora.
 */
class CommissionService
{
    private const EPS = 1e-6;
    /** Horas mínimas que una persona debe haber trabajado en la tarea para recibir el bono del equipo. */
    public const MIN_HOURS = 1.0;

    /** Comisiones activas que aplican a esta tarea (de personas activas). Vacío si la tarea no aplica comisión. */
    public function applicable(Task $task): Collection
    {
        if (! $task->commissionable) {
            return collect();
        }
        return Commission::with('user:id,name,active,worker_type', 'services:id')
            ->where('project_id', $task->project_id)->where('active', true)
            ->whereHas('tasks', fn ($q) => $q->whereKey($task->id))
            ->whereHas('user', fn ($q) => $q->where('active', true))->get();
    }

    /** Horas aprobadas por persona y servicio de la tarea, y lo que sigue sin aprobar. */
    public function hours(Task $task): array
    {
        $approved = fn ($t) => $t->where('status', Timesheet::APPROVED);
        $rows = TimeEntry::where('task_id', $task->id)->whereHas('timesheet', $approved)->get(['user_id', 'service_id', 'hours']);
        $pending = (float) TimeEntry::where('task_id', $task->id)->whereHas('timesheet', fn ($t) => $t->where('status', '!=', Timesheet::APPROVED))->sum('hours');
        return ['entries' => $rows, 'total' => round((float) $rows->sum('hours'), 2), 'pending' => round($pending, 2)];
    }

    /**
     * El cálculo de cada comisión aplicable si la tarea se completara en $completedOn. No guarda nada.
     * @return array{budget_hours:float, goal_hours:float, total_hours:float, pending_hours:float, rows:array<int,array>}
     */
    public function evaluate(Task $task, string $completedOn, ?Collection $commissions = null): array
    {
        $commissions ??= $this->applicable($task);
        $h = $this->hours($task);
        $budget = (float) $task->budget_hours;
        $goalPct = (float) $task->savings_goal_pct;
        $goal = round($budget * (1 - $goalPct / 100), 2);
        $total = $h['total'];
        $deadline = $task->estimated_end?->toDateString();
        $metDate = $deadline === null || $completedOn <= $deadline;
        $saved = round(max(0.0, $budget - $total), 2);
        $bonusHours = $goal;   // el bono del equipo es sobre las horas de la meta, no sobre las ahorradas

        $rows = [];
        foreach ($commissions as $c) {
            $serviceIds = $c->services->pluck('id')->map(fn ($i) => (int) $i)->all();
            $mine = $h['entries']->filter(fn ($e) => (int) $e->user_id === (int) $c->user_id && (! $serviceIds || in_array((int) $e->service_id, $serviceIds, true)));
            $userHours = round((float) $mine->sum('hours'), 2);
            $userPct = $total > 0 ? $userHours / $total * 100 : 0.0;
            $metGoal = $budget > 0 && $total <= $goal + (float) $c->tolerance_hours + self::EPS;
            $workedMin = $userHours + self::EPS >= self::MIN_HOURS;
            $metShare = $userPct + self::EPS >= (float) $c->percent_required;
            $team = $metGoal && $metDate && $workedMin;
            $sharePct = $c->savingsShare();
            $savings = $team ? round($bonusHours * $sharePct / 100 * (float) $c->savings_per_hour, 2) : 0.0;
            $commission = $team && $metShare ? round($userHours * (float) $c->extra_per_hour, 2) : 0.0;
            $rows[] = [
                'commission_id' => $c->id, 'user_id' => $c->user_id, 'user_name' => $c->user->name, 'worker_type' => $c->user->worker_type,
                'project_id' => $task->project_id, 'task_id' => $task->id,
                'completed_on' => $completedOn, 'deadline' => $deadline,
                'budget_hours' => $budget, 'goal_hours' => $goal, 'tolerance_hours' => (float) $c->tolerance_hours,
                'total_hours' => $total, 'user_hours' => $userHours, 'user_pct' => round($userPct, 2), 'saved_hours' => $saved, 'bonus_hours' => $bonusHours,
                'percent_required' => (float) $c->percent_required, 'extra_per_hour' => (float) $c->extra_per_hour,
                'savings_mode' => $c->savings_mode, 'savings_pct' => $sharePct, 'savings_per_hour' => (float) $c->savings_per_hour,
                'met_goal' => $metGoal, 'met_date' => $metDate, 'worked_min' => $workedMin, 'met_share' => $metShare,
                'commission_amount' => $commission, 'savings_amount' => $savings, 'adjustment' => 0.0, 'adjustment_note' => null,
                'total' => round($commission + $savings, 2), 'withhold' => (bool) $c->withhold, 'ivu' => (bool) $c->ivu,
            ];
        }
        return ['budget_hours' => $budget, 'goal_hours' => $goal, 'total_hours' => $total, 'pending_hours' => $h['pending'], 'rows' => $rows];
    }

    /** ¿Alguna comisión tiene algo que pagar? */
    public function payable(array $evaluation): bool
    {
        return collect($evaluation['rows'])->contains(fn ($r) => $r['total'] > 0);
    }

    /** Guarda el resultado de cada persona. Con $release (el dueño decidió liberar) quedan liberadas; si no, pendientes de liberar. */
    public function store(array $evaluation, bool $release, User $by): void
    {
        foreach ($evaluation['rows'] as $r) {
            $commission = Commission::find($r['commission_id']);
            $status = $r['total'] > 0 ? ($release ? 'released' : 'pending') : 'none';
            CommissionResult::updateOrCreate(['task_id' => $r['task_id'], 'user_id' => $r['user_id']], array_diff_key($r, array_flip(['user_name', 'worker_type', 'commission_id', 'task_id', 'user_id'])) + [
                'company_id' => $by->company_id, 'commission_id' => $commission?->id, 'status' => $status,
                'released_at' => $status === 'released' ? now() : null, 'released_by' => $status === 'released' ? $by->id : null,
            ]);
        }
    }

    /** ¿Alguna comisión de la tarea ya se facturó o se pagó? Entonces la tarea no se puede reabrir. */
    public function hasLockedResults(Task $task): bool
    {
        return CommissionResult::where('task_id', $task->id)->whereNotIn('status', ['pending', 'released', 'none'])->exists();
    }

    /** Al reabrir una tarea se descartan sus comisiones calculadas (se vuelven a calcular al completarla otra vez). */
    public function discardFor(Task $task): void
    {
        CommissionResult::where('task_id', $task->id)->delete();
    }

    /** La forma de un cálculo (sin guardar) o de un resultado guardado, para la API del dueño. */
    public static function rowPayload(array $r): array
    {
        return [
            'user' => ['id' => $r['user_id'], 'name' => $r['user_name'] ?? null],
            'completed_on' => $r['completed_on'] ?? null, 'deadline' => $r['deadline'] ?? null,
            'budget_hours' => (float) $r['budget_hours'], 'goal_hours' => (float) $r['goal_hours'], 'tolerance_hours' => (float) $r['tolerance_hours'],
            'total_hours' => (float) $r['total_hours'], 'user_hours' => (float) $r['user_hours'], 'user_pct' => (float) $r['user_pct'],
            'saved_hours' => (float) $r['saved_hours'], 'bonus_hours' => (float) ($r['bonus_hours'] ?? $r['saved_hours']), 'percent_required' => (float) $r['percent_required'],
            'extra_per_hour' => (float) $r['extra_per_hour'], 'savings_pct' => (float) $r['savings_pct'], 'savings_per_hour' => (float) $r['savings_per_hour'],
            'met_goal' => (bool) $r['met_goal'], 'met_date' => (bool) $r['met_date'], 'worked_min' => (bool) $r['worked_min'], 'met_share' => (bool) $r['met_share'],
            'commission_amount' => (float) $r['commission_amount'], 'savings_amount' => (float) $r['savings_amount'],
            'adjustment' => (float) ($r['adjustment'] ?? 0), 'adjustment_note' => $r['adjustment_note'] ?? null, 'total' => (float) $r['total'],
            'withhold' => (bool) $r['withhold'], 'ivu' => (bool) $r['ivu'],
        ];
    }

    /**
     * Lo que ve una persona con comisión (solo lo suyo y lo agregado del equipo, nunca nombres ni horas de otras personas):
     * sus términos y, por cada tarea que cuenta, cómo va (tarea abierta: las condiciones y lo que ganaría si se completara hoy) o el
     * resultado (tarea completada). Una comisión sin liberar no muestra montos: está en revisión.
     */
    public function forWorker(User $user): array
    {
        $today = now($user->company->timezone)->toDateString();
        $out = [];
        $commissions = Commission::with('project:id,code,name,active', 'tasks', 'services:id', 'user:id,name,active,worker_type')
            ->where('user_id', $user->id)->where('active', true)->whereHas('project', fn ($p) => $p->where('active', true))->get();
        foreach ($commissions as $c) {
            $tasks = [];
            foreach ($c->tasks->where('commissionable', true) as $task) {
                $tasks[] = $task->active ? $this->liveTask($c, $task, $today) : $this->doneTask($c, $task, $user);
            }
            $tasks = array_values(array_filter($tasks));
            usort($tasks, fn ($a, $b) => [$b['status'] === 'open', $a['task']['name']] <=> [$a['status'] === 'open', $b['task']['name']]);
            $out[] = [
                'id' => $c->id, 'project' => ['id' => $c->project->id, 'code' => $c->project->code, 'name' => $c->project->name],
                'terms' => ['percent_required' => (float) $c->percent_required, 'extra_per_hour' => (float) $c->extra_per_hour, 'savings_mode' => $c->savings_mode,
                    'savings_pct' => $c->savingsShare(), 'savings_per_hour' => (float) $c->savings_per_hour, 'tolerance_hours' => (float) $c->tolerance_hours,
                    'withhold' => (bool) $c->withhold],
                'tasks' => $tasks,
            ];
        }
        return $out;
    }

    private function taskBrief(Task $task, string $today): array
    {
        $left = $task->estimated_end ? (int) \Carbon\CarbonImmutable::parse($today)->diffInDays($task->estimated_end, false) : null;
        return ['id' => $task->id, 'name' => $task->name, 'estimated_end' => $task->estimated_end?->toDateString(), 'days_left' => $left,
            'completed_on' => $task->completed_on?->toDateString()];
    }

    /** Tarea abierta: cómo va hoy, con las horas aprobadas, y lo que ganaría si se completara hoy. */
    private function liveTask(Commission $c, Task $task, string $today): array
    {
        $eval = $this->evaluate($task, $today, collect([$c]));
        $r = $eval['rows'][0];
        $mine = (float) TimeEntry::where('task_id', $task->id)->where('user_id', $c->user_id)
            ->whereHas('timesheet', fn ($t) => $t->where('status', '!=', Timesheet::APPROVED))->sum('hours');
        return [
            'status' => 'open', 'task' => $this->taskBrief($task, $today),
            'team' => ['total_hours' => $r['total_hours'], 'goal_hours' => $r['goal_hours'], 'pending_hours' => $eval['pending_hours']],
            'mine' => ['hours' => $r['user_hours'], 'pct' => $r['user_pct'], 'percent_required' => $r['percent_required'], 'pending_hours' => round($mine, 2)],
            'conditions' => ['goal' => $r['met_goal'], 'date' => $r['met_date'], 'share' => $r['met_share'], 'min_hours' => $r['worked_min']],
            'would_earn' => ['commission' => $r['commission_amount'], 'savings' => $r['savings_amount'], 'total' => $r['total']],
        ];
    }

    /** Tarea completada: el resultado guardado. Sin liberar, solo se dice que está en revisión. */
    private function doneTask(Commission $c, Task $task, User $user): ?array
    {
        $r = CommissionResult::where('task_id', $task->id)->where('user_id', $user->id)->first();
        if (! $r) {
            return null;
        }
        $shown = in_array($r->status, ['released', 'invoiced', 'paid'], true);
        return [
            'status' => $r->status === 'pending' ? 'in_review' : $r->status, 'task' => $this->taskBrief($task, $r->completed_on->toDateString()),
            'team' => ['total_hours' => (float) $r->total_hours, 'goal_hours' => (float) $r->goal_hours],
            'mine' => ['hours' => (float) $r->user_hours, 'pct' => (float) $r->user_pct, 'percent_required' => (float) $r->percent_required],
            'conditions' => ['goal' => (bool) $r->met_goal, 'date' => (bool) $r->met_date, 'share' => (bool) $r->met_share, 'min_hours' => (bool) $r->worked_min],
            'earned' => $shown ? ['commission' => (float) $r->commission_amount, 'savings' => (float) $r->savings_amount, 'adjustment' => (float) $r->adjustment,
                'total' => (float) $r->total] : null,
        ];
    }
}
