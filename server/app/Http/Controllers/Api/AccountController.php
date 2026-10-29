<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approver;
use App\Models\User;
use App\Services\AccountService;
use App\Support\Text;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Vista unificada de cuentas (trabajadores, aprobadores y back office) e invitación de cuentas nuevas. Solo el dueño. */
class AccountController extends Controller
{
    public function __construct(private AccountService $accounts, private SimpleCrudController $approvers)
    {
    }

    public function index(Request $request)
    {
        $f = $request->validate([
            'q' => 'nullable|string|max:100',
            'role' => 'nullable|in:worker,approver,backoffice,office',
            'worker_type' => 'nullable|in:employee,consultant',
        ]);
        $cid = $request->user()->company_id;

        // Quien es aprobador interno de algún servicio activo que pide aprobación interna.
        $internal = app(\App\Services\InternalApprovalService::class)->internalUserIds($cid);
        $rows = User::where('company_id', $cid)->get()->map(fn (User $u) => $this->fromUser($u, in_array($u->id, $internal, true)));
        // Aprobadores sin cuenta de entrada: también son una "cuenta" (sin acceso).
        $rows = $rows->concat(
            Approver::with('account')->where('company_id', $cid)->get()->filter(fn ($a) => ! $a->account)->map(fn ($a) => $this->fromApprover($a))
        );

        $rows = $rows
            ->when(! empty($f['role']), fn ($c) => $c->where('role', $f['role']))
            ->when(! empty($f['worker_type']), fn ($c) => $c->where('worker_type', $f['worker_type']))
            ->filter(fn ($r) => Text::matches($f['q'] ?? null, [$r['name'], $r['email']]))
            ->sortBy(fn ($r) => Text::fold($r['name']))->values();

        return response()->json($rows);
    }

    public function invite(Request $request)
    {
        $actor = $request->user();
        $cid = $actor->company_id;
        $v = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:190'],
            'role' => 'required|in:worker,approver,backoffice,office',
            'worker_type' => ['nullable', 'required_if:role,worker', 'in:employee,consultant'],
            'employee_number' => ['nullable', 'string', 'max:40', Rule::unique('users', 'employee_number')->where('company_id', $cid)],
        ]);
        if (User::where('email', $v['email'])->exists()) {
            throw ValidationException::withMessages(['email' => __('Ya existe una cuenta con ese correo.')]);
        }

        if ($v['role'] === 'approver') {
            $existing = Approver::where('company_id', $cid)->where('email', $v['email'])->first();
            [$approver] = $this->approvers->persistApprover($actor, $existing, $existing
                ? ['name' => $existing->name, 'email' => $existing->email, 'active' => true, 'can_login' => true]
                : ['name' => $v['name'], 'email' => $v['email'], 'active' => true, 'can_login' => true]);
            $user = $approver->account()->firstOrFail();
            if (! empty($v['employee_number'])) {
                $user->update(['employee_number' => $v['employee_number']]);
            }
        } else {
            $worker = $v['role'] === 'worker';
            $user = User::create([
                'company_id' => $cid,
                'name' => $v['name'],
                'email' => $v['email'],
                'role' => $worker ? 'member' : ($v['role'] === 'office' ? 'office' : 'owner'),
                'worker_type' => $worker ? $v['worker_type'] : ($v['worker_type'] ?? 'employee'),
                'capture_mode' => $worker && $v['worker_type'] === 'employee' ? 'full_day' : 'from_to',
                'weekly_hours' => $worker && $v['worker_type'] === 'employee' ? 40 : null,
                'employee_number' => $v['employee_number'] ?? null,
                'password' => Str::random(64),
                'must_set_password' => true,
                'active' => true,
            ]);
            $this->accounts->invite($user->load('company'), $actor);
        }

        return response()->json(['account' => $this->fromUser($user->fresh()), 'emailed_to' => $user->email], 201);
    }

    /**
     * role: lo que muestra la tabla de Personas. Un trabajador que es aprobador interno aparece como 'approver'
     * (user_role conserva lo que es de verdad: worker | backoffice; null en cuentas de aprobador externo).
     * approver_kind: external | internal | null.
     */
    private function fromUser(User $u, bool $internal = false): array
    {
        $real = match ($u->role) {
            'owner' => 'backoffice',
            'office' => 'office',
            'approver' => 'approver',
            default => 'worker',
        };
        $role = $real === 'worker' && $internal ? 'approver' : $real;
        $kind = $real === 'approver' ? 'external' : ($internal ? 'internal' : null);
        return [
            'id' => 'u'.$u->id,
            'user_id' => $u->id,
            'approver_id' => $u->approver_profile_id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $role,
            'user_role' => $real === 'approver' ? null : $real,
            'approver_kind' => $kind,
            'worker_type' => $real === 'worker' ? $u->worker_type : null,
            'employee_number' => $u->employee_number,
            'two_step' => $real === 'approver',
            'status' => ! $u->active ? 'disabled' : ($u->must_set_password ? 'invited' : 'active'),
            'can_login' => (bool) $u->active,
        ];
    }

    private function fromApprover(Approver $a): array
    {
        return [
            'id' => 'a'.$a->id,
            'user_id' => null,
            'approver_id' => $a->id,
            'name' => $a->name,
            'email' => $a->email,
            'role' => 'approver',
            'user_role' => null,
            'approver_kind' => 'external',
            'worker_type' => null,
            'employee_number' => null,
            'two_step' => false,
            'status' => $a->active ? 'active' : 'disabled',
            'can_login' => false,
        ];
    }
}
