<?php

namespace App\Services;

use App\Models\Approver;
use App\Models\Project;
use App\Models\Service;
use App\Models\Timesheet;
use App\Models\TimesheetExternalApproval;
use App\Models\User;
use App\Support\Parts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * APROBACIÓN EXTERNA POR PARTES. La etapa 'external' de una hoja se reparte en una PARTE por servicio (los servicios
 * external/both y, por compatibilidad, las entradas sin servicio). Cada parte la pueden aprobar los aprobadores externos
 * de ese servicio (basta uno) y cada aprobador solo ve y decide sobre las partes que le tocan. La hoja queda aprobada
 * cuando todas sus partes externas lo están. Cualquier devolución devuelve la hoja completa (R3).
 *
 * QUIÉNES aprueban una parte (resolución en tiempo de ejecución, resolve()):
 *   (a) la lista de aprobadores externos del servicio (services.externalApprovers);
 *   (b) si está vacía, todos los aprobadores ACTIVOS ligados al cliente del proyecto (approver_customer);
 *   (c) RESPALDO para datos viejos (cliente sin aprobadores o proyecto sin cliente): el aprobador del proyecto
 *       (projects.approver_id) o, si no, el de la persona (users.approver_id). Esos dos campos se conservan SOLO para esto.
 * effective() devuelve solo (a)/(b): es lo que muestra el servicio como "quiénes recibirían".
 */
class ExternalApprovalService
{
    public const ANY_ONE_APPROVER_SUFFICES = true;

    private function activeOf(Collection $approvers, int $companyId): Collection
    {
        return $approvers->filter(fn (Approver $a) => $a->active && (int) $a->company_id === $companyId)->values();
    }

    /** (a) o (b): los escogidos en el servicio o, si no hay, todos los activos del cliente del proyecto. */
    public function effective(?Service $service, ?Project $project): Collection
    {
        $companyId = (int) ($project?->company_id ?? $service?->company_id);
        if ($service) {
            $chosen = $this->activeOf($service->externalApprovers, $companyId);
            if ($chosen->isNotEmpty()) {
                return $chosen;
            }
        }
        return $project?->customer ? $this->activeOf($project->customer->approvers, $companyId) : collect();
    }

    /** (a), (b) y, si no hay nadie, el respaldo (c). */
    public function resolve(?Service $service, Project $project, User $worker): Collection
    {
        $found = $this->effective($service, $project);
        if ($found->isNotEmpty()) {
            return $found;
        }
        $id = $project->approver_id ?: $worker->approver_id;
        $fallback = $id ? Approver::where('company_id', $project->company_id)->where('active', true)->find($id) : null;
        return $fallback ? collect([$fallback]) : collect();
    }

    // ---------- partes ----------

    /**
     * Una parte por servicio que pide aprobación externa. key = id del servicio (0 = entradas sin servicio).
     * @return Collection<int, array{key:int, service:?Service, project:Project, eligible:Collection, entries:Collection, row:?TimesheetExternalApproval, status:string, approved_by:?Approver}>
     */
    public function parts(Timesheet $sheet): Collection
    {
        $sheet->loadMissing('user');
        $entries = $sheet->entries()->with('project.customer.approvers', 'project.services', 'service.externalApprovers', 'task:id,name')->get()
            ->filter(fn ($e) => $e->project && (! $e->service || $e->service->needsExternal()));
        if ($entries->isEmpty()) {
            return collect();
        }
        $rows = $sheet->externalApprovals()->with('approvedBy')->get()->keyBy(fn ($r) => (int) $r->service_id);

        return $entries->groupBy(fn ($e) => (int) $e->service_id)->map(function (Collection $group, $key) use ($sheet, $rows) {
            $first = $group->first();
            $row = $rows->get((int) $key);
            return [
                'key' => (int) $key,
                'service' => $first->service,
                'project' => $first->project,
                'eligible' => $this->resolve($first->service, $first->project, $sheet->user),
                'entries' => $group->values(),
                'row' => $row,
                'status' => $row ? ($row->approved_by_approver_id ? 'approved' : 'skipped') : 'pending',
                'approved_by' => $row?->approvedBy,
            ];
        })->sortBy(fn ($p) => $p['project']->code.'|'.str_pad((string) ($p['service']?->sort ?? 0), 6, '0', STR_PAD_LEFT).'|'.str_pad((string) $p['key'], 10, '0', STR_PAD_LEFT))->values();
    }

    public function pendingParts(Timesheet $sheet): Collection
    {
        return $this->parts($sheet)->where('status', 'pending')->values();
    }

    /** Partes de la hoja que este aprobador puede decidir (pendientes o no). */
    public function partsOf(Timesheet $sheet, Approver $approver): Collection
    {
        return $this->parts($sheet)->filter(fn ($p) => $p['eligible']->contains('id', $approver->id))->values();
    }

    public function pendingPartsOf(Timesheet $sheet, Approver $approver): Collection
    {
        return $this->partsOf($sheet, $approver)->where('status', 'pending')->values();
    }

    /** Aprobadores a quienes todavía les falta una parte (basta uno por parte). */
    public function waitingFor(Timesheet $sheet): Collection
    {
        return $this->pendingParts($sheet)->flatMap(fn ($p) => $p['eligible'])->unique('id')->values();
    }

    /** Líneas de la hoja que le tocan a este aprobador (solo las pendientes si $pendingOnly). */
    public function entriesOf(Timesheet $sheet, Approver $approver, bool $pendingOnly = false): Collection
    {
        $parts = $pendingOnly ? $this->pendingPartsOf($sheet, $approver) : $this->partsOf($sheet, $approver);
        return $parts->flatMap(fn ($p) => $p['entries'])->sortBy(fn ($e) => $e->work_date->toDateString().'|'.$e->start_time.'|'.str_pad((string) $e->id, 10, '0', STR_PAD_LEFT))->values();
    }

    /** Ids de servicio (0 = sin servicio) de las partes de este aprobador. */
    public function serviceKeysOf(Timesheet $sheet, Approver $approver): array
    {
        return $this->partsOf($sheet, $approver)->pluck('key')->all();
    }

    // ---------- ciclo ----------

    /** Una persona que no es del back office no puede enviar una hoja con una parte externa sin nadie que la apruebe. */
    public function assertResolvable(Timesheet $sheet): void
    {
        $sheet->loadMissing('user');
        if ($sheet->user->isOwner()) {
            return;
        }
        if ($this->parts($sheet)->contains(fn ($p) => $p['eligible']->isEmpty())) {
            throw ValidationException::withMessages(['approver' => __('No hay un aprobador asignado a esta hoja.')]);
        }
    }

    /** Primer aprobador de la hoja: se guarda en timesheets.approver_id solo como referencia (las listas usan las partes). */
    public function hintApproverId(Timesheet $sheet): ?int
    {
        return $this->parts($sheet)->flatMap(fn ($p) => $p['eligible'])->first()?->id;
    }

    /**
     * Empieza (o reinicia) la etapa externa: borra las aprobaciones previas (R3 al reenviar, R5 al cambiar). Las partes sin
     * aprobadores de quien es del back office quedan aprobadas por él mismo (null).
     * @return array{pending:int, total:int, cleared:int}
     */
    public function restart(Timesheet $sheet): array
    {
        $sheet->loadMissing('user');
        $cleared = $sheet->externalApprovals()->whereNotNull('approved_by_approver_id')->count();
        $sheet->externalApprovals()->delete();
        $parts = $this->parts($sheet);
        if ($sheet->user->isOwner()) {
            foreach ($parts->filter(fn ($p) => $p['eligible']->isEmpty()) as $p) {
                TimesheetExternalApproval::create(['timesheet_id' => $sheet->id, 'service_id' => $p['key'] ?: null,
                    'version' => $sheet->version, 'approved_by_approver_id' => null, 'approved_at' => now()]);
            }
            $parts = $this->parts($sheet);
        }
        return ['pending' => $parts->where('status', 'pending')->count(), 'total' => $parts->count(), 'cleared' => $cleared];
    }

    /** ¿Todas las partes externas son del dueño que se aprueba solo (nadie externo que las apruebe)? */
    public function allSelfApproved(Timesheet $sheet): bool
    {
        $parts = $this->parts($sheet);
        return $parts->isNotEmpty() && $parts->every(fn ($p) => $p['status'] === 'skipped');
    }

    /** Registra la aprobación de este aprobador en todas sus partes pendientes. Devuelve esas partes. */
    public function recordApproval(Timesheet $sheet, Approver $approver): Collection
    {
        $mine = $this->pendingPartsOf($sheet, $approver);
        foreach ($mine as $p) {
            TimesheetExternalApproval::create(['timesheet_id' => $sheet->id, 'service_id' => $p['key'] ?: null,
                'version' => $sheet->version, 'approved_by_approver_id' => $approver->id, 'approved_at' => now()]);
        }
        return $mine;
    }

    // ---------- lo que ve la API ----------

    /** Lista `external` de una hoja: partes con estado, quién aprobó y quiénes pueden aprobar. $onlyKeys recorta a ciertos servicios. */
    public function info(Timesheet $s, ?array $onlyKeys = null): array
    {
        if (! in_array($s->status, [Timesheet::SUBMITTED, Timesheet::APPROVED], true)) {
            return [];
        }
        $parts = $this->parts($s);
        if ($s->status === Timesheet::APPROVED) {
            $parts = $parts->where('status', '!=', 'pending');
        }
        if ($onlyKeys !== null) {
            $parts = $parts->filter(fn ($p) => in_array($p['key'], $onlyKeys, true));
        }
        return $parts->map(fn ($p) => [
            'service_id' => $p['service']?->id,
            'project_id' => $p['project']->id,
            'project' => $p['project']->code.' · '.$p['project']->name,
            'service' => Parts::serviceName($p['project'], $p['service']),
            'status' => $p['status'],
            'approved_by' => $p['approved_by'] ? ['id' => $p['approved_by']->id, 'name' => $p['approved_by']->name] : null,
            'approvers' => $p['eligible']->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()->all(),
        ])->values()->all();
    }

    // ---------- listas del aprobador ----------

    /** Hojas que podrían tocarle a este aprobador (se afina con involves()). */
    private function candidates(Approver $a): Builder
    {
        $customerIds = $a->customers()->pluck('customers.id')->all();
        $serviceIds = \Illuminate\Support\Facades\DB::table('service_external_approvers')->where('approver_id', $a->id)->pluck('service_id')->all();
        return Timesheet::query()->where('company_id', $a->company_id)
            ->whereIn('status', [Timesheet::SUBMITTED, Timesheet::APPROVED, Timesheet::RETURNED])->releasedToExternal()
            ->where(function ($q) use ($a, $customerIds, $serviceIds) {
                $q->where('approver_id', $a->id)->orWhere('decided_by_approver_id', $a->id)
                    ->orWhereHas('externalApprovals', fn ($x) => $x->where('approved_by_approver_id', $a->id))
                    ->orWhereHas('entries', fn ($e) => $e->whereIn('service_id', $serviceIds ?: [0]))
                    ->orWhereHas('entries', fn ($e) => $e->whereHas('project', fn ($p) => $p->whereIn('customer_id', $customerIds ?: [0])->orWhere('approver_id', $a->id)))
                    ->orWhereHas('user', fn ($u) => $u->where('approver_id', $a->id));
            });
    }

    /** ¿Esta hoja tiene (o tuvo) algo que ver con este aprobador? */
    public function involves(Timesheet $sheet, Approver $a): bool
    {
        return (int) $sheet->approver_id === $a->id || (int) $sheet->decided_by_approver_id === $a->id
            || $sheet->externalApprovals()->where('approved_by_approver_id', $a->id)->exists()
            || $this->partsOf($sheet, $a)->isNotEmpty();
    }

    /** Ids de las hojas (con status enviada/aprobada/devuelta, ya fuera de la etapa interna) donde este aprobador tiene partes. */
    public function sheetIdsFor(Approver $a): array
    {
        return $this->candidates($a)->get()->filter(fn (Timesheet $s) => $this->involves($s, $a))->pluck('id')->all();
    }

    /** Hojas enviadas con al menos una parte pendiente que este aprobador puede decidir. */
    public function pendingSheetsFor(Approver $a): Collection
    {
        return $this->candidates($a)->where('status', Timesheet::SUBMITTED)->with('user', 'company')
            ->orderByRaw('submitted_at is null')->orderBy('submitted_at')->orderBy('id')->get()
            ->filter(fn (Timesheet $s) => $this->pendingPartsOf($s, $a)->isNotEmpty())->values();
    }

    /** pending | approved | returned | null: dónde cae la hoja en la lista de este aprobador. */
    public function classify(Timesheet $s, Approver $a): ?string
    {
        return match ($s->status) {
            Timesheet::RETURNED => 'returned',
            Timesheet::APPROVED => 'approved',
            Timesheet::SUBMITTED => $this->pendingPartsOf($s, $a)->isNotEmpty() ? 'pending' : 'approved',
            default => null,
        };
    }

    /** Deja en la hoja (solo en memoria) las líneas y el total de las partes de este aprobador. */
    public function restrictTo(Timesheet $s, Approver $a): Timesheet
    {
        $keys = $this->serviceKeysOf($s, $a);
        if ($keys === [] && ((int) $s->approver_id === $a->id || (int) $s->decided_by_approver_id === $a->id) && ! $s->externalApprovals()->exists()) {
            // Hoja vieja (sin partes registradas) que este aprobador ya tenía o decidió: ve todas las líneas, como antes.
            $entries = $s->entries()->with('project:id,code,name', 'service:id,name', 'task:id,name')->get();
        } else {
            $entries = $s->entries()->with('project:id,code,name', 'service:id,name', 'task:id,name')
                ->where(fn ($x) => $x->whereIn('service_id', array_filter($keys))->when(in_array(0, $keys, true), fn ($y) => $y->orWhereNull('service_id')))->get();
        }
        $s->setRelation('entries', $entries);
        $s->total_hours = round((float) $entries->sum('hours'), 2);
        return $s;
    }

    // ---------- correos ----------

    /** $kind: new | resubmitted | changed. Un correo por aprobador con partes pendientes, solo con las horas que le tocan. */
    public function notify(Timesheet $sheet, string $kind): int
    {
        $approvers = $this->waitingFor($sheet);
        foreach ($approvers as $a) {
            app(ApprovalService::class)->sendSheetRequest($sheet, $kind, $a);
        }
        return $approvers->count();
    }
}
