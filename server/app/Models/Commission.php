<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** La comisión por productividad de una persona en un proyecto (una por proyecto). La configura el dueño. */
class Commission extends Model
{
    /** Cómo se reparte el bono del equipo: fixed = un % fijo; assigned = el % que le correspondía hacer (percent_required); actual = el % que realmente trabajó. */
    public const SAVINGS_MODES = ['fixed', 'assigned', 'actual'];

    /** Sobre qué horas se paga la comisión individual: las trabajadas por la persona o el % que le correspondía del total de la tarea. */
    public const COMMISSION_BASES = ['worked', 'assigned'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'percent_required' => 'decimal:2', 'extra_per_hour' => 'decimal:2', 'savings_pct' => 'decimal:2', 'savings_per_hour' => 'decimal:2',
            'tolerance_hours' => 'decimal:2', 'withhold' => 'boolean', 'ivu' => 'boolean', 'active' => 'boolean',
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

    /** Servicios del proyecto cuyas horas de esta persona cuentan. Vacío = todos. */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'commission_service');
    }

    /** Tareas (marcadas "aplica comisión") que entran en esta comisión. */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'commission_task');
    }

    public function results(): HasMany
    {
        return $this->hasMany(CommissionResult::class);
    }

    /**
     * La parte del bono del equipo que se reconoce a esta persona: el % fijo, el que le correspondía hacer, o (en el modo "actual") el % que
     * realmente trabajó del total de la tarea, que se conoce al calcular ($actualPct).
     */
    public function savingsShare(?float $actualPct = null): float
    {
        return match ($this->savings_mode) {
            'assigned' => (float) $this->percent_required,
            'actual' => (float) ($actualPct ?? 0),
            default => (float) $this->savings_pct,
        };
    }
}
