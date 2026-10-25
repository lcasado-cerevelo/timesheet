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
        $q = Project::with('customer:id,name', 'users:id,name', 'services')->where('company_id', $u->company_id)->orderBy('code');
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
        return response()->json($this->payload($project->fresh(['customer', 'users', 'services']), true, $request->user()), 201);
    }

    public function update(Request $request, Project $project)
    {
        abort_unless($project->company_id === $request->user()->company_id, 404);
        $data = $this->validated($request, $project);
        $project->update($data['fields']);
        if (isset($data['user_ids'])) {
            $project->users()->sync($this->ownUsers($request, $data['user_ids']));
        }
        return response()->json($this->payload($project->fresh(['customer', 'users', 'services']), true, $request->user()));
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
            'invoice_detail' => 'in:hours_total,by_day,with_times',
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
        $ids = $v['user_ids'] ?? null;
        unset($v['user_ids']);
        return ['fields' => $v, 'user_ids' => $ids];
    }

    private function ownUsers(Request $request, array $ids): array
    {
        return \App\Models\User::where('company_id', $request->user()->company_id)->whereIn('id', $ids)->pluck('id')->all();
    }

    private function payload(Project $p, bool $owner, ?User $for = null): array
    {
        $out = $p->only(['id', 'code', 'name', 'customer_id', 'billable', 'active', 'invoice_detail', 'meta',
            'geo_lat', 'geo_lng', 'geo_radius_m', 'budget_hours']);
        $out['customer'] = $p->customer?->only(['id', 'name']);
        // Servicios del proyecto: el dueño ve todos (también los desactivados); los demás solo los activos.
        $out['services'] = $p->services->when(! $owner, fn ($c) => $c->where('active', true))->values()
            ->map(fn ($s) => ServiceController::payload($s, $owner, $for))->all();
        if ($owner) {
            $out += ['bill_rate' => $p->bill_rate, 'approver_id' => $p->approver_id, 'user_ids' => $p->users->pluck('id'),
                'accountisi_id' => $p->accountisi_id];
        }
        return $out;
    }
}
