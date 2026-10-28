<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Service;
use App\Models\Signature;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\InternalApprovalService;
use App\Services\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tareas de un proyecto. El dueño las administra (crear, editar, borrar si no tienen horas, reabrir). Una tarea está abierta
 * (active = true) o completada (active = false, con su fecha): la completan el dueño y los aprobadores internos del proyecto,
 * firmando con su contraseña.
 */
class TaskController extends Controller
{
    public function store(Request $request, Project $project)
    {
        abort_unless($project->company_id === $request->user()->company_id, 404);
        $task = Task::create($this->validated($request, $project, null) + ['company_id' => $project->company_id, 'project_id' => $project->id]);
        return response()->json(self::payload($task->refresh(), true), 201);
    }

    /** Edita nombre, presupuesto, fecha estimada, meta de ahorro y si aplica comisión. Abrir y cerrar la tarea se hace con complete/reopen (firmados). */
    public function update(Request $request, Task $task)
    {
        abort_unless($task->company_id === $request->user()->company_id, 404);
        $task->update($this->validated($request, $task->project, $task));
        return response()->json(self::payload($task->refresh(), true));
    }

    /** Solo se borra una tarea creada por error: sin horas registradas. Con horas se completa. */
    public function destroy(Request $request, Task $task)
    {
        abort_unless($task->company_id === $request->user()->company_id, 404);
        if ($task->entries()->exists()) {
            abort(422, __('Esta tarea ya tiene horas registradas: no se puede borrar. Márcala como completada.'));
        }
        $task->delete();
        return response()->json(['ok' => true]);
    }

    /** Las tareas que esta persona puede completar: todas las de la compañía (dueño) o las de los proyectos que supervisa. */
    public function index(Request $request)
    {
        $u = $request->user();
        $projectIds = $this->completableProjectIds($u);
        abort_if($projectIds === [], 403);
        $q = Task::with('project:id,code,name,active')->where('company_id', $u->company_id)
            ->withSum('entries as used_hours', 'hours')->withCount('entries')
            ->withSum(['entries as approved_hours' => fn ($e) => $e->whereHas('timesheet', fn ($t) => $t->where('status', Timesheet::APPROVED))], 'hours')
            ->when($projectIds !== ['*'], fn ($x) => $x->whereIn('project_id', $projectIds))
            ->when($request->query('status') === 'open', fn ($x) => $x->where('active', true))
            ->when($request->query('status') === 'completed', fn ($x) => $x->where('active', false))
            ->when($request->query('project_id'), fn ($x, $id) => $x->where('project_id', (int) $id))
            ->orderBy('project_id')->orderByDesc('active')->orderBy('name');
        return response()->json($q->get()->map(fn (Task $t) => self::payload($t, true) + [
            'project' => ['id' => $t->project->id, 'code' => $t->project->code, 'name' => $t->project->name],
            'pending_hours' => round(max(0, (float) $t->used_hours - (float) $t->approved_hours), 2),
        ])->values());
    }

    /** Lo que se necesita para completar: la fecha por defecto (la de las últimas horas) y cuántas horas siguen sin aprobar. */
    public function completion(Request $request, Task $task)
    {
        $this->authorizeComplete($request->user(), $task);
        return response()->json($this->completionInfo($task));
    }

    public function complete(Request $request, Task $task)
    {
        $user = $request->user();
        $this->authorizeComplete($user, $task);
        if (! $task->active) {
            abort(422, __('Esta tarea ya está completada.'));
        }
        $data = $request->validate(['completed_on' => 'nullable|date_format:Y-m-d']);
        $info = $this->completionInfo($task);
        $on = $data['completed_on'] ?? $info['default_completed_on'];
        if ($on > $info['today']) {
            throw ValidationException::withMessages(['completed_on' => __('La fecha de completada no puede ser futura.')]);
        }
        app(SignatureService::class)->sign($request, $user, 'task.complete', $task, ['completed_on' => $on, 'hours' => $info['total_hours']]);
        $task->update(['active' => false, 'completed_on' => $on]);
        return response()->json(self::payload($task->refresh(), true));
    }

    /** Solo el dueño reabre una tarea. */
    public function reopen(Request $request, Task $task)
    {
        $user = $request->user();
        abort_unless($task->company_id === $user->company_id, 404);
        if ($task->active) {
            abort(422, __('Esta tarea ya está abierta.'));
        }
        app(SignatureService::class)->sign($request, $user, 'task.reopen', $task, ['was_completed_on' => $task->completed_on?->toDateString()]);
        $task->update(['active' => true, 'completed_on' => null]);
        return response()->json(self::payload($task->refresh(), true));
    }

    private function completionInfo(Task $task): array
    {
        $tz = $task->company->timezone;
        $today = now($tz)->toDateString();
        $total = (float) $task->entries()->sum('hours');
        $approved = (float) $task->entries()->whereHas('timesheet', fn ($t) => $t->where('status', Timesheet::APPROVED))->sum('hours');
        $last = $task->entries()->max('work_date');
        $last = $last ? substr((string) $last, 0, 10) : null;
        return [
            'today' => $today,
            'last_work_date' => $last,
            // Por defecto la tarea se completa en la fecha de sus últimas horas; quien completa puede cambiarla.
            'default_completed_on' => $last ?? $today,
            'total_hours' => round($total, 2),
            'approved_hours' => round($approved, 2),
            'pending_hours' => round(max(0, $total - $approved), 2),
        ];
    }

    private function authorizeComplete(User $u, Task $task): void
    {
        abort_unless($task->company_id === $u->company_id, 404);
        $ids = $this->completableProjectIds($u);
        abort_unless($ids === ['*'] || in_array($task->project_id, $ids, true), 403);
    }

    /** ['*'] = todos los proyectos (dueño); si no, los ids de los proyectos donde supervisa algún servicio; [] = no puede completar. */
    private function completableProjectIds(User $u): array
    {
        if ($u->isApprover()) {
            return [];
        }
        if ($u->isOwner()) {
            return ['*'];
        }
        $serviceIds = app(InternalApprovalService::class)->supervisedServiceIds($u, true);
        return $serviceIds ? Service::whereIn('id', $serviceIds)->pluck('project_id')->map(fn ($i) => (int) $i)->unique()->values()->all() : [];
    }

    private function validated(Request $request, Project $project, ?Task $task): array
    {
        $required = $task ? ['sometimes', 'required'] : ['required'];
        $v = $request->validate([
            'name' => [...$required, 'string', 'max:120', Rule::unique('tasks')->where('project_id', $project->id)->ignore($task?->id)],
            'budget_hours' => 'nullable|numeric|min:0|max:99999',
            'estimated_end' => 'nullable|date_format:Y-m-d',
            'savings_goal_pct' => 'nullable|numeric|min:0|max:100',
            'commissionable' => 'boolean',
            'sort' => 'nullable|integer|min:0|max:65000',
        ]);
        // Una tarea con comisión necesita presupuesto de horas: sin él no hay contra qué medir el ahorro.
        $commissionable = array_key_exists('commissionable', $v) ? (bool) $v['commissionable'] : (bool) $task?->commissionable;
        $budget = array_key_exists('budget_hours', $v) ? $v['budget_hours'] : $task?->budget_hours;
        if ($commissionable && (float) $budget <= 0) {
            throw ValidationException::withMessages(['budget_hours' => __('Una tarea con comisión necesita un presupuesto de horas.')]);
        }
        return $v;
    }

    /** $owner: dueño y aprobadores internos ven además presupuesto, fechas y horas; los demás solo lo que necesitan para escoger. */
    public static function payload(Task $t, bool $owner = false): array
    {
        $out = ['id' => $t->id, 'project_id' => $t->project_id, 'name' => $t->name, 'active' => $t->active, 'sort' => $t->sort];
        if ($owner) {
            $out['completed_on'] = $t->completed_on?->toDateString();
            $out['can_delete'] = ($t->entries_count ?? $t->entries()->count()) === 0;
            $out['budget_hours'] = $t->budget_hours === null ? null : (float) $t->budget_hours;
            $out['used_hours'] = round((float) ($t->used_hours ?? $t->entries()->sum('hours')), 2);
            $out['estimated_end'] = $t->estimated_end?->toDateString();
            $out['savings_goal_pct'] = $t->savings_goal_pct === null ? null : (float) $t->savings_goal_pct;
            $out['commissionable'] = (bool) $t->commissionable;
            $out['completed_by'] = null;
            if (! $t->active) {
                $sig = Signature::with('user:id,name')->where('subject_type', 'task')->where('subject_id', $t->id)->where('action', 'task.complete')->latest('id')->first();
                $out['completed_by'] = $sig?->user?->name;
            }
        }
        return $out;
    }
}
