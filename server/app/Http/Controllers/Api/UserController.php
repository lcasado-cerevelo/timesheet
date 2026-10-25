<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approver;
use App\Models\ContractorTerms;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Personas de la compañía. Solo el dueño las crea y fija su modo de captura. */
class UserController extends Controller
{
    public function __construct(private AccountService $accounts)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            User::with('terms')->where('company_id', $request->user()->company_id)->where('role', '!=', 'approver')->orderBy('name')->get()->map(fn ($u) => $this->payload($u))
        );
    }

    public function store(Request $request)
    {
        $data = $this->rules($request, null);
        // El dueño nunca escribe contraseñas: la cuenta nace con una inservible y la persona crea la suya con la invitación.
        $user = User::create($data['user'] + [
            'company_id' => $request->user()->company_id,
            'password' => Str::random(64),
            'must_set_password' => true,
        ]);
        $this->saveRelations($user, $data);
        $this->accounts->invite($user->load('company'), $request->user());
        return response()->json($this->payload($user->fresh('terms')), 201);
    }

    public function update(Request $request, User $user)
    {
        $this->mine($request, $user);
        $data = $this->rules($request, $user);
        if ($user->isApprover()) {
            // Las cuentas de aprobador se manejan desde Aprobadores: nombre y correo vienen del aprobador.
            $data['user'] = array_intersect_key($data['user'], ['active' => 1]);
            $data['projects'] = $data['terms'] = null;
        }
        $user->update($data['user']);
        $this->saveRelations($user, $data);
        return response()->json($this->payload($user->fresh('terms')));
    }

    /** Reseteo hecho por el dueño: la persona recibe un enlace para crear una contraseña nueva. */
    public function resetPassword(Request $request, User $user)
    {
        $this->mine($request, $user);
        abort_if($user->id === $request->user()->id, 422, __('Para cambiar tu propia contraseña usa Cambiar contraseña.'));
        $this->accounts->reset($user->load('company'), $request->user());
        return response()->json(['ok' => true, 'emailed_to' => $user->email]);
    }

    public function resendInvitation(Request $request, User $user)
    {
        $this->mine($request, $user);
        abort_unless($user->must_set_password && $user->active, 422, __('Esta persona ya creó su contraseña.'));
        $this->accounts->invite($user->load('company'), $request->user());
        return response()->json(['ok' => true, 'emailed_to' => $user->email]);
    }

    private function mine(Request $request, User $user): void
    {
        abort_unless($user->company_id === $request->user()->company_id, 404);
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless($user->company_id === $request->user()->company_id, 404);
        abort_if($user->id === $request->user()->id, 422, __('No puedes desactivarte a ti mismo.'));
        $user->update(['active' => false]);
        $this->accounts->revokeAccess($user);
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
            // Único por compañía cuando no es nulo.
            'employee_number' => ['nullable', 'string', 'max:40', Rule::unique('users', 'employee_number')->where('company_id', $companyId)->ignore($user?->id)],
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
            'weekly_hours' => $u->weekly_hours, 'employee_number' => $u->employee_number, 'approver_id' => $u->approver_id, 'active' => $u->active,
            'project_ids' => $u->projects()->pluck('projects.id'),
            'terms' => $u->terms, 'invitation_status' => $u->invitationStatus(),
        ];
    }
}
