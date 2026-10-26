<?php

namespace App\Services;

use App\Mail\BrandedMail;
use App\Models\Project;
use App\Models\Service;
use App\Models\Timesheet;
use App\Models\TimesheetInternalApproval;
use App\Models\User;
use App\Support\Lang;
use App\Support\Parts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * APROBACIÓN INTERNA. Un servicio puede exigir que un supervisor (un usuario con cuenta, "aprobador interno") apruebe las
 * horas antes de que la hoja pase a los aprobadores externos (los de siempre: enlace/código o su cuenta).
 *
 * Etapas de una hoja enviada (timesheets.stage): 'internal' -> 'external' -> aprobada. Sin partes internas va directo a 'external'.
 * Las partes externas (una por servicio) viven en ExternalApprovalService; este servicio arma también el resumen de la API (info()).
 *
 * TODAS las reglas que Luis aún no confirmó están en este archivo, para cambiarlas rápido:
 *   R1  ANY_ONE_APPROVER_SUFFICES  basta un interno por parte (false: hacen falta todos los internos del servicio).
 *   R2  método parts()             una "parte" por SERVICIO que exige aprobación interna; la hoja pasa a la etapa externa con todas aprobadas.
 *   R3  SheetService::internalReturn()  la devolución interna devuelve la hoja completa; submit() borra las aprobaciones
 *                                  anteriores al reenviar.
 *   R4  SKIP_PART_WHEN_NO_ELIGIBLE  quien envía nunca aprueba lo suyo (eligibleFor() la excluye); si no queda nadie
 *                                  elegible, la parte se omite y queda registrada (true) o bloquea hasta que el dueño decida (false).
 *   R5  INVALIDATE_ON_CHANGE       un cambio en la hoja durante la etapa interna borra las aprobaciones ya dadas y se vuelve a avisar.
 *   R6  OWNER_CAN_OVERRIDE         el dueño (back office) aprueba o devuelve en cualquier etapa; queda como evento "override".
 *
 * QUIÉNES son los internos de una parte: la lista del servicio (service_internal_approvers). RESPALDO para servicios viejos con la
 * lista vacía: la lista vieja del proyecto (project_internal_approvers, que se conserva solo para esto).
 */
class InternalApprovalService
{
    public const ANY_ONE_APPROVER_SUFFICES = true;      // R1
    public const SKIP_PART_WHEN_NO_ELIGIBLE = true;     // R4
    public const INVALIDATE_ON_CHANGE = true;           // R5
    public const OWNER_CAN_OVERRIDE = true;             // R6

    // ---------- quién es interno ----------

    /**
     * Servicios (ids) con aprobación interna que esta persona supervisa: los de cuya lista forma parte o, en servicios viejos
     * con la lista vacía, los de proyectos cuya lista (vieja) la incluye. $activeOnly: solo servicios y proyectos activos.
     */
    public function supervisedServiceIds(User $user, bool $activeOnly = false): array
    {
        $q = Service::query()->where('company_id', $user->company_id)->whereIn('approval_mode', ['internal', 'both'])
            ->when($activeOnly, fn ($x) => $x->where('active', true)->whereHas('project', fn ($p) => $p->where('active', true)));
        $own = (clone $q)->whereHas('internalApprovers', fn ($u) => $u->where('users.id', $user->id))->pluck('id');
        $legacy = (clone $q)->whereDoesntHave('internalApprovers')
            ->whereHas('project.internalApprovers', fn ($u) => $u->where('users.id', $user->id))->pluck('id');
        return $own->merge($legacy)->unique()->map(fn ($i) => (int) $i)->values()->all();
    }

    /** Usuarios (ids) de la compañía que son aprobadores internos de algún servicio activo con aprobación interna. */
    public function internalUserIds(int $companyId): array
    {
        $own = DB::table('service_internal_approvers as sia')->join('services', 'services.id', '=', 'sia.service_id')
            ->join('projects', 'projects.id', '=', 'services.project_id')
            ->where('services.company_id', $companyId)->where('services.active', true)->whereIn('services.approval_mode', ['internal', 'both'])
            ->where('projects.active', true)->pluck('sia.user_id');
        $legacy = DB::table('project_internal_approvers as pia')->join('projects', 'projects.id', '=', 'pia.project_id')
            ->where('projects.company_id', $companyId)->where('projects.active', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('services')->whereColumn('services.project_id', 'projects.id')
                ->where('services.active', true)->whereIn('services.approval_mode', ['internal', 'both'])
                ->whereNotExists(fn ($n) => $n->selectRaw('1')->from('service_internal_approvers')->whereColumn('service_internal_approvers.service_id', 'services.id')))
            ->pluck('pia.user_id');
        return $own->merge($legacy)->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    /** Quién puede aprobar en este servicio: internos activos de la compañía, sin contar a quien envía la hoja (R4). */
    public function eligibleFor(Service $service, Timesheet $sheet): Collection
    {
        $list = $service->internalApprovers;
        if ($list->isEmpty()) {
            $list = $service->project->internalApprovers;       // respaldo: lista vieja del proyecto
        }
        return $list->filter(fn (User $u) => $u->active
            && $u->company_id === $sheet->company_id && $u->id !== $sheet->user_id)->values();
    }

    // ---------- partes de una hoja (R2) ----------

    /**
     * Una parte por servicio de la hoja que exige aprobación interna.
     * @return Collection<int, array{service:Service, project:Project, eligible:Collection, rows:Collection, status:string, approved_by:?User}>
     */
    public function parts(Timesheet $sheet): Collection
    {
        // Cada entrada decide por su servicio: solo los servicios internal/both piden aprobación interna.
        $services = $sheet->entries()->with('service.internalApprovers', 'service.project.internalApprovers', 'service.project.services')->get()
            ->filter(fn ($e) => $e->service?->needsInternal())->pluck('service')->unique('id');
        if ($services->isEmpty()) {
            return collect();
        }
        $rows = $sheet->internalApprovals()->with('approvedBy')->get();

        return $services->sortBy(fn (Service $s) => $s->project->code.'|'.str_pad((string) $s->sort, 6, '0', STR_PAD_LEFT).'|'.str_pad((string) $s->id, 10, '0', STR_PAD_LEFT))
            ->map(function (Service $svc) use ($sheet, $rows) {
                $eligible = $this->eligibleFor($svc, $sheet);
                // Las filas viejas eran por proyecto (service_id nulo): cuentan para todos los servicios de ese proyecto.
                $done = $rows->where('skipped', false)->filter(fn ($r) => $r->service_id
                    ? (int) $r->service_id === $svc->id : (int) $r->project_id === $svc->project_id)->values();
                if ($eligible->isEmpty() && self::SKIP_PART_WHEN_NO_ELIGIBLE) {
                    $status = 'skipped';
                } elseif (self::ANY_ONE_APPROVER_SUFFICES) {
                    $status = $done->isNotEmpty() ? 'approved' : 'pending';
                } else {
                    $status = $eligible->isNotEmpty() && $eligible->every(fn ($u) => $done->contains('approved_by_user_id', $u->id)) ? 'approved' : 'pending';
                }
                return ['service' => $svc, 'project' => $svc->project, 'eligible' => $eligible, 'rows' => $done, 'status' => $status, 'approved_by' => $done->first()?->approvedBy];
            })->values();
    }

    /** ¿Alguna entrada de la hoja está en un servicio que pide aprobación externa (external o both)? Sin servicio: sí (como antes). */
    public function requiresExternal(Timesheet $sheet): bool
    {
        return $sheet->entries()->with('service')->get()->contains(fn ($e) => $e->service ? $e->service->needsExternal() : true);
    }

    /** Etapas que esta hoja requiere según los servicios de sus entradas: subconjunto de ['internal','external']. */
    public function requiredStages(Timesheet $sheet): array
    {
        $out = [];
        if ($this->parts($sheet)->isNotEmpty()) {
            $out[] = Timesheet::STAGE_INTERNAL;
        }
        if ($this->requiresExternal($sheet)) {
            $out[] = Timesheet::STAGE_EXTERNAL;
        }
        return $out;
    }

    public function pendingParts(Timesheet $sheet): Collection
    {
        return $this->parts($sheet)->where('status', 'pending')->values();
    }

    /** Internos a quienes todavía les falta aprobar esa parte. */
    private function waitingOnPart(array $part): Collection
    {
        if ($part['status'] !== 'pending') {
            return collect();
        }
        return self::ANY_ONE_APPROVER_SUFFICES
            ? $part['eligible']
            : $part['eligible']->reject(fn ($u) => $part['rows']->contains('approved_by_user_id', $u->id))->values();
    }

    /** Partes pendientes que esta persona puede aprobar. */
    public function pendingPartsOf(Timesheet $sheet, User $user): Collection
    {
        return $this->parts($sheet)->filter(fn ($p) => $this->waitingOnPart($p)->contains('id', $user->id))->values();
    }

    public function waitingFor(Timesheet $sheet): Collection
    {
        return $this->parts($sheet)->flatMap(fn ($p) => $this->waitingOnPart($p))->unique('id')->values();
    }

    // ---------- ciclo ----------

    /**
     * Empieza (o reinicia) la etapa interna: borra las aprobaciones previas (R3 al reenviar, R5 al cambiar) y registra las
     * partes omitidas. Devuelve cuántas partes siguen pendientes y las partes omitidas.
     * @return array{pending:int, skipped:Collection, cleared:int}
     */
    public function restart(Timesheet $sheet, bool $clearApprovals = true): array
    {
        $q = $sheet->internalApprovals();
        $cleared = $clearApprovals ? (clone $q)->where('skipped', false)->count() : 0;
        $clearApprovals ? $q->delete() : $q->where('skipped', true)->delete();

        $parts = $this->parts($sheet);
        $skipped = $parts->where('status', 'skipped')->values();
        foreach ($skipped as $p) {
            TimesheetInternalApproval::create(['timesheet_id' => $sheet->id, 'project_id' => $p['project']->id, 'service_id' => $p['service']->id,
                'version' => $sheet->version, 'approved_by_user_id' => null, 'skipped' => true, 'approved_at' => now()]);
        }
        return ['pending' => $parts->where('status', 'pending')->count(), 'skipped' => $skipped, 'cleared' => $cleared];
    }

    /** Registra la aprobación de esta persona en todas sus partes pendientes. Devuelve esas partes. */
    public function recordApproval(Timesheet $sheet, User $user): Collection
    {
        $mine = $this->pendingPartsOf($sheet, $user);
        foreach ($mine as $p) {
            TimesheetInternalApproval::create(['timesheet_id' => $sheet->id, 'project_id' => $p['project']->id, 'service_id' => $p['service']->id,
                'version' => $sheet->version, 'approved_by_user_id' => $user->id, 'skipped' => false, 'approved_at' => now()]);
        }
        return $mine;
    }

    // ---------- lo que ve la API ----------

    private function internalItem(array $p): array
    {
        return [
            'service_id' => $p['service']->id,
            'project_id' => $p['project']->id,
            'project' => $p['project']->code.' · '.$p['project']->name,
            'service' => Parts::serviceName($p['project'], $p['service']),
            'status' => $p['status'],
            'approved_by' => $p['approved_by'] ? ['id' => $p['approved_by']->id, 'name' => $p['approved_by']->name] : null,
            'approvers' => $p['eligible']->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
        ];
    }

    /**
     * stage, waiting_for, internal, external y required_stages de una hoja.
     * $onlyServiceKeys recorta `internal` y `external` a ciertos servicios (0 = sin servicio): lo que ve un supervisor o un aprobador.
     * $forApprover: un aprobador externo solo ve en waiting_for si le toca a él.
     */
    public function info(Timesheet $s, ?array $onlyServiceKeys = null, ?\App\Models\Approver $forApprover = null): array
    {
        $external = app(ExternalApprovalService::class);
        $stage = $s->status === Timesheet::SUBMITTED ? ($s->stage ?: Timesheet::STAGE_EXTERNAL) : null;
        $waiting = [];
        $internal = [];

        if ($stage === Timesheet::STAGE_INTERNAL) {
            $parts = $this->parts($s);
            $waiting = $parts->flatMap(fn ($p) => $this->waitingOnPart($p))->unique('id')
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'kind' => 'internal'])->values()->all();
            $internal = $parts->map(fn ($p) => $this->internalItem($p))->all();
        } else {
            if ($stage === Timesheet::STAGE_EXTERNAL) {
                $waiting = $external->waitingFor($s)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'kind' => 'external'])->values()->all();
            }
            if (in_array($s->status, [Timesheet::SUBMITTED, Timesheet::APPROVED], true)) {
                $internal = $s->internalApprovals()->with('approvedBy', 'service.internalApprovers', 'service.project.internalApprovers', 'service.project.services', 'project.services')->get()
                    ->groupBy(fn ($r) => $r->service_id ? 's'.$r->service_id : 'p'.$r->project_id)
                    ->map(function ($rows) use ($s) {
                        $svc = $rows->first()->service;
                        $p = $svc?->project ?? $rows->first()->project;
                        $done = $rows->where('skipped', false)->first();
                        return [
                            'service_id' => $svc?->id,
                            'project_id' => $p->id,
                            'project' => $p->code.' · '.$p->name,
                            'service' => Parts::serviceName($p, $svc),
                            'status' => $done ? 'approved' : 'skipped',
                            'approved_by' => $done?->approvedBy ? ['id' => $done->approvedBy->id, 'name' => $done->approvedBy->name] : null,
                            'approvers' => ($svc ? $this->eligibleFor($svc, $s) : $p->internalApprovers->filter(fn ($u) => $u->active && $u->id !== $s->user_id))
                                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
                        ];
                    })->sortBy('project')->values()->all();
            }
        }
        $ext = $external->info($s, $onlyServiceKeys);
        if ($onlyServiceKeys !== null) {
            $internal = array_values(array_filter($internal, fn ($i) => in_array((int) $i['service_id'], $onlyServiceKeys, true)));
        }
        if ($forApprover) {
            $waiting = $stage === Timesheet::STAGE_EXTERNAL && $external->pendingPartsOf($s, $forApprover)->isNotEmpty()
                ? [['id' => $forApprover->id, 'name' => $forApprover->name, 'kind' => 'external']] : [];
        }
        return ['stage' => $stage, 'waiting_for' => $waiting, 'internal' => $internal, 'external' => $ext, 'required_stages' => $this->requiredStages($s)];
    }

    // ---------- listas del supervisor ----------

    /** Hojas donde esta persona supervisa al menos un servicio (y no son suyas). */
    public function scopeFor(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $ids = $this->supervisedServiceIds($user);
        return Timesheet::query()->where('company_id', $user->company_id)->where('user_id', '!=', $user->id)
            ->whereIn('status', [Timesheet::SUBMITTED, Timesheet::APPROVED, Timesheet::RETURNED])
            ->whereHas('entries', fn ($q) => $q->whereIn('service_id', $ids ?: [0]));
    }

    /** pending | approved | returned | null: dónde cae la hoja en la lista de este supervisor. */
    public function classify(Timesheet $s, User $user): ?string
    {
        if ($s->status === Timesheet::RETURNED && $s->decided_via === 'internal' && (int) $s->decided_by_user_id === $user->id) {
            return 'returned';
        }
        if ($s->status === Timesheet::SUBMITTED && $s->stage === Timesheet::STAGE_INTERNAL && $this->pendingPartsOf($s, $user)->isNotEmpty()) {
            return 'pending';
        }
        if ($s->internalApprovals()->where('approved_by_user_id', $user->id)->where('skipped', false)->exists()) {
            return 'approved';
        }
        return null;
    }

    /** Deja en la hoja (solo en memoria) las entradas de los servicios que esta persona supervisa. */
    public function restrictTo(Timesheet $s, User $user): Timesheet
    {
        $ids = $this->supervisedServiceIds($user);
        $s->setRelation('entries', $s->entries()->with('project:id,code,name', 'service:id,name')->whereIn('service_id', $ids ?: [0])->get());
        $s->total_hours = (float) $s->entries->sum('hours');
        return $s;
    }

    // ---------- correos ----------

    /** $kind: new | resubmitted | changed. Avisa a los internos con partes pendientes. */
    public function notify(Timesheet $sheet, string $kind): int
    {
        $sheet->loadMissing('company', 'user');
        $parts = $this->pendingParts($sheet);
        $users = $parts->flatMap(fn ($p) => $this->waitingOnPart($p))->unique('id')->values();
        foreach ($users as $u) {
            $mine = $parts->filter(fn ($p) => $this->waitingOnPart($p)->contains('id', $u->id));
            $this->mail($u, $sheet, $this->item($sheet, $mine), $kind);
        }
        return $users->count();
    }

    private function item(Timesheet $sheet, Collection $parts): array
    {
        $entries = $sheet->entries()->get();
        $projects = $parts->map(fn ($p) => [
            'project' => Parts::label($p['project'], $p['service']),
            'hours' => round((float) $entries->where('service_id', $p['service']->id)->sum('hours'), 2),
        ])->values()->all();
        return ['worker' => $sheet->user->name, 'week' => $sheet->weekLabel(),
            'total_hours' => round(array_sum(array_column($projects, 'hours')), 2), 'projects' => $projects,
            'late' => (bool) $sheet->late, 'changes' => Parts::changes($sheet->change_summary, $parts->map(fn ($p) => $p['service']->id)->all())];
    }

    /** Un correo con una o varias hojas, en el idioma del destinatario. */
    private function mail(User $u, Timesheet $sheet, array|null $item, string $kind, ?array $items = null): void
    {
        $company = $sheet->company;
        $items ??= [$item];
        Lang::with($u->lang(), function () use ($u, $company, $kind, $items) {
            $one = $items[0];
            $p = ['name' => $one['worker'], 'week' => $one['week']];
            $subject = match (true) {
                $kind === 'reminder' && count($items) === 1 => __('Tienes 1 hoja pendiente de tu aprobación'),
                $kind === 'reminder' => __('Tienes :count hojas pendientes de tu aprobación', ['count' => count($items)]),
                $kind === 'changed' => __('Esta hoja cambió (aprobación interna): :name, semana :week', $p),
                $kind === 'resubmitted' => __('Hoja corregida para tu aprobación: :name, semana :week', $p),
                default => __('Hoja para tu aprobación: :name, semana :week', $p),
            };
            Mail::to($u->email, $u->name)->locale($u->lang())->send(new BrandedMail(
                $company, $subject, 'mail.internal-approval-request',
                ['kind' => $kind, 'items' => $items, 'name' => $u->name, 'url' => $company->baseUrl()]
            ));
        });
    }

    /** Recordatorios a los internos con partes pendientes (mismos días que el aprobador externo). Un correo por persona. */
    public function sendReminders(): int
    {
        $byUser = [];     // user_id => ['user' => User, 'items' => [], 'due' => bool]
        $dueSheets = [];
        Timesheet::where('status', Timesheet::SUBMITTED)->where('stage', Timesheet::STAGE_INTERNAL)->with('company', 'user')->get()
            ->each(function (Timesheet $sheet) use (&$byUser, &$dueSheets) {
                $rules = $sheet->company->rules();
                $due = $sheet->submitted_at && $sheet->submitted_at->lte(now()->subDays((int) $rules->get('approval_remind_days')))
                    && (! $sheet->internal_reminded_at || $sheet->internal_reminded_at->lte(now()->subDays((int) $rules->get('remind_every_days'))));
                $parts = $this->pendingParts($sheet);
                foreach ($parts->flatMap(fn ($p) => $this->waitingOnPart($p))->unique('id') as $u) {
                    $mine = $parts->filter(fn ($p) => $this->waitingOnPart($p)->contains('id', $u->id));
                    $byUser[$u->id]['user'] = $u;
                    $byUser[$u->id]['sheet'] ??= $sheet;
                    $byUser[$u->id]['items'][] = $this->item($sheet, $mine);
                    $byUser[$u->id]['due'] = ($byUser[$u->id]['due'] ?? false) || $due;
                }
                if ($due) {
                    $dueSheets[] = $sheet->id;
                }
            });
        $sent = 0;
        foreach ($byUser as $row) {
            if ($row['due']) {
                $this->mail($row['user'], $row['sheet'], null, 'reminder', $row['items']);
                $sent++;
            }
        }
        if ($dueSheets) {
            Timesheet::whereIn('id', $dueSheets)->update(['internal_reminded_at' => now()]);
        }
        return $sent;
    }
}
