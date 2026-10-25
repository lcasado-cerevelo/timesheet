<?php

namespace App\Services;

use App\Mail\BrandedMail;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\TimesheetInternalApproval;
use App\Models\User;
use App\Support\Lang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * APROBACIÓN INTERNA. Un proyecto puede exigir que un supervisor (un usuario con cuenta, "aprobador interno")
 * apruebe las horas antes de que la hoja pase al aprobador externo (el de siempre: enlace/código o su cuenta).
 *
 * Etapas de una hoja enviada (timesheets.stage): 'internal' -> 'external' -> aprobada. Sin partes internas va directo a 'external'.
 *
 * TODAS las reglas que Luis aún no confirmó están en este archivo, para cambiarlas rápido:
 *   R1  ANY_ONE_APPROVER_SUFFICES  basta un interno por proyecto (false: hacen falta todos los internos del proyecto).
 *   R2  método parts()             una "parte" por proyecto que exige aprobación; la hoja pasa al externo con todas aprobadas.
 *                                  (Para exigir UNA sola aprobación por hoja, agrupar todos los proyectos en una sola parte en parts().)
 *   R3  SheetService::internalReturn()  la devolución interna devuelve la hoja completa; start() borra las aprobaciones
 *                                  anteriores al reenviar.
 *   R4  SKIP_PART_WHEN_NO_ELIGIBLE  quien envía nunca aprueba lo suyo (eligibleFor() la excluye); si no queda nadie
 *                                  elegible, la parte se omite y queda registrada (true) o bloquea hasta que el dueño decida (false).
 *   R5  INVALIDATE_ON_CHANGE       un cambio en la hoja durante la etapa interna borra las aprobaciones ya dadas y se vuelve a avisar.
 *   R6  OWNER_CAN_OVERRIDE         el dueño (back office) aprueba o devuelve en cualquier etapa; queda como evento "override".
 */
class InternalApprovalService
{
    public const ANY_ONE_APPROVER_SUFFICES = true;      // R1
    public const SKIP_PART_WHEN_NO_ELIGIBLE = true;     // R4
    public const INVALIDATE_ON_CHANGE = true;           // R5
    public const OWNER_CAN_OVERRIDE = true;             // R6

    // ---------- quién es interno ----------

    /** Proyectos (ids) que esta persona supervisa como interno. */
    public function supervisedProjectIds(User $user): array
    {
        return $user->internalProjects()->where('projects.requires_internal_approval', true)
            ->where('projects.company_id', $user->company_id)->pluck('projects.id')->all();
    }

    /** Quién puede aprobar en este proyecto: internos activos de la compañía, sin contar a quien envía la hoja (R4). */
    public function eligibleFor(Project $project, Timesheet $sheet): Collection
    {
        return $project->internalApprovers->filter(fn (User $u) => $u->active
            && $u->company_id === $sheet->company_id && $u->id !== $sheet->user_id)->values();
    }

    // ---------- partes de una hoja (R2) ----------

    /**
     * Una parte por proyecto de la hoja que exige aprobación interna.
     * @return Collection<int, array{project:Project, eligible:Collection, rows:Collection, status:string, approved_by:?User}>
     */
    public function parts(Timesheet $sheet): Collection
    {
        $projects = $sheet->entries()->with('project.internalApprovers')->get()
            ->pluck('project')->filter(fn ($p) => $p && $p->requires_internal_approval)->unique('id')->sortBy('code')->values();
        if ($projects->isEmpty()) {
            return collect();
        }
        $rows = $sheet->internalApprovals()->with('approvedBy')->get()->groupBy('project_id');

        return $projects->map(function (Project $p) use ($sheet, $rows) {
            $eligible = $this->eligibleFor($p, $sheet);
            $done = ($rows->get($p->id) ?? collect())->where('skipped', false)->values();
            if ($eligible->isEmpty() && self::SKIP_PART_WHEN_NO_ELIGIBLE) {
                $status = 'skipped';
            } elseif (self::ANY_ONE_APPROVER_SUFFICES) {
                $status = $done->isNotEmpty() ? 'approved' : 'pending';
            } else {
                $status = $eligible->isNotEmpty() && $eligible->every(fn ($u) => $done->contains('approved_by_user_id', $u->id)) ? 'approved' : 'pending';
            }
            return ['project' => $p, 'eligible' => $eligible, 'rows' => $done, 'status' => $status, 'approved_by' => $done->first()?->approvedBy];
        })->values();
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

    /** Partes de la hoja que esta persona supervisa (pendientes o no). */
    public function supervisedParts(Timesheet $sheet, User $user): Collection
    {
        return $this->parts($sheet)->filter(fn ($p) => $p['eligible']->contains('id', $user->id))->values();
    }

    public function waitingFor(Timesheet $sheet): Collection
    {
        return $this->parts($sheet)->flatMap(fn ($p) => $this->waitingOnPart($p))->unique('id')->values();
    }

    // ---------- ciclo ----------

    /**
     * Empieza (o reinicia) la etapa interna: borra las aprobaciones previas (R3 al reenviar, R5 al cambiar) y registra las
     * partes omitidas. Devuelve cuántas partes siguen pendientes y los proyectos omitidos.
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
            TimesheetInternalApproval::create(['timesheet_id' => $sheet->id, 'project_id' => $p['project']->id,
                'version' => $sheet->version, 'approved_by_user_id' => null, 'skipped' => true, 'approved_at' => now()]);
        }
        return ['pending' => $parts->where('status', 'pending')->count(), 'skipped' => $skipped->pluck('project'), 'cleared' => $cleared];
    }

    /** Registra la aprobación de esta persona en todas sus partes pendientes. Devuelve los proyectos aprobados. */
    public function recordApproval(Timesheet $sheet, User $user): Collection
    {
        $mine = $this->pendingPartsOf($sheet, $user);
        foreach ($mine as $p) {
            TimesheetInternalApproval::create(['timesheet_id' => $sheet->id, 'project_id' => $p['project']->id,
                'version' => $sheet->version, 'approved_by_user_id' => $user->id, 'skipped' => false, 'approved_at' => now()]);
        }
        return $mine->pluck('project');
    }

    // ---------- lo que ve la API ----------

    /** stage, waiting_for e internal de una hoja. $onlyProjectIds recorta `internal` a los proyectos de un supervisor. */
    public function info(Timesheet $s, ?array $onlyProjectIds = null): array
    {
        $stage = $s->status === Timesheet::SUBMITTED ? ($s->stage ?: Timesheet::STAGE_EXTERNAL) : null;
        $waiting = [];
        $internal = [];

        if ($stage === Timesheet::STAGE_INTERNAL) {
            $parts = $this->parts($s);
            $waiting = $parts->flatMap(fn ($p) => $this->waitingOnPart($p))->unique('id')
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'kind' => 'internal'])->values()->all();
            $internal = $parts->map(fn ($p) => [
                'project_id' => $p['project']->id,
                'project' => $p['project']->code.' · '.$p['project']->name,
                'status' => $p['status'],
                'approved_by' => $p['approved_by'] ? ['id' => $p['approved_by']->id, 'name' => $p['approved_by']->name] : null,
                'approvers' => $p['eligible']->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
            ])->all();
        } else {
            if ($stage === Timesheet::STAGE_EXTERNAL && $s->approver_id) {
                $s->loadMissing('approver');
                $waiting = $s->approver ? [['id' => $s->approver->id, 'name' => $s->approver->name, 'kind' => 'external']] : [];
            }
            if (in_array($s->status, [Timesheet::SUBMITTED, Timesheet::APPROVED], true)) {
                $internal = $s->internalApprovals()->with('approvedBy', 'project.internalApprovers')->get()->groupBy('project_id')
                    ->map(function ($rows) use ($s) {
                        $p = $rows->first()->project;
                        $done = $rows->where('skipped', false)->first();
                        return [
                            'project_id' => $p->id,
                            'project' => $p->code.' · '.$p->name,
                            'status' => $done ? 'approved' : 'skipped',
                            'approved_by' => $done?->approvedBy ? ['id' => $done->approvedBy->id, 'name' => $done->approvedBy->name] : null,
                            'approvers' => $this->eligibleFor($p, $s)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
                        ];
                    })->sortBy('project')->values()->all();
            }
        }
        if ($onlyProjectIds !== null) {
            $internal = array_values(array_filter($internal, fn ($i) => in_array($i['project_id'], $onlyProjectIds, true)));
        }
        return ['stage' => $stage, 'waiting_for' => $waiting, 'internal' => $internal];
    }

    // ---------- listas del supervisor ----------

    /** Hojas donde esta persona supervisa al menos un proyecto (y no son suyas). */
    public function scopeFor(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $ids = $this->supervisedProjectIds($user);
        return Timesheet::query()->where('company_id', $user->company_id)->where('user_id', '!=', $user->id)
            ->whereIn('status', [Timesheet::SUBMITTED, Timesheet::APPROVED, Timesheet::RETURNED])
            ->whereHas('entries', fn ($q) => $q->whereIn('project_id', $ids ?: [0]));
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

    /** Deja en la hoja (solo en memoria) las entradas de los proyectos que esta persona supervisa. */
    public function restrictTo(Timesheet $s, User $user): Timesheet
    {
        $ids = $this->supervisedProjectIds($user);
        $s->setRelation('entries', $s->entries()->with('project:id,code,name', 'service:id,name')->whereIn('project_id', $ids ?: [0])->get());
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
            'project' => $p['project']->code.' · '.$p['project']->name,
            'hours' => round((float) $entries->where('project_id', $p['project']->id)->sum('hours'), 2),
        ])->values()->all();
        return ['worker' => $sheet->user->name, 'week' => $sheet->week_ending->toDateString(),
            'total_hours' => round(array_sum(array_column($projects, 'hours')), 2), 'projects' => $projects,
            'late' => (bool) $sheet->late, 'changes' => array_filter(explode("\n", (string) $sheet->change_summary))];
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
