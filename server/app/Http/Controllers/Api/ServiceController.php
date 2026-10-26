<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Services\ExternalApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Servicios de un proyecto. Solo el dueño los administra. Los servicios no se borran: se desactivan. */
class ServiceController extends Controller
{
    public function store(Request $request, Project $project)
    {
        abort_unless($project->company_id === $request->user()->company_id, 404);
        $data = $this->validated($request, $project, null);
        $lists = $this->approvalLists($request, $project, null, $data);
        $service = DB::transaction(function () use ($data, $project, $lists) {
            $service = Service::create($data + ['company_id' => $project->company_id, 'project_id' => $project->id]);
            $this->saveLists($service, $lists);
            return $service;
        });
        return response()->json(self::payload($service->refresh(), true), 201);
    }

    public function update(Request $request, Service $service)
    {
        abort_unless($service->company_id === $request->user()->company_id, 404);
        $data = $this->validated($request, $service->project, $service);
        $lists = $this->approvalLists($request, $service->project, $service, $data);
        // Un proyecto siempre conserva al menos un servicio activo.
        if (($data['active'] ?? true) === false
            && ! Service::where('project_id', $service->project_id)->where('active', true)->where('id', '!=', $service->id)->exists()) {
            abort(422, __('El proyecto necesita al menos un servicio activo.'));
        }
        DB::transaction(function () use ($service, $data, $lists) {
            $service->update($data);
            $this->saveLists($service, $lists);
        });
        return response()->json(self::payload($service->refresh(), true));
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /**
     * Quiénes aprueban este servicio (reglas de Luis):
     *  - interno / both: al menos un supervisor activo de la compañía (no se puede omitir); basta que apruebe uno;
     *  - externo / both: el proyecto necesita cliente y el cliente al menos un aprobador activo; los escogidos deben ser de ese
     *    cliente; si se omite la lista, la hoja va a todos los del cliente.
     * Solo se valida cuando la petición toca la aprobación (approval_mode o alguna lista): los servicios viejos sin tocar, y los que
     * un cliente antiguo crea sin approval_mode (quedan 'external' con el respaldo), siguen funcionando. Un servicio que se desactiva no se valida.
     * Una lista que no aplica al modo (p. ej. externos en un servicio 'internal') se vacía.
     * @return array{internal:?array, external:?array} ids a guardar (null = no tocar la lista)
     */
    private function approvalLists(Request $request, Project $project, ?Service $service, array $data): array
    {
        $out = ['internal' => null, 'external' => null];
        $mode = $data['approval_mode'] ?? $service?->approval_mode ?? 'external';
        $active = $data['active'] ?? $service?->active ?? true;
        $touches = $request->hasAny(['approval_mode', 'internal_approver_ids', 'external_approver_ids']);
        if (! $touches || ! $active) {
            return $out;
        }
        $cid = $project->company_id;

        if (in_array($mode, ['internal', 'both'], true)) {
            $ids = $request->has('internal_approver_ids')
                ? array_values(array_unique(array_map('intval', (array) $request->input('internal_approver_ids'))))
                : ($service ? $service->internalApprovers()->pluck('users.id')->map(fn ($i) => (int) $i)->all() : []);
            if (! $ids) {
                $this->fail('internal_approver_ids', __('Escoge al menos un aprobador interno: un servicio con aprobación interna no puede quedar sin él.'));
            }
            $valid = User::where('company_id', $cid)->where('active', true)->where('role', '!=', 'approver')->whereIn('id', $ids)->count();
            if ($valid !== count($ids)) {
                $this->fail('internal_approver_ids', __('Algún aprobador interno no existe, está inactivo o no es de esta compañía.'));
            }
            $out['internal'] = $request->has('internal_approver_ids') ? $ids : null;
        } else {
            $out['internal'] = [];
        }

        if (in_array($mode, ['external', 'both'], true)) {
            $customer = $project->customer;
            if (! $customer) {
                $this->fail('external_approver_ids', __('El proyecto no tiene cliente: ponle uno antes de pedir aprobación externa.'));
            }
            $linked = $customer->approvers()->where('approvers.active', true)->where('approvers.company_id', $cid)->pluck('approvers.id')->map(fn ($i) => (int) $i)->all();
            if (! $linked) {
                $this->fail('external_approver_ids', __('El cliente :client no tiene aprobadores externos activos: ligales uno antes de pedir aprobación externa.', ['client' => $customer->name]));
            }
            $ids = $request->has('external_approver_ids')
                ? array_values(array_unique(array_map('intval', (array) $request->input('external_approver_ids'))))
                : ($service ? $service->externalApprovers()->pluck('approvers.id')->map(fn ($i) => (int) $i)->all() : []);
            if (array_diff($ids, $linked)) {
                $this->fail('external_approver_ids', __('Algún aprobador externo no pertenece al cliente :client.', ['client' => $customer->name]));
            }
            $out['external'] = $request->has('external_approver_ids') ? $ids : null;
        } else {
            $out['external'] = [];
        }
        return $out;
    }

    private function saveLists(Service $service, array $lists): void
    {
        if ($lists['internal'] !== null) {
            $service->internalApprovers()->sync($lists['internal']);
        }
        if ($lists['external'] !== null) {
            $service->externalApprovers()->sync($lists['external']);
        }
    }

    private function validated(Request $request, Project $project, ?Service $service): array
    {
        $required = $service ? ['sometimes', 'required'] : ['required'];
        $v = $request->validate([
            'name' => [...$required, 'string', 'max:120', Rule::unique('services')->where('project_id', $project->id)->ignore($service?->id)],
            'bill_rate' => 'nullable|numeric|min:0',
            'billable' => 'boolean',
            'require_note' => 'boolean',
            'capture_mode' => ['sometimes', Rule::in(Service::MODES)],
            'accountisi_item_id' => 'nullable|string|max:40',
            'approval_mode' => ['sometimes', Rule::in(Service::APPROVAL_MODES)],
            'internal_approver_ids' => 'nullable|array',
            'internal_approver_ids.*' => 'integer',
            'external_approver_ids' => 'nullable|array',
            'external_approver_ids.*' => 'integer',
            'active' => 'boolean',
            'sort' => 'nullable|integer|min:0|max:65000',
        ]);
        unset($v['internal_approver_ids'], $v['external_approver_ids']);
        return $v;
    }

    /** $owner: el dueño ve tarifas, artículo de Accountisi y quiénes aprueban; los demás solo lo que necesitan para escoger. */
    public static function payload(Service $s, bool $owner, ?User $for = null, ?Project $project = null): array
    {
        $out = ['id' => $s->id, 'project_id' => $s->project_id, 'name' => $s->name, 'billable' => $s->billable,
            'capture_mode' => $s->capture_mode, 'approval_mode' => $s->approval_mode ?: 'external', 'active' => $s->active, 'sort' => $s->sort,
            'require_note' => (bool) $s->require_note];
        if ($for) {
            // Cómo entra las horas esta persona en este servicio: 'from_to' (detalle) o 'full_day' (total).
            $out['mode'] = $s->effectiveMode($for);
        }
        if ($owner) {
            $out += ['bill_rate' => $s->bill_rate, 'accountisi_item_id' => $s->accountisi_item_id];
            $project ??= $s->project;
            $project->loadMissing('customer.approvers');
            $s->loadMissing('internalApprovers', 'externalApprovers');
            $pair = fn ($c) => $c->sortBy('name')->map(fn ($x) => ['id' => $x->id, 'name' => $x->name])->values();
            $internal = $pair($s->internalApprovers);
            $external = $pair($s->externalApprovers);
            $effective = $s->needsExternal() ? app(ExternalApprovalService::class)->effective($s, $project) : collect();
            $out += [
                'internal_approver_ids' => $internal->pluck('id')->all(),
                'internal_approvers' => $internal->all(),
                'external_approver_ids' => $external->pluck('id')->all(),
                'external_approvers' => $external->all(),
                'external_effective' => $pair($effective)->all(),
                'client' => $project->customer ? ['id' => $project->customer->id, 'name' => $project->customer->name] : null,
            ];
        }
        return $out;
    }
}
