<?php

namespace App\Services;

use App\Models\Timesheet;
use App\Support\Text;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Listas de revisión (aprobaciones) con filtros y conteos. Regla del diseño: "Pendientes" muestra siempre todo
 * lo que espera, sin importar las fechas; el periodo aplica a Aprobadas, Devueltas y Todas (en Todas, lo pendiente
 * se queda visible aunque caiga fuera del periodo).
 */
class ReviewService
{
    public const FILTERS = [
        'status' => 'nullable|in:pending,approved,returned,all',
        'q' => 'nullable|string|max:100',
        'type' => 'nullable|in:employee,consultant',
        'from' => 'nullable|date_format:Y-m-d',
        'to' => 'nullable|date_format:Y-m-d',
        // Proyecto, servicio y tarea: varios ids separados por comas; la hoja entra si tiene una línea que cumple todo lo marcado.
        'project_ids' => 'nullable|string|max:300',
        'service_ids' => 'nullable|string|max:300',
        'task_ids' => 'nullable|string|max:300',
    ];

    private const STATUS = [
        'pending' => Timesheet::SUBMITTED,
        'approved' => Timesheet::APPROVED,
        'returned' => Timesheet::RETURNED,
    ];

    /**
     * @param callable(Timesheet):array $present convierte una hoja en su resumen
     * @param callable(Timesheet):?string|null $classify dónde cae cada hoja (pending|approved|returned|null); por omisión, según su estado
     * @param callable(Timesheet):iterable|null $entriesOf las líneas de la hoja que esta persona puede ver (por omisión, todas)
     * @return array{sheets:array, counts:array, options:array}
     */
    public function run(Request $request, \Illuminate\Database\Eloquent\Builder $scope, callable $present, ?callable $classify = null, ?callable $entriesOf = null): array
    {
        $f = $request->validate(self::FILTERS);
        $status = $f['status'] ?? 'pending';

        $all = $scope->whereIn('status', array_values(self::STATUS))
            ->when(! empty($f['type']), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('worker_type', $f['type'])))
            ->with('user', 'approver', 'entries.project', 'entries.service', 'entries.task')->get()
            ->filter(fn (Timesheet $s) => Text::matches($f['q'] ?? null, [$s->user->name]));

        // Las opciones de proyecto, servicio y tarea salen de las líneas que esta persona ve, con cualquier estado.
        $mine = fn (Timesheet $s) => collect($entriesOf ? $entriesOf($s) : $s->entries);
        $options = $this->options($all, $mine);
        [$P, $S, $T] = [$this->ids($f['project_ids'] ?? null), $this->ids($f['service_ids'] ?? null), $this->ids($f['task_ids'] ?? null)];
        if ($P || $S || $T) {
            $all = $all->filter(fn (Timesheet $s) => $mine($s)->contains(fn ($e) => (! $P || in_array((int) $e->project_id, $P, true))
                && (! $S || in_array((int) $e->service_id, $S, true)) && (! $T || in_array((int) $e->task_id, $T, true))));
        }

        $inPeriod = fn (Timesheet $s) => (empty($f['from']) || $s->week_ending->toDateString() >= $f['from'])
            && (empty($f['to']) || $s->week_ending->toDateString() <= $f['to']);
        $is = $classify
            ? fn (Timesheet $s, string $k) => $classify($s) === $k
            : fn (Timesheet $s, string $k) => $s->status === self::STATUS[$k];

        $pending = $all->filter(fn ($s) => $is($s, 'pending'));
        $approved = $all->filter(fn ($s) => $is($s, 'approved') && $inPeriod($s));
        $returned = $all->filter(fn ($s) => $is($s, 'returned') && $inPeriod($s));

        $pick = match ($status) {
            'pending' => $pending->sortBy([['submitted_at', 'asc'], ['id', 'asc']]),
            'approved' => $approved->sortByDesc(fn ($s) => $s->decided_at?->timestamp ?? 0),
            'returned' => $returned->sortByDesc(fn ($s) => $s->decided_at?->timestamp ?? 0),
            default => $pending->sortBy([['submitted_at', 'asc'], ['id', 'asc']])->values()
                ->concat($approved->concat($returned)->sortByDesc(fn ($s) => $s->week_ending->toDateString().'|'.str_pad((string) $s->id, 10, '0', STR_PAD_LEFT))),
        };

        return [
            'sheets' => $pick->values()->map($present)->values()->all(),
            'counts' => [
                'pending' => $pending->count(),
                'approved' => $approved->count(),
                'returned' => $returned->count(),
                'all' => $pending->count() + $approved->count() + $returned->count(),
            ],
            'options' => $options,
        ];
    }

    /** "3,5,8" → [3, 5, 8]. */
    private function ids(?string $csv): array
    {
        return array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $csv)))));
    }

    /** Los proyectos, servicios y tareas que aparecen en las líneas de estas hojas, para los filtros de la pantalla. */
    private function options(Collection $sheets, callable $mine): array
    {
        $projects = $services = $tasks = [];
        foreach ($sheets as $s) {
            foreach ($mine($s) as $e) {
                if (! $e->project) {
                    continue;
                }
                $code = $e->project->code;
                $projects[$e->project_id] = ['id' => $e->project_id, 'label' => $code.' · '.$e->project->name];
                if ($e->service) {
                    $services[$e->service_id] = ['id' => $e->service_id, 'project_id' => $e->project_id, 'label' => $code.' · '.$e->service->name];
                }
                if ($e->task) {
                    $tasks[$e->task_id] = ['id' => $e->task_id, 'project_id' => $e->project_id, 'label' => $code.' · '.$e->task->name];
                }
            }
        }
        $sorted = fn (array $a) => array_values(collect($a)->sortBy(fn ($x) => mb_strtolower($x['label']))->all());
        return ['projects' => $sorted($projects), 'services' => $sorted($services), 'tasks' => $sorted($tasks)];
    }

    /** Campos extra de la lista: tipo de persona, número de empleado, proyectos con horas y desglose de horas. */
    public function extras(Timesheet $s): array
    {
        $entries = $s->relationLoaded('entries') ? $s->entries : $s->entries()->with('project')->get();
        return [
            'worker_type' => $s->user->worker_type,
            'employee_number' => $s->user->employee_number,
            'projects' => $this->projects($entries),
        ] + $s->hoursSplit($entries);
    }

    private function projects(Collection $entries): array
    {
        return $entries->filter(fn ($e) => (float) $e->hours > 0 && $e->project)
            ->map(fn ($e) => $e->project->code.' · '.$e->project->name)->unique()->values()->all();
    }
}
