<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approver;
use App\Models\ContractorTerms;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Personas de la compañía. Solo el dueño las crea y fija su modo de captura. */
class UserController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            User::with('terms')->where('company_id', $request->user()->company_id)->orderBy('name')->get()->map(fn ($u) => $this->payload($u))
        );
    }

    public function store(Request $request)
    {
        $data = $this->rules($request, null);
        $user = User::create($data['user'] + [
            'company_id' => $request->user()->company_id,
            'password' => $request->validate(['password' => 'required|string|min:10'])['password'],
        ]);
        $this->saveRelations($user, $data);
        return response()->json($this->payload($user->fresh('terms')), 201);
    }

    public function update(Request $request, User $user)
    {
        abort_unless($user->company_id === $request->user()->company_id, 404);
        $data = $this->rules($request, $user);
        $extra = $request->filled('password') ? ['password' => $request->validate(['password' => 'string|min:10'])['password']] : [];
        $user->update($data['user'] + $extra);
        $this->saveRelations($user, $data);
        return response()->json($this->payload($user->fresh('terms')));
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless($user->company_id === $request->user()->company_id, 404);
        abort_if($user->id === $request->user()->id, 422, 'No puedes desactivarte a ti mismo.');
        $user->update(['active' => false]);
        $user->tokens()->delete();
        return response()->json(['ok' => true]);
    }

    private function rules(Request $request, ?User $user): array
    {
        $companyId = $request->user()->company_id;
        $v = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => 'in:owner,member',
            'worker_type' => 'required|in:consultant,employee',
            // Lo decide el dueño por usuario: día completo o desde/hasta.
            'capture_mode' => 'required|in:full_day,from_to',
            'weekly_hours' => 'nullable|numeric|min:1|max:168',
            'approver_id' => ['nullable', 'integer', Rule::exists('approvers', 'id')->where('company_id', $companyId)],
            'active' => 'boolean',
            'project_ids' => 'nullable|array',
            'project_ids.*' => 'integer',
            'terms' => 'nullable|array',
            'terms.rate' => 'nullable|numeric|min:0',
            'terms.retention_pct' => 'nullable|numeric|between:0,100',
            'terms.ivu_agent' => 'nullable|boolean',
            'terms.ivu_pct' => 'nullable|numeric|between:0,100',
            'terms.terms_days' => 'nullable|integer|between:0,365',
            'terms.accountisi_vendor_id' => 'nullable|string|max:40',
        ]);
        if (($v['worker_type'] ?? null) === 'employee' && ! array_key_exists('weekly_hours', $v)) {
            $v['weekly_hours'] = 40;
        }
        if (($v['worker_type'] ?? null) === 'consultant') {
            $v['weekly_hours'] = null;
        }
        $projects = $v['project_ids'] ?? null;
        $terms = $v['terms'] ?? null;
        unset($v['project_ids'], $v['terms']);
        return ['user' => $v, 'projects' => $projects, 'terms' => $terms];
    }

    private function saveRelations(User $user, array $data): void
    {
        if ($data['projects'] !== null) {
            $user->projects()->sync(
                \App\Models\Project::where('company_id', $user->company_id)->whereIn('id', $data['projects'])->pluck('id')->all()
            );
        }
        // Los empleados no facturan: no llevan términos de contratista.
        if ($data['terms'] !== null && $user->worker_type === 'consultant') {
            ContractorTerms::updateOrCreate(['user_id' => $user->id], $data['terms']);
        }
    }

    private function payload(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role,
            'worker_type' => $u->worker_type, 'capture_mode' => $u->capture_mode,
            'weekly_hours' => $u->weekly_hours, 'approver_id' => $u->approver_id, 'active' => $u->active,
            'project_ids' => $u->projects()->pluck('projects.id'),
            'terms' => $u->terms,
        ];
    }
}
