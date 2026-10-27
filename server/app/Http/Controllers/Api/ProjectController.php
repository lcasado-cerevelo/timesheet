<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Los proyectos los administra solo el dueño; los demás usuarios solo los ven (los asignados). */
class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $q = Project::with('customer.approvers', 'users:id,name', 'services.internalApprovers', 'services.externalApprovers', 'tasks')->where('company_id', $u->company_id)->orderBy('code');
        if (! $u->isOwner()) {
            $q->where('active', true)->whereHas('users', fn ($x) => $x->where('users.id', $u->id));
        }
        return response()->json($q->get()->map(fn ($p) => $this->payload($p, $u->isOwner(), $u)));
    }

    public function store(Request $request)
    {
        $company = $request->user()->company_id;
        $data = $this->validated($request, null);
        $project = Project::create($data['fields'] + ['company_id' => $company]);
        if (isset($data['user_ids'])) {
            $project->users()->sync($this->ownUsers($request, $data['user_ids']));
        }
        return response()->json($this->payload($project->fresh(['customer.approvers', 'users', 'services.internalApprovers', 'services.externalApprovers', 'tasks']), true, $request->user()), 201);
    }

    public function update(Request $request, Project $project)
    {
        abort_unless($project->company_id === $request->user()->company_id, 404);
        $data = $this->validated($request, $project);
        $project->update($data['fields']);
        if (isset($data['user_ids'])) {
            $project->users()->sync($this->ownUsers($request, $data['user_ids']));
        }
        return response()->json($this->payload($project->fresh(['customer.approvers', 'users', 'services.internalApprovers', 'services.externalApprovers', 'tasks']), true, $request->user()));
    }

    /** Un proyecto con horas no se borra; se desactiva. */
    public function destroy(Request $request, Project $project)
    {
        abort_unless($project->company_id === $request->user()->company_id, 404);
        $project->update(['active' => false]);
        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?Project $project): array
    {
        $companyId = $request->user()->company_id;
        $v = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('projects')->where('company_id', $companyId)->ignore($project?->id)],
            'name' => 'required|string|max:190',
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'approver_id' => ['nullable', 'integer', Rule::exists('approvers', 'id')->where('company_id', $companyId)],
            'billable' => 'boolean',
            'active' => 'boolean',
            'bill_rate' => 'nullable|numeric|min:0',
            'budget_hours' => 'nullable|numeric|min:0',
            'meta' => 'nullable|array',
            'geo_lat' => 'nullable|numeric|between:-90,90',
            'geo_lng' => 'nullable|numeric|between:-180,180',
            'geo_radius_m' => 'nullable|integer|between:50,5000',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer',
        ]);
        // Un proyecto facturable necesita cliente (el que se envía o, al editar, el que ya tiene).
        $billable = array_key_exists('billable', $v) ? (bool) $v['billable'] : ($project ? (bool) $project->billable : true);
        $customerId = array_key_exists('customer_id', $v) ? $v['customer_id'] : $project?->customer_id;
        if ($billable && ! $customerId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'customer_id' => __('Un proyecto facturable necesita un cliente. Elige uno o marca el proyecto como no facturable.')]);
        }
        // Quiénes aprueban se decide por servicio (ServiceController): internal_approver_ids y requires_internal_approval ya no se
        // usan aquí (requires_internal_approval se deriva de los servicios activos con aprobación interna) y se ignoran.
        // Cambiar el cliente no puede dejar, en servicios externos, aprobadores escogidos que no son del cliente nuevo.
        if ($project && array_key_exists('customer_id', $v) && (int) $v['customer_id'] !== (int) $project->customer_id) {
            $bad = $project->services()->with('externalApprovers.customers')->get()
                ->filter(fn ($svc) => $svc->externalApprovers->contains(fn ($a) => ! $a->customers->contains('id', $v['customer_id'])));
            if ($bad->isNotEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'customer_id' => __('Hay servicios con aprobadores externos de otro cliente (:services). Revisa esos servicios antes de cambiar el cliente.', ['services' => $bad->pluck('name')->implode(', ')])]);
            }
        }
        // invoice_detail ya no se usa: se acepta y se ignora (clientes antiguos). El detalle de la factura sigue cómo se entraron las horas.
        $ids = $v['user_ids'] ?? null;
        unset($v['user_ids']);
        return ['fields' => $v, 'user_ids' => $ids];
    }

    /** Opciones para los selectores de aprobadores de los servicios del proyecto: internos posibles y externos del cliente. */
    public function approverOptions(Request $request, Project $project)
    {
        abort_unless($project->company_id === $request->user()->company_id, 404);
        $project->loadMissing('customer');
        $pair = fn ($c) => $c->sortBy(fn ($x) => mb_strtolower($x->name))->map(fn ($x) => ['id' => $x->id, 'name' => $x->name])->values();
        return response()->json([
            'client' => $project->customer ? ['id' => $project->customer->id, 'name' => $project->customer->name] : null,
            'internal' => $pair(User::where('company_id', $project->company_id)->where('active', true)->where('role', '!=', 'approver')->get()),
            'external' => $project->customer
                ? $pair($project->customer->approvers()->where('approvers.active', true)->where('approvers.company_id', $project->company_id)->get()) : [],
        ]);
    }

    private function ownUsers(Request $request, array $ids): array
    {
        return \App\Models\User::where('company_id', $request->user()->company_id)->whereIn('id', $ids)->pluck('id')->all();
    }

    private function payload(Project $p, bool $owner, ?User $for = null): array
    {
        $out = $p->only(['id', 'code', 'name', 'customer_id', 'billable', 'active', 'meta',
            'geo_lat', 'geo_lng', 'geo_radius_m', 'budget_hours']);
        $out['customer'] = $p->customer?->only(['id', 'name']);
        // Servicios del proyecto: el dueño ve todos (también los desactivados); los demás solo los activos.
        $out['services'] = $p->services->when(! $owner, fn ($c) => $c->where('active', true))->values()
            ->map(fn ($s) => ServiceController::payload($s, $owner, $for, $p))->all();
        // Tareas (opcionales): el dueño ve todas; los demás solo las activas.
        $out['tasks'] = $p->tasks->when(! $owner, fn ($c) => $c->where('active', true))->values()
            ->map(fn ($t) => TaskController::payload($t, $owner))->all();
        if ($owner) {
            $out += ['bill_rate' => $p->bill_rate, 'approver_id' => $p->approver_id, 'user_ids' => $p->users->pluck('id'),
                'accountisi_id' => $p->accountisi_id,
                'requires_internal_approval' => (bool) $p->requires_internal_approval];
        }
        return $out;
    }
}
