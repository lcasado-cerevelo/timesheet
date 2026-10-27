<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tareas de un proyecto (opcionales). Solo el dueño las administra. Las tareas no se borran: se desactivan. */
class TaskController extends Controller
{
    public function store(Request $request, Project $project)
    {
        abort_unless($project->company_id === $request->user()->company_id, 404);
        $task = Task::create($this->validated($request, $project, null) + ['company_id' => $project->company_id, 'project_id' => $project->id]);
        return response()->json(self::payload($task->refresh(), true), 201);
    }

    public function update(Request $request, Task $task)
    {
        abort_unless($task->company_id === $request->user()->company_id, 404);
        $data = $this->validated($request, $task->project, $task);
        // Completar guarda la fecha; reabrir la quita.
        if (array_key_exists('active', $data) && (bool) $data['active'] !== (bool) $task->active) {
            $data['completed_on'] = $data['active'] ? null : now($task->project->company->timezone)->toDateString();
        }
        $task->update($data);
        return response()->json(self::payload($task->refresh(), true));
    }

    /** Solo se borra una tarea creada por error: sin horas registradas. Con horas se completa (active = false). */
    public function destroy(Request $request, Task $task)
    {
        abort_unless($task->company_id === $request->user()->company_id, 404);
        if ($task->entries()->exists()) {
            abort(422, __('Esta tarea ya tiene horas registradas: no se puede borrar. Márcala como completada.'));
        }
        $task->delete();
        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, Project $project, ?Task $task): array
    {
        $required = $task ? ['sometimes', 'required'] : ['required'];
        return $request->validate([
            'name' => [...$required, 'string', 'max:120', Rule::unique('tasks')->where('project_id', $project->id)->ignore($task?->id)],
            'active' => 'boolean',
            'budget_hours' => 'nullable|numeric|min:0|max:99999',
            'sort' => 'nullable|integer|min:0|max:65000',
        ]);
    }

    /** $owner: el dueño ve además el presupuesto de horas y las horas ya usadas; los demás solo lo que necesitan para escoger. */
    public static function payload(Task $t, bool $owner = false): array
    {
        $out = ['id' => $t->id, 'project_id' => $t->project_id, 'name' => $t->name, 'active' => $t->active, 'sort' => $t->sort];
        if ($owner) {
            $out['completed_on'] = $t->completed_on?->toDateString();
            $out['can_delete'] = ($t->entries_count ?? $t->entries()->count()) === 0;
            $out['budget_hours'] = $t->budget_hours === null ? null : (float) $t->budget_hours;
            $out['used_hours'] = round((float) ($t->used_hours ?? $t->entries()->sum('hours')), 2);
        }
        return $out;
    }
}
