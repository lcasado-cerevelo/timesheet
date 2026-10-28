<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\CommissionResult;
use App\Models\Project;
use App\Models\Service;
use App\Models\Task;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Comisiones por productividad (ver diseno.md, v1.11). El dueño configura la comisión de cada persona (una por proyecto) y libera,
 * ajusta o marca como pagados los resultados; cada persona ve lo suyo en /api/me/commissions. Todo lo que mueve dinero se firma con contraseña.
 */
class CommissionController extends Controller
{
    // ---------- configuración (dueño) ----------

    public function index(Request $request)
    {
        $q = Commission::with('user:id,name,worker_type', 'project:id,code,name', 'services:id', 'tasks:id')->withCount('results')
            ->where('company_id', $request->user()->company_id)
            ->when($request->query('user_id'), fn ($x, $id) => $x->where('user_id', (int) $id))
            ->orderBy('project_id')->orderBy('user_id');
        return response()->json($q->get()->map(fn ($c) => self::payload($c))->values());
    }

    public function store(Request $request)
    {
        $cid = $request->user()->company_id;
        $data = $this->validated($request, null);
        $user = User::where('company_id', $cid)->findOrFail($data['user_id']);
        if ($user->isApprover()) {
            throw ValidationException::withMessages(['user_id' => __('Una cuenta de aprobador externo no puede tener comisión.')]);
        }
        $project = Project::where('company_id', $cid)->findOrFail($data['project_id']);
        if (Commission::where('user_id', $user->id)->where('project_id', $project->id)->exists()) {
            throw ValidationException::withMessages(['project_id' => __('Esta persona ya tiene una comisión en este proyecto: edítala.')]);
        }
        [$serviceIds, $taskIds] = $this->checkScope($request, $project);
        $commission = DB::transaction(function () use ($data, $cid, $serviceIds, $taskIds) {
            $c = Commission::create(array_diff_key($data, array_flip(['service_ids', 'task_ids'])) + ['company_id' => $cid]);
            $c->services()->sync($serviceIds);
            $c->tasks()->sync($taskIds);
            return $c;
        });
        return response()->json(self::payload($commission->load('user:id,name,worker_type', 'project:id,code,name', 'services:id', 'tasks:id')->loadCount('results')), 201);
    }

    public function update(Request $request, Commission $commission)
    {
        abort_unless($commission->company_id === $request->user()->company_id, 404);
        $data = $this->validated($request, $commission);
        [$serviceIds, $taskIds] = $this->checkScope($request, $commission->project);
        DB::transaction(function () use ($commission, $data, $request, $serviceIds, $taskIds) {
            $commission->update(array_diff_key($data, array_flip(['service_ids', 'task_ids', 'user_id', 'project_id'])));
            if ($request->has('service_ids')) {
                $commission->services()->sync($serviceIds);
            }
            if ($request->has('task_ids')) {
                $commission->tasks()->sync($taskIds);
            }
        });
        return response()->json(self::payload($commission->refresh()->load('user:id,name,worker_type', 'project:id,code,name', 'services:id', 'tasks:id')->loadCount('results')));
    }

    /** Con resultados guardados no se borra (queda el historial): se desactiva. */
    public function destroy(Request $request, Commission $commission)
    {
        abort_unless($commission->company_id === $request->user()->company_id, 404);
        if ($commission->results()->exists()) {
            abort(422, __('Esta comisión ya tiene resultados: no se puede borrar. Desactívala.'));
        }
        $commission->delete();
        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?Commission $c): array
    {
        $cid = $request->user()->company_id;
        $req = $c ? ['sometimes', 'required'] : ['required'];
        $v = $request->validate([
            'user_id' => $c ? 'prohibited' : ['required', 'integer', Rule::exists('users', 'id')->where('company_id', $cid)],
            'project_id' => $c ? 'prohibited' : ['required', 'integer', Rule::exists('projects', 'id')->where('company_id', $cid)],
            'service_ids' => 'nullable|array', 'service_ids.*' => 'integer',
            'task_ids' => 'nullable|array', 'task_ids.*' => 'integer',
            'percent_required' => [...$req, 'numeric', 'min:0', 'max:100'],
            'extra_per_hour' => [...$req, 'numeric', 'min:0', 'max:99999'],
            'commission_basis' => ['sometimes', Rule::in(Commission::COMMISSION_BASES)],
            'savings_mode' => [...$req, Rule::in(Commission::SAVINGS_MODES)],
            'savings_pct' => [...$req, 'numeric', 'min:0', 'max:100'],
            'savings_per_hour' => [...$req, 'numeric', 'min:0', 'max:99999'],
            'tolerance_hours' => [...$req, 'numeric', 'min:0', 'max:999'],
            'withhold' => 'boolean', 'ivu' => 'boolean', 'active' => 'boolean',
        ]);
        return $v;
    }

    /** Los servicios y las tareas escogidos deben ser del proyecto, y las tareas deben ser de las que "aplican comisión". */
    private function checkScope(Request $request, Project $project): array
    {
        $serviceIds = array_values(array_unique(array_map('intval', (array) $request->input('service_ids', []))));
        $taskIds = array_values(array_unique(array_map('intval', (array) $request->input('task_ids', []))));
        if ($serviceIds && Service::where('project_id', $project->id)->whereIn('id', $serviceIds)->count() !== count($serviceIds)) {
            throw ValidationException::withMessages(['service_ids' => __('Algún servicio no es de este proyecto.')]);
        }
        if ($taskIds && Task::where('project_id', $project->id)->where('commissionable', true)->whereIn('id', $taskIds)->count() !== count($taskIds)) {
            throw ValidationException::withMessages(['task_ids' => __('Algunas tareas no son de este proyecto o no están marcadas como "aplica comisión".')]);
        }
        return [$serviceIds, $taskIds];
    }

    public static function payload(Commission $c): array
    {
        return [
            'id' => $c->id, 'user' => ['id' => $c->user->id, 'name' => $c->user->name, 'worker_type' => $c->user->worker_type],
            'project' => ['id' => $c->project->id, 'code' => $c->project->code, 'name' => $c->project->name],
            'service_ids' => $c->services->pluck('id')->map(fn ($i) => (int) $i)->values()->all(),
            'task_ids' => $c->tasks->pluck('id')->map(fn ($i) => (int) $i)->values()->all(),
            'percent_required' => (float) $c->percent_required, 'extra_per_hour' => (float) $c->extra_per_hour, 'commission_basis' => $c->commission_basis,
            'savings_mode' => $c->savings_mode, 'savings_pct' => (float) $c->savings_pct, 'savings_per_hour' => (float) $c->savings_per_hour,
            'tolerance_hours' => (float) $c->tolerance_hours, 'withhold' => (bool) $c->withhold, 'ivu' => (bool) $c->ivu, 'active' => (bool) $c->active,
            'results_count' => (int) ($c->results_count ?? 0),
        ];
    }

    // ---------- resultados (dueño): liberar, ajustar, pagar ----------

    public function results(Request $request)
    {
        $status = $request->query('status');
        $rows = CommissionResult::with('user:id,name,worker_type', 'project:id,code,name', 'task:id,name')->where('company_id', $request->user()->company_id)
            ->when($status === 'open', fn ($x) => $x->whereIn('status', ['pending', 'released']))
            ->when($status && $status !== 'open' && $status !== 'all', fn ($x) => $x->where('status', $status))
            ->when(! $status, fn ($x) => $x->where('status', '!=', 'none'))
            ->when($request->query('user_id'), fn ($x, $id) => $x->where('user_id', (int) $id))
            ->when($request->query('project_id'), fn ($x, $id) => $x->where('project_id', (int) $id))
            ->orderByDesc('completed_on')->orderBy('id')->get();
        return response()->json($rows->map(fn ($r) => self::resultPayload($r))->values());
    }

    public function release(Request $request, CommissionResult $result)
    {
        $this->own($request, $result);
        if ($result->status !== 'pending') {
            abort(422, __('Esta comisión no está pendiente de liberar.'));
        }
        $user = $request->user();
        DB::transaction(function () use ($request, $user, $result) {
            app(SignatureService::class)->sign($request, $user, 'commission.release', $result, ['total' => (float) $result->total, 'user_id' => $result->user_id]);
            $result->update(['status' => 'released', 'released_at' => now(), 'released_by' => $user->id]);
        });
        return response()->json(self::resultPayload($result->refresh()->load('user:id,name,worker_type', 'project:id,code,name', 'task:id,name')));
    }

    /** Ajuste manual del dueño: se guarda aparte del cálculo, con el motivo, y se firma. No se ajusta una comisión ya facturada o pagada. */
    public function adjust(Request $request, CommissionResult $result)
    {
        $this->own($request, $result);
        if (! $result->isOpen()) {
            abort(422, __('Esta comisión ya se facturó o se pagó: no se puede ajustar.'));
        }
        $data = $request->validate(['adjustment' => 'required|numeric|min:-99999|max:99999', 'adjustment_note' => 'nullable|string|max:500']);
        $adj = round((float) $data['adjustment'], 2);
        $note = trim((string) ($data['adjustment_note'] ?? ''));
        if ($adj != 0.0 && mb_strlen($note) < 3) {
            throw ValidationException::withMessages(['adjustment_note' => __('Escribe el motivo del ajuste.')]);
        }
        $total = round((float) $result->commission_amount + (float) $result->savings_amount + $adj, 2);
        if ($total < 0) {
            throw ValidationException::withMessages(['adjustment' => __('El ajuste no puede dejar la comisión en negativo.')]);
        }
        $user = $request->user();
        DB::transaction(function () use ($request, $user, $result, $adj, $note, $total) {
            app(SignatureService::class)->sign($request, $user, 'commission.adjust', $result, ['adjustment' => $adj, 'note' => $note, 'total' => $total]);
            $fields = ['adjustment' => $adj, 'adjustment_note' => $adj == 0.0 ? null : $note, 'total' => $total];
            if ($total <= 0) {                                   // sin nada que pagar: vuelve a "ninguna"
                $fields += ['status' => 'none', 'released_at' => null, 'released_by' => null];
            } elseif ($result->status === 'none') {              // el dueño concede una comisión que no se ganó: queda por liberar
                $fields += ['status' => 'pending'];
            }
            $result->update($fields);
        });
        return response()->json(self::resultPayload($result->refresh()->load('user:id,name,worker_type', 'project:id,code,name', 'task:id,name')));
    }

    /** Un empleado no factura: la oficina marca la comisión liberada como pagada. */
    public function paid(Request $request, CommissionResult $result)
    {
        $this->own($request, $result);
        $result->loadMissing('user');
        if ($result->status !== 'released') {
            abort(422, __('Solo se marca como pagada una comisión liberada.'));
        }
        if ($result->user->worker_type !== 'employee') {
            abort(422, __('Los consultores cobran la comisión en su factura; solo se marca como pagada la de un empleado.'));
        }
        $user = $request->user();
        DB::transaction(function () use ($request, $user, $result) {
            app(SignatureService::class)->sign($request, $user, 'commission.paid', $result, ['total' => (float) $result->total, 'user_id' => $result->user_id]);
            $result->update(['status' => 'paid', 'paid_at' => now()]);
        });
        return response()->json(self::resultPayload($result->refresh()->load('user:id,name,worker_type', 'project:id,code,name', 'task:id,name')));
    }

    private function own(Request $request, CommissionResult $result): void
    {
        abort_unless($result->company_id === $request->user()->company_id, 404);
    }

    public static function resultPayload(CommissionResult $r): array
    {
        return CommissionService::rowPayload($r->toArray() + ['user_name' => $r->user?->name]) + [
            'id' => $r->id, 'status' => $r->status, 'worker_type' => $r->user?->worker_type,
            'project' => ['id' => $r->project_id, 'code' => $r->project?->code, 'name' => $r->project?->name],
            'task' => ['id' => $r->task_id, 'name' => $r->task?->name],
            'released_at' => $r->released_at?->toIso8601String(), 'paid_at' => $r->paid_at?->toIso8601String(),
        ];
    }

    // ---------- lo que ve cada persona ----------

    public function mine(Request $request)
    {
        $user = $request->user();
        abort_if($user->isApprover(), 403);
        return response()->json(app(CommissionService::class)->forWorker($user));
    }
}
