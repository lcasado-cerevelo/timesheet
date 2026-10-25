<?php

namespace App\Services;

use App\Models\ApprovalToken;
use App\Models\Approver;
use App\Models\Timesheet;
use App\Models\TimesheetEvent;
use App\Models\User;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de la hoja semanal:
 *   draft -> submitted -> approved (bloqueada) | returned -> (se corrige) -> submitted
 * Un cambio mientras está "submitted" sube la versión, anula los enlaces anteriores y
 * programa un aviso de "esta hoja cambió". Una hoja aprobada no se cambia nunca.
 */
class SheetService
{
    public function __construct(private ApprovalService $approvals, private InternalApprovalService $internal)
    {
    }

    public function forWeek(User $user, string $date): Timesheet
    {
        $end = Week::ending($date);
        return Timesheet::firstOrCreate(
            ['user_id' => $user->id, 'week_ending' => $end],
            ['company_id' => $user->company_id, 'status' => Timesheet::DRAFT, 'version' => 1]
        );
    }

    public function log(Timesheet $sheet, string $type, ?string $actor, ?string $channel, array $data = []): void
    {
        $req = request();
        TimesheetEvent::create([
            'timesheet_id' => $sheet->id,
            'type' => $type,
            'actor' => $actor,
            'channel' => $channel,
            'data' => $data ?: null,
            'ip' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 300) : null,
            'created_at' => now(),
        ]);
    }

    /** Llamado por EntryService después de crear, cambiar o borrar una entrada. */
    public function entriesChanged(Timesheet $sheet, string $changeLine, User $actor, string $channel): void
    {
        $sheet->recalcTotal();
        $this->log($sheet, 'edited', $actor->name, $channel, ['change' => $changeLine]);

        if ($sheet->status === Timesheet::SUBMITTED) {
            $minutes = (int) $sheet->company->rules()->get('change_notice_minutes');
            $lines = array_filter([$sheet->change_summary, $changeLine]);
            $sheet->version = $sheet->version + 1;
            $sheet->change_summary = implode("\n", array_slice($lines, -30));
            $sheet->change_notice_due_at = now()->addMinutes($minutes);
            $sheet->save();
            // Los enlaces emitidos para la versión anterior dejan de servir.
            ApprovalToken::where('timesheet_id', $sheet->id)->whereNull('used_at')
                ->update(['used_at' => now()]);

            // R5: en la etapa interna, el cambio invalida las aprobaciones internas ya dadas (el aviso sale con la espera de siempre).
            if ($sheet->stage === Timesheet::STAGE_INTERNAL) {
                $r = $this->internal->restart($sheet, InternalApprovalService::INVALIDATE_ON_CHANGE);
                if ($r['cleared']) {
                    $this->log($sheet, 'internal_invalidated', null, null, ['stage' => 'internal', 'version' => $sheet->version]);
                }
                if ($r['pending'] === 0) {
                    $this->advanceToExternal($sheet);
                }
            }
        }
    }

    /** La etapa interna terminó: la hoja pasa al aprobador externo con lo mismo que se le enviaba al enviar. */
    public function advanceToExternal(Timesheet $sheet): Timesheet
    {
        $sheet->refresh();
        $sheet->fill(['stage' => Timesheet::STAGE_EXTERNAL, 'change_notice_due_at' => null, 'last_notified_version' => $sheet->version])->save();
        $this->log($sheet, 'stage_external', null, null, ['stage' => 'external', 'version' => $sheet->version]);
        if (! $sheet->approver_id) {
            // Dueño sin otro aprobador: autoaprobación, como al enviar.
            return $this->approve($sheet, null, $sheet->user, 'self');
        }
        $last = $sheet->events()->whereIn('type', ['submitted', 'resubmitted'])->latest('id')->value('type');
        $this->approvals->sendSheetRequest($sheet, $last === 'resubmitted' ? 'resubmitted' : 'new');
        return $sheet->refresh();
    }

    /** Aprobador de la hoja: el único aprobador de los proyectos, si lo hay; si no, el de la persona. */
    public function resolveApprover(Timesheet $sheet): ?Approver
    {
        $user = $sheet->user;
        $projectApprovers = $sheet->entries()->with('project')->get()
            ->pluck('project.approver_id')->filter()->unique()->values();

        $id = null;
        if ($projectApprovers->count() === 1) {
            $id = $projectApprovers->first();
        } elseif ($projectApprovers->count() > 1 && ! $user->approver_id) {
            throw ValidationException::withMessages([
                'approver' => __('La hoja tiene proyectos con aprobadores distintos y la persona no tiene un aprobador por omisión.'),
            ]);
        } else {
            $id = $user->approver_id;
        }

        $approver = $id ? Approver::where('company_id', $user->company_id)->where('active', true)->find($id) : null;
        if (! $approver && ! $user->isOwner()) {
            throw ValidationException::withMessages(['approver' => __('No hay un aprobador asignado a esta hoja.')]);
        }
        return $approver;
    }

    public function submit(Timesheet $sheet, User $actor, string $channel = 'app'): Timesheet
    {
        if ($sheet->user_id !== $actor->id) {
            throw new \DomainException(__('Solo la persona dueña de la hoja puede enviarla.'));
        }
        if (! in_array($sheet->status, [Timesheet::DRAFT, Timesheet::RETURNED], true)) {
            throw ValidationException::withMessages(['status' => __('Esta hoja ya fue enviada o aprobada.')]);
        }
        $sheet->recalcTotal();
        if ((float) $sheet->total_hours <= 0) {
            throw ValidationException::withMessages(['status' => __('La hoja no tiene horas.')]);
        }
        if ($sheet->entries()->where('running', true)->exists()) {
            throw ValidationException::withMessages(['status' => __('Hay un reloj corriendo; páralo antes de enviar.')]);
        }

        $approver = $this->resolveApprover($sheet);
        $company = $sheet->company;
        $deadline = $company->rules()->deadlineFor($sheet->week_ending->toDateString(), $company->timezone);
        $kind = $sheet->status === Timesheet::RETURNED ? 'resubmitted' : 'new';

        $sheet->fill([
            'status' => Timesheet::SUBMITTED,
            'stage' => null,
            'version' => $kind === 'resubmitted' ? $sheet->version + 1 : $sheet->version,
            'approver_id' => $approver?->id,
            'submitted_at' => now(),
            'late' => (bool) $company->rules()->get('flag_late') && CarbonImmutable::now($company->timezone)->gt($deadline),
            'return_comment' => null,
            'change_notice_due_at' => null,
            'change_summary' => null,
            'internal_reminded_at' => null,
            'decided_at' => null,
            'decided_by_approver_id' => null,
            'decided_by_user_id' => null,
            'decided_via' => null,
        ])->save();

        // Aprobación interna (R3: al reenviar empieza de nuevo; R4: partes omitidas).
        $r = $this->internal->restart($sheet);
        $stage = $r['pending'] > 0 ? Timesheet::STAGE_INTERNAL : Timesheet::STAGE_EXTERNAL;
        $sheet->forceFill(['stage' => $stage])->save();
        $this->log($sheet, $kind === 'resubmitted' ? 'resubmitted' : 'submitted', $actor->name, $channel, ['stage' => $stage]);
        foreach ($r['skipped'] as $project) {
            $this->log($sheet, 'internal_skipped', $actor->name, $channel, ['stage' => 'internal', 'project' => $project->code]);
        }

        if ($stage === Timesheet::STAGE_INTERNAL) {
            $sheet->last_notified_version = $sheet->version;
            $sheet->save();
            $this->internal->notify($sheet, $kind);
            return $sheet->refresh();
        }

        if ($approver === null) {
            // Dueño sin otro aprobador: autoaprobación, queda registrada como tal.
            return $this->approve($sheet, null, $actor, 'self');
        }

        $sheet->last_notified_version = $sheet->version;
        $sheet->save();
        $this->approvals->sendSheetRequest($sheet, $kind);
        return $sheet->refresh();
    }

    /**
     * @param int|null $expectedVersion versión que el aprobador vio; si cambió, no se aprueba.
     */
    public function approve(Timesheet $sheet, ?Approver $approver, ?User $user, string $via, ?int $expectedVersion = null): Timesheet
    {
        $sheet->refresh();
        if ($sheet->status !== Timesheet::SUBMITTED) {
            throw ValidationException::withMessages(['status' => __('Esta hoja ya no está pendiente de aprobación.')]);
        }
        if ($expectedVersion !== null && $expectedVersion !== (int) $sheet->version) {
            throw ValidationException::withMessages(['version' => __('La hoja cambió después de enviarse; revisa la versión nueva.')]);
        }
        if ($user && $user->id === $sheet->user_id && $via !== 'self') {
            throw ValidationException::withMessages(['status' => __('No puedes aprobar tu propia hoja.')]);
        }
        $override = $this->guardStage($sheet, $approver, $user, $via);

        // Las tarifas quedan guardadas en cada entrada antes de cerrar la hoja (las entradas nuevas ya las traen al crearse).
        foreach ($sheet->entries()->with('project', 'service', 'user.terms')->get() as $e) {
            if ($e->bill_rate === null || $e->pay_rate === null) {
                $e->forceFill(['bill_rate' => $e->bill_rate ?? $e->saleRate(), 'pay_rate' => $e->pay_rate ?? ($e->user?->terms?->rate !== null ? (float) $e->user->terms->rate : null)])->saveQuietly();
            }
        }

        $sheet->fill([
            'status' => Timesheet::APPROVED,
            'stage' => null,
            'decided_at' => now(),
            'decided_by_approver_id' => $approver?->id,
            'decided_by_user_id' => $user?->id,
            'decided_via' => $via,
            'change_notice_due_at' => null,
        ])->save();
        ApprovalToken::where('timesheet_id', $sheet->id)->whereNull('used_at')->update(['used_at' => now()]);
        $this->log($sheet, $via === 'self' ? 'self_approved' : 'approved', $approver?->name ?? $user?->name, $via,
            ['version' => $sheet->version, 'stage' => $override ? 'internal' : 'external']);
        if ($override) {
            $this->log($sheet, 'override', $user->name, $via, ['action' => 'approved', 'stage' => 'internal']);
        }

        if ($via !== 'self') {
            $this->approvals->notifyWorkerDecision($sheet, 'approved');
        }
        return $sheet;
    }

    public function return(Timesheet $sheet, ?Approver $approver, ?User $user, string $via, string $comment, ?int $expectedVersion = null): Timesheet
    {
        $comment = trim($comment);
        if (mb_strlen($comment) < 3) {
            throw ValidationException::withMessages(['comment' => __('Para devolver una hoja hay que escribir un comentario.')]);
        }
        $sheet->refresh();
        if ($sheet->status !== Timesheet::SUBMITTED) {
            throw ValidationException::withMessages(['status' => __('Esta hoja ya no está pendiente de aprobación.')]);
        }
        if ($expectedVersion !== null && $expectedVersion !== (int) $sheet->version) {
            throw ValidationException::withMessages(['version' => __('La hoja cambió después de enviarse; revisa la versión nueva.')]);
        }
        $override = $this->guardStage($sheet, $approver, $user, $via);
        $stage = $sheet->stage ?: Timesheet::STAGE_EXTERNAL;

        $sheet->fill([
            'status' => Timesheet::RETURNED,
            'stage' => null,
            'decided_at' => now(),
            'decided_by_approver_id' => $approver?->id,
            'decided_by_user_id' => $user?->id,
            'decided_via' => $via,
            'return_comment' => mb_substr($comment, 0, 1000),
            'change_notice_due_at' => null,
        ])->save();
        ApprovalToken::where('timesheet_id', $sheet->id)->whereNull('used_at')->update(['used_at' => now()]);
        $this->log($sheet, 'returned', $approver?->name ?? $user?->name, $via, ['comment' => $comment, 'stage' => $stage]);
        if ($override) {
            $this->log($sheet, 'override', $user->name, $via, ['action' => 'returned', 'stage' => 'internal']);
        }
        $this->approvals->notifyWorkerDecision($sheet, 'returned');
        return $sheet;
    }

    /**
     * El aprobador externo no decide mientras la hoja está en la etapa interna. El dueño sí (R6, "override").
     * @return bool true si es una anulación del dueño sobre la etapa interna
     */
    private function guardStage(Timesheet $sheet, ?Approver $approver, ?User $user, string $via): bool
    {
        if ($sheet->stage !== Timesheet::STAGE_INTERNAL || $via === 'self') {
            return false;
        }
        if ($approver !== null || $user === null || ! $user->isOwner() || ! InternalApprovalService::OWNER_CAN_OVERRIDE) {
            throw ValidationException::withMessages(['status' => __('Esta hoja espera la aprobación interna.')]);
        }
        return true;
    }

    /** Un supervisor interno aprueba todas sus partes pendientes de la hoja. Si con eso quedan todas, pasa al externo. */
    public function internalApprove(Timesheet $sheet, User $user, ?int $expectedVersion): Timesheet
    {
        $sheet->refresh();
        $this->guardInternal($sheet, $expectedVersion);
        $projects = $this->internal->recordApproval($sheet, $user);
        if ($projects->isEmpty()) {
            throw ValidationException::withMessages(['status' => __('No tienes partes pendientes de aprobar en esta hoja.')]);
        }
        $this->log($sheet, 'internal_approved', $user->name, 'app', ['stage' => 'internal', 'version' => $sheet->version, 'projects' => $projects->pluck('code')->all()]);
        if ($this->internal->pendingParts($sheet)->isEmpty()) {
            $this->advanceToExternal($sheet);
        }
        return $sheet->refresh();
    }

    /** R3: la devolución interna vuelve la hoja completa a la persona. */
    public function internalReturn(Timesheet $sheet, User $user, string $comment, ?int $expectedVersion): Timesheet
    {
        $comment = trim($comment);
        if (mb_strlen($comment) < 3) {
            throw ValidationException::withMessages(['comment' => __('Para devolver una hoja hay que escribir un comentario.')]);
        }
        $sheet->refresh();
        $this->guardInternal($sheet, $expectedVersion);
        $sheet->fill([
            'status' => Timesheet::RETURNED, 'stage' => null, 'decided_at' => now(), 'decided_by_approver_id' => null,
            'decided_by_user_id' => $user->id, 'decided_via' => 'internal', 'return_comment' => mb_substr($comment, 0, 1000),
            'change_notice_due_at' => null,
        ])->save();
        $this->log($sheet, 'returned', $user->name, 'internal', ['comment' => $comment, 'stage' => 'internal']);
        $this->approvals->notifyWorkerDecision($sheet, 'returned', $user->name);
        return $sheet;
    }

    private function guardInternal(Timesheet $sheet, ?int $expectedVersion): void
    {
        if ($sheet->status !== Timesheet::SUBMITTED || $sheet->stage !== Timesheet::STAGE_INTERNAL) {
            throw ValidationException::withMessages(['status' => __('Esta hoja ya no está pendiente de aprobación interna.')]);
        }
        if ($expectedVersion !== null && $expectedVersion !== (int) $sheet->version) {
            throw ValidationException::withMessages(['version' => __('La hoja cambió después de enviarse; revisa la versión nueva.')]);
        }
    }

    /** Envía los avisos de "la hoja cambió" cuya espera ya venció. Corre cada minuto por cron. */
    public function sendDueChangeNotices(): int
    {
        $n = 0;
        Timesheet::where('status', Timesheet::SUBMITTED)
            ->whereNotNull('change_notice_due_at')->where('change_notice_due_at', '<=', now())
            ->each(function (Timesheet $sheet) use (&$n) {
                if ($sheet->stage === Timesheet::STAGE_INTERNAL) {
                    $this->internal->notify($sheet, 'changed');
                } else {
                    $this->approvals->sendSheetRequest($sheet, 'changed');
                }
                $sheet->forceFill(['change_notice_due_at' => null, 'last_notified_version' => $sheet->version])->save();
                $n++;
            });
        return $n;
    }
}
