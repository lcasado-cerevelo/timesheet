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

    public function events(): HasMany
    {
        return $this->hasMany(TimesheetEvent::class)->orderBy('id');
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
