<?php

namespace App\Services;

use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Week;
use Illuminate\Support\Collection;

/**
 * Reporte de horas. Quién lo pide decide qué horas entran: el dueño y la oficina, las de toda la compañía; el gerente de proyecto, las de todos
 * en los proyectos de sus clientes (solo lo que ya salió de la etapa interna); los demás, las suyas. Cada total viene separado por estado de la
 * hoja (aprobadas, enviadas esperando, devueltas, borrador) para que cuadre con la hoja del cliente, que solo lleva las aprobadas.
 */
class HoursReportService
{
    public const GROUPS = ['project', 'day', 'week', 'customer', 'person'];

    public function __construct(private ManagerReportService $manager)
    {
    }

    /** @return Collection<int, TimeEntry> */
    public function entries(User $u, array $d): Collection
    {
        $project = ! empty($d['project_id']) ? (int) $d['project_id'] : null;
        $person = ! empty($d['user_id']) ? (int) $d['user_id'] : null;
        $customer = ! empty($d['customer_id']) ? (int) $d['customer_id'] : null;
        if ($u->isApprover()) {
            return $this->manager->entries($this->manager->manager($u), $d['from'], $d['to'], $project)
                ->when($person, fn ($c) => $c->where('user_id', $person))
                ->when($customer, fn ($c) => $c->filter(fn ($e) => (int) $e->project->customer_id === $customer))->values();
        }
        $q = TimeEntry::with('project.customer', 'user:id,name', 'timesheet:id,status,stage')->where('running', false)->whereBetween('work_date', [$d['from'], $d['to']])
            ->when($project, fn ($x) => $x->where('project_id', $project))
            ->when($customer, fn ($x) => $x->whereHas('project', fn ($p) => $p->where('customer_id', $customer)));
        if ($u->isStaff()) {
            $q->where('company_id', $u->company_id)->when($person, fn ($x) => $x->where('user_id', $person));
        } else {
            $q->where('user_id', $u->id);
        }
        return $q->get();
    }

    /** @return array{group:string, rows:array<int,array>, total:array} */
    public function summary(User $u, array $d): array
    {
        $entries = $this->entries($u, $d);
        $group = in_array($d['group'] ?? 'project', self::GROUPS, true) ? ($d['group'] ?? 'project') : 'project';
        $weekStart = $u->company->rules()->weekStart();
        $rows = $entries->groupBy(fn ($e) => match ($group) {
            'day' => $e->work_date->toDateString(),
            'week' => Week::ending($e->work_date, $weekStart),
            'customer' => $e->project->customer?->name ?? __('(sin cliente)'),
            'person' => $e->user?->name ?? '—',
            default => $e->project->code.' · '.$e->project->name,
        })->map(fn ($g, $k) => ['label' => (string) $k] + $this->totals($g))->sortKeys()->values()->all();
        return ['group' => $group, 'rows' => $rows, 'total' => $this->totals($entries)];
    }

    /** Estado de la hoja de la entrada, en cuatro: aprobada, enviada (esperando aprobación), devuelta o borrador. */
    private function state(TimeEntry $e): string
    {
        return match ($e->timesheet?->status) {
            'approved' => 'approved',
            'submitted' => 'submitted',
            'returned' => 'returned',
            default => 'draft',
        };
    }

    private function totals(Collection $g): array
    {
        $sum = fn (Collection $c) => round((float) $c->sum('hours'), 2);
        return [
            'hours' => $sum($g),
            'billable_hours' => $sum($g->where('billable', true)),
            'non_billable_hours' => $sum($g->where('billable', false)),
            'approved_hours' => $sum($g->filter(fn ($e) => $this->state($e) === 'approved')),
            'submitted_hours' => $sum($g->filter(fn ($e) => $this->state($e) === 'submitted')),
            'returned_hours' => $sum($g->filter(fn ($e) => $this->state($e) === 'returned')),
            'draft_hours' => $sum($g->filter(fn ($e) => $this->state($e) === 'draft')),
        ];
    }
}
