<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Servicios de un proyecto. Solo el dueño los administra. Los servicios no se borran: se desactivan. */
class ServiceController extends Controller
{
    public function store(Request $request, Project $project)
    {
        abort_unless($project->company_id === $request->user()->company_id, 404);
        $data = $this->validated($request, $project, null);
        $service = Service::create($data + ['company_id' => $project->company_id, 'project_id' => $project->id]);
        return response()->json(self::payload($service, true), 201);
    }

    public function update(Request $request, Service $service)
    {
        abort_unless($service->company_id === $request->user()->company_id, 404);
        $data = $this->validated($request, $service->project, $service);
        // Un proyecto siempre conserva al menos un servicio activo.
        if (($data['active'] ?? true) === false
            && ! Service::where('project_id', $service->project_id)->where('active', true)->where('id', '!=', $service->id)->exists()) {
            abort(422, __('El proyecto necesita al menos un servicio activo.'));
        }
        $service->update($data);
        return response()->json(self::payload($service->refresh(), true));
    }

    private function validated(Request $request, Project $project, ?Service $service): array
    {
        $required = $service ? 'sometimes|required' : 'required';
        return $request->validate([
            'name' => [$required, 'string', 'max:120', Rule::unique('services')->where('project_id', $project->id)->ignore($service?->id)],
            'bill_rate' => 'nullable|numeric|min:0',
            'billable' => 'boolean',
            'capture_mode' => ['sometimes', Rule::in(Service::MODES)],
            'accountisi_item_id' => 'nullable|string|max:40',
            'active' => 'boolean',
            'sort' => 'nullable|integer|min:0|max:65000',
        ]);
    }

    /** $owner: el dueño ve tarifas y artículo de Accountisi; los demás solo lo que necesitan para escoger. */
    public static function payload(Service $s, bool $owner, ?\App\Models\User $for = null): array
    {
        $out = ['id' => $s->id, 'project_id' => $s->project_id, 'name' => $s->name, 'billable' => $s->billable,
            'capture_mode' => $s->capture_mode, 'active' => $s->active, 'sort' => $s->sort];
        if ($for) {
            // Cómo entra las horas esta persona en este servicio: 'from_to' (detalle) o 'full_day' (total).
            $out['mode'] = $s->effectiveMode($for);
        }
        if ($owner) {
            $out += ['bill_rate' => $s->bill_rate, 'accountisi_item_id' => $s->accountisi_item_id];
        }
        return $out;
    }
}
