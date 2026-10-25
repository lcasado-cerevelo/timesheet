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
    ];

    private const STATUS = [
        'pending' => Timesheet::SUBMITTED,
        'approved' => Timesheet::APPROVED,
        'returned' => Timesheet::RETURNED,
    ];

    /**
     * @param callable(Timesheet):array $present convierte una hoja en su resumen
     * @param callable(Timesheet):?string|null $classify dónde cae cada hoja (pending|approved|returned|null); por omisión, según su estado
     * @return array{sheets:array, counts:array}
     */
    public function run(Request $request, \Illuminate\Database\Eloquent\Builder $scope, callable $present, ?callable $classify = null): array
    {
        $f = $request->validate(self::FILTERS);
        $status = $f['status'] ?? 'pending';

        $all = $scope->whereIn('status', array_values(self::STATUS))
            ->when(! empty($f['type']), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('worker_type', $f['type'])))
            ->with('user', 'approver', 'entries.project')->get()
            ->filter(fn (Timesheet $s) => Text::matches($f['q'] ?? null, [$s->user->name]));

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
        ];
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
