<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un servicio de un proyecto. Define la tarifa, si se factura y cómo se entran las horas:
 *  - detail: desde / hasta (reloj incluido)
 *  - total:  total de horas del día
 *  - both:   lo decide la preferencia de la persona (users.capture_mode)
 * Los servicios no se borran, se desactivan.
 */
class Service extends Model
{
    public const MODES = ['detail', 'total', 'both'];
    /** Qué aprobación pide el servicio: ninguna, solo interna, solo externa o las dos. */
    public const APPROVAL_MODES = ['none', 'internal', 'external', 'both'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['billable' => 'boolean', 'active' => 'boolean', 'bill_rate' => 'decimal:2', 'require_note' => 'boolean'];
    }

    public function needsInternal(): bool
    {
        return in_array($this->approval_mode, ['internal', 'both'], true);
    }

    public function needsExternal(): bool
    {
        return in_array($this->approval_mode ?: 'external', ['external', 'both'], true);
    }

    /** Supervisores (usuarios con cuenta) de este servicio: basta que apruebe uno. */
    public function internalApprovers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'service_internal_approvers');
    }

    /** Aprobadores externos escogidos entre los del cliente. Vacío = todos los aprobadores activos del cliente del proyecto. */
    public function externalApprovers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Approver::class, 'service_external_approvers');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Cómo entra las horas esta persona en este servicio: 'from_to' (detalle) o 'full_day' (total). */
    public function effectiveMode(User $user): string
    {
        return match ($this->capture_mode) {
            'detail' => 'from_to',
            'total' => 'full_day',
            default => $user->capture_mode === 'full_day' ? 'full_day' : 'from_to',
        };
    }

    /** Tarifa de venta: la del servicio y, si no tiene, la del proyecto. */
    public function effectiveRate(): ?float
    {
        $r = $this->bill_rate ?? $this->project?->bill_rate;
        return $r === null ? null : (float) $r;
    }

    protected static function booted(): void
    {
        // requires_internal_approval del proyecto se deriva de sus servicios activos.
        static::saved(fn (Service $s) => $s->project?->syncInternalFlag());
    }
}
