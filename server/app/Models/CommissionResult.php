<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que ganó una persona por una tarea completada, con el cálculo tal como salió (no se recalcula).
 * Estados: pending (calculada, falta que el dueño la libere), released (liberada: puede facturarse o pagarse), invoiced (ya salió en una
 * factura), paid (pagada a un empleado), none (no hubo comisión que pagar: se guarda para saber por qué).
 */
class CommissionResult extends Model
{
    public const STATUSES = ['pending', 'released', 'invoiced', 'paid', 'none'];

    protected $guarded = [];

    protected function casts(): array
    {
        $dec = ['budget_hours', 'goal_hours', 'tolerance_hours', 'total_hours', 'user_hours', 'commission_hours', 'user_pct', 'saved_hours', 'percent_required',
            'extra_per_hour', 'savings_pct', 'savings_per_hour', 'commission_amount', 'savings_amount', 'adjustment', 'total'];
        return array_fill_keys($dec, 'decimal:2') + [
            'completed_on' => 'date:Y-m-d', 'deadline' => 'date:Y-m-d',
            'met_goal' => 'boolean', 'met_date' => 'boolean', 'worked_min' => 'boolean', 'met_share' => 'boolean', 'withhold' => 'boolean', 'ivu' => 'boolean',
            'released_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    /** ¿Todavía se puede ajustar o descartar? (no si ya se facturó o se pagó). */
    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'released', 'none'], true);
    }
}
