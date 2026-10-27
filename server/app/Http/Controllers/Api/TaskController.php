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
        return response()->json(self::payload($task->refresh()), 201);
    }

    public function update(Request $request, Task $task)
    {
        abort_unless($task->company_id === $request->user()->company_id, 404);
        $task->update($this->validated($request, $task->project, $task));
        return response()->json(self::payload($task->refresh()));
    }

    private function validated(Request $request, Project $project, ?Task $task): array
    {
        $required = $task ? ['sometimes', 'required'] : ['required'];
        return $request->validate([
            'name' => [...$required, 'string', 'max:120', Rule::unique('tasks')->where('project_id', $project->id)->ignore($task?->id)],
            'active' => 'boolean',
            'sort' => 'nullable|integer|min:0|max:65000',
        ]);
    }

    public static function payload(Task $t): array
    {
        return ['id' => $t->id, 'project_id' => $t->project_id, 'name' => $t->name, 'active' => $t->active, 'sort' => $t->sort];
    }
}
