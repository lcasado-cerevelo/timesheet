<?php

namespace App\Services;

use App\Models\ApprovalToken;
use App\Models\Approver;
use App\Models\Timesheet;
use App\Models\TimesheetEvent;
use App\Models\User;
use App\Support\Parts;
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
    public function __construct(private ApprovalService $approvals, private InternalApprovalService $internal, private ExternalApprovalService $external)
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
    public function entriesChanged(Timesheet $sheet, string $changeLine, User $actor, string $channel, array $serviceIds = []): void
    {
        $sheet->recalcTotal();
        $this->log($sheet, 'edited', $actor->name, $channel, ['change' => $changeLine]);

        if ($sheet->status === Timesheet::SUBMITTED) {
            $minutes = (int) $sheet->company->rules()->get('change_notice_minutes');
            $lines = array_filter([$sheet->change_summary, Parts::tag($changeLine, $serviceIds)]);
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
                    $this->finishInternal($sheet);
                }
            } elseif ($sheet->stage === Timesheet::STAGE_EXTERNAL || $sheet->stage === null) {
                // R5 también en la etapa externa: el cambio borra las aprobaciones externas ya dadas; el aviso sale con la espera de siempre.
                $r = $this->external->restart($sheet);
                if ($r['cleared']) {
                    $this->log($sheet, 'external_invalidated', null, null, ['stage' => 'external', 'version' => $sheet->version]);
                }
                if ($r['total'] === 0) {
                    // Ya ninguna línea pide aprobación externa: no hay a quién esperar.
                    $this->approveWithoutExternal($sheet);
                } elseif ($r['pending'] === 0 && $this->external->allSelfApproved($sheet)) {
                    $this->approve($sheet->refresh(), null, $sheet->user, 'self');
                }
            }
        }
    }

    /**
     * La etapa interna terminó: si algún servicio de la hoja pide aprobación externa, pasa al aprobador externo;
     * si no, la hoja queda aprobada con la aprobación interna como final.
     */
    public function finishInternal(Timesheet $sheet, ?User $by = null): Timesheet
    {
        $sheet->refresh();
        if ($this->internal->requiresExternal($sheet)) {
            return $this->advanceToExternal($sheet);
        }
        return $this->approveWithoutExternal($sheet, $by);
    }

    /**
     * Aprobación que no pasa por el aprobador externo. Con $by (o con quien aprobó la última parte interna) queda como
     * aprobación interna final; sin nadie (servicios sin aprobación, o partes omitidas) queda como 'auto_approved'.
     */
    private function approveWithoutExternal(Timesheet $sheet, ?User $by = null): Timesheet
    {
        $sheet->refresh();
        $by ??= $sheet->internalApprovals()->where('skipped', false)->whereNotNull('approved_by_user_id')
            ->latest('id')->first()?->approvedBy;
        $this->freezeRates($sheet);
        $sheet->fill([
            'status' => Timesheet::APPROVED, 'stage' => null, 'decided_at' => now(),
            'decided_by_approver_id' => null, 'decided_by_user_id' => $by?->id,
            'decided_via' => $by ? 'internal' : 'auto', 'change_notice_due_at' => null,
        ])->save();
        ApprovalToken::where('timesheet_id', $sheet->id)->whereNull('used_at')->update(['used_at' => now()]);
        $this->log($sheet, $by ? 'approved' : 'auto_approved', $by?->name, $by ? 'internal' : 'auto',
            ['version' => $sheet->version, 'stage' => $by ? 'internal' : null]);
        $this->approvals->notifyWorkerDecision($sheet, 'approved', $by?->name);
        return $sheet->refresh();
    }

    /** Las tarifas quedan guardadas en cada entrada antes de cerrar la hoja (las entradas nuevas ya las traen al crearse). */
    private function freezeRates(Timesheet $sheet): void
    {
        foreach ($sheet->entries()->with('project', 'service', 'user.terms')->get() as $e) {
            if ($e->bill_rate === null || $e->pay_rate === null) {
                $e->forceFill(['bill_rate' => $e->bill_rate ?? $e->saleRate(), 'pay_rate' => $e->pay_rate ?? ($e->user?->terms?->rate !== null ? (float) $e->user->terms->rate : null)])->saveQuietly();
            }
        }
    }

    /** La etapa interna terminó y falta el externo: la hoja pasa a los aprobadores externos con lo mismo que se les enviaba al enviar. */
    public function advanceToExternal(Timesheet $sheet): Timesheet
    {
        $sheet->refresh();
        $sheet->fill(['stage' => Timesheet::STAGE_EXTERNAL, 'change_notice_due_at' => null, 'last_notified_version' => $sheet->version,
            'approver_id' => $this->external->hintApproverId($sheet)])->save();
        $this->log($sheet, 'stage_external', null, null, ['stage' => 'external', 'version' => $sheet->version]);
        $last = $sheet->events()->whereIn('type', ['submitted', 'resubmitted'])->latest('id')->value('type');
        return $this->startExternal($sheet, $last === 'resubmitted' ? 'resubmitted' : 'new');
    }

    /**
     * Arranca las partes externas (una por servicio) y avisa a cada aprobador con sus horas. Si todas las partes son de un dueño
     * sin otro aprobador, autoaprobación, como al enviar.
     */
    private function startExternal(Timesheet $sheet, string $kind): Timesheet
    {
        $r = $this->external->restart($sheet);
        if ($r['total'] === 0) {
            return $this->approveWithoutExternal($sheet);
        }
        if ($r['pending'] === 0) {
            return $this->approve($sheet, null, $sheet->user, 'self');
        }
        $this->external->notify($sheet, $kind);
        return $sheet->refresh();
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

        // Cada servicio decide qué aprobación pide (none | internal | external | both).
        $needsExternal = $this->internal->requiresExternal($sheet);
        if ($needsExternal) {
            $this->external->assertResolvable($sheet);
        }
        $company = $sheet->company;
        $deadline = $company->rules()->deadlineFor($sheet->week_ending->toDateString(), $company->timezone);
        $kind = $sheet->status === Timesheet::RETURNED ? 'resubmitted' : 'new';

        $sheet->fill([
            'status' => Timesheet::SUBMITTED,
            'stage' => null,
            'version' => $kind === 'resubmitted' ? $sheet->version + 1 : $sheet->version,
            'approver_id' => $needsExternal ? $this->external->hintApproverId($sheet) : null,
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
        $sheet->externalApprovals()->delete();
        $stage = $r['pending'] > 0 ? Timesheet::STAGE_INTERNAL : ($needsExternal ? Timesheet::STAGE_EXTERNAL : null);
        $sheet->forceFill(['stage' => $stage])->save();
        $this->log($sheet, $kind === 'resubmitted' ? 'resubmitted' : 'submitted', $actor->name, $channel, ['stage' => $stage]);
        foreach ($r['skipped'] as $part) {
            $this->log($sheet, 'internal_skipped', $actor->name, $channel, ['stage' => 'internal', 'project' => $part['project']->code, 'service' => $part['service']->name]);
        }

        if ($stage === null) {
            // Nada que esperar: todo servicio es 'none' (o la interna no tenía a quién esperar y no hay externa).
            return $this->approveWithoutExternal($sheet);
        }

        if ($stage === Timesheet::STAGE_INTERNAL) {
            $sheet->last_notified_version = $sheet->version;
            $sheet->save();
            $this->internal->notify($sheet, $kind);
            return $sheet->refresh();
        }

        $sheet->last_notified_version = $sheet->version;
        $sheet->save();
        return $this->startExternal($sheet, $kind);
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
        if ($approver !== null) {
            return $this->externalApprove($sheet, $approver, $via);
        }

        return $this->finalApprove($sheet, null, $user, $via, $override);
    }

    /**
     * Un aprobador externo aprueba SOLO las partes (servicios) que le tocan. La hoja queda aprobada cuando todas las partes
     * externas lo están; mientras tanto sigue enviada, esperando a los demás.
     */
    private function externalApprove(Timesheet $sheet, Approver $approver, string $via): Timesheet
    {
        $mine = $this->external->recordApproval($sheet, $approver);
        if ($mine->isEmpty()) {
            throw ValidationException::withMessages(['status' => __('No tienes partes pendientes de aprobar en esta hoja.')]);
        }
        $this->log($sheet, 'external_approved', $approver->name, $via, ['stage' => 'external', 'version' => $sheet->version,
            'services' => $mine->map(fn ($p) => $p['service']?->name)->filter()->values()->all()]);
        // Sus enlaces ya cumplieron: no hay nada más que decidir por su parte.
        ApprovalToken::where('timesheet_id', $sheet->id)->where('approver_id', $approver->id)->whereNull('used_at')->update(['used_at' => now()]);
        // Basta uno por parte: los demás aprobadores a quienes ya no les queda nada pendiente pierden su enlace.
        ApprovalToken::where('timesheet_id', $sheet->id)->where('scope', 'sheet')->whereNull('used_at')->with('approver')->get()
            ->each(function (ApprovalToken $t) use ($sheet) {
                if ($t->approver && $this->external->pendingPartsOf($sheet, $t->approver)->isEmpty()) {
                    $t->forceFill(['used_at' => now()])->save();
                }
            });
        if ($this->external->pendingParts($sheet)->isEmpty()) {
            return $this->finalApprove($sheet, $approver, null, $via, false);
        }
        return $sheet->refresh();
    }

    /** Cierra la hoja como aprobada (todas las etapas listas, o anulación del dueño) y avisa a la persona. */
    private function finalApprove(Timesheet $sheet, ?Approver $approver, ?User $user, string $via, bool $override): Timesheet
    {
        $this->freezeRates($sheet);

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
        if ($approver !== null && $this->external->partsOf($sheet, $approver)->isEmpty()) {
            throw ValidationException::withMessages(['status' => __('Esta hoja no tiene partes para ti.')]);
        }
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
        $parts = $this->internal->recordApproval($sheet, $user);
        if ($parts->isEmpty()) {
            throw ValidationException::withMessages(['status' => __('No tienes partes pendientes de aprobar en esta hoja.')]);
        }
        $this->log($sheet, 'internal_approved', $user->name, 'app', ['stage' => 'internal', 'version' => $sheet->version,
            'projects' => $parts->pluck('project.code')->unique()->values()->all(), 'services' => $parts->pluck('service.name')->values()->all()]);
        if ($this->internal->pendingParts($sheet)->isEmpty()) {
            $this->finishInternal($sheet, $user);
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
                    $this->external->notify($sheet, 'changed');
                }
                $sheet->forceFill(['change_notice_due_at' => null, 'last_notified_version' => $sheet->version])->save();
                $n++;
            });
        return $n;
    }
}
