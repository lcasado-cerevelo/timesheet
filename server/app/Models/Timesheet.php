<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Timesheet extends Model
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const RETURNED = 'returned';

    /** Etapa mientras status = submitted. */
    public const STAGE_INTERNAL = 'internal';
    public const STAGE_EXTERNAL = 'external';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'week_ending' => 'date:Y-m-d',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'total_hours' => 'decimal:2',
            'late' => 'boolean',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'change_notice_due_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'internal_reminded_at' => 'datetime',
        ];
    }

    /** Hojas que el aprobador externo ya puede ver: las que no están (todavía) en la etapa interna. */
    public function scopeReleasedToExternal($q)
    {
        return $q->where(fn ($x) => $x->whereNull('stage')->orWhere('stage', '!=', self::STAGE_INTERNAL));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Approver::class);
    }

    public function decidedByApprover(): BelongsTo
    {
        return $this->belongsTo(Approver::class, 'decided_by_approver_id');
    }

    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ContractorInvoice::class, 'contractor_invoice_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimeEntry::class)->orderBy('work_date')->orderBy('start_time')->orderBy('id');
    }

    public function internalApprovals(): HasMany
    {
        return $this->hasMany(TimesheetInternalApproval::class, 'timesheet_id')->orderBy('id');
    }

    public function externalApprovals(): HasMany
    {
        return $this->hasMany(TimesheetExternalApproval::class, 'timesheet_id')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TimesheetEvent::class)->orderBy('id');
    }

    /** Primer día que cubre la hoja: el inicio de su periodo, o el lunes de la semana. */
    public function periodStarting(): string
    {
        return $this->period_start?->toDateString() ?? \App\Support\Week::starting($this->week_ending->toDateString());
    }

    /** Último día que cubre la hoja: el final de su periodo, o el domingo de la semana. */
    public function periodEnding(): string
    {
        return $this->period_end?->toDateString() ?? $this->week_ending->toDateString();
    }

    /** ¿Esta hoja (enviada, devuelta o aprobada) cubre ese día? Una hoja en borrador nunca cubre: solo guarda lo que falta por enviar. */
    public function covers(string $date): bool
    {
        return $this->status !== self::DRAFT && $date >= $this->periodStarting() && $date <= $this->periodEnding();
    }

    /** La semana de la hoja escrita para mensajes: la fecha de siempre y, si es una parte, los días que cubre. */
    public function weekLabel(): string
    {
        $end = $this->week_ending->toDateString();
        if (! $this->period_start || ! $this->period_end) {
            return $end;
        }
        return $end.' ('.__('parte :n: del :from al :to', ['n' => $this->part, 'from' => $this->periodStarting(), 'to' => $this->periodEnding()]).')';
    }

    /** Aprobada = bloqueada. */
    public function isLocked(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function isEditable(): bool
    {
        return ! $this->isLocked();
    }

    public function recalcTotal(): void
    {
        $this->total_hours = (float) $this->entries()->sum('hours');
        $this->save();
    }

    /** Horas facturables y no facturables (a partir de las entradas, si ya se cargaron). */
    public function hoursSplit(?\Illuminate\Support\Collection $entries = null): array
    {
        $entries ??= $this->entries()->get(['hours', 'billable']);
        $billable = round((float) $entries->where('billable', true)->sum('hours'), 2);
        $non = round((float) $entries->where('billable', false)->sum('hours'), 2);
        return ['billable_hours' => $billable, 'non_billable_hours' => $non, 'total_hours' => round($billable + $non, 2)];
    }

    /** Advertencias que no bloquean (empleados: pasa de sus horas por semana). */
    public function warnings(): array
    {
        $w = [];
        $user = $this->user;
        if ($user && $user->isEmployee() && $user->weekly_hours !== null
            && (float) $this->total_hours > (float) $user->weekly_hours) {
            $w[] = ['code' => 'over_weekly_hours', 'message' => __(
                'Pasa de :limit h por semana (:hours h).', ['limit' => rtrim(rtrim((string) $user->weekly_hours, '0'), '.'), 'hours' => (float) $this->total_hours]
            )];
        }
        return $w;
    }
}
