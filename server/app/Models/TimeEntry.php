<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    /** reg = regular, ot = tiempo extra, hol = feriado, sick = enfermedad, vac = vacaciones, nb = no facturable. */
    public const HOUR_TYPES = ['reg', 'ot', 'hol', 'sick', 'vac', 'nb'];

    /** Tipos que se facturan según el servicio; los demás nunca. */
    public const BILLABLE_HOUR_TYPES = ['reg', 'ot'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'hours' => 'decimal:2',
            'running' => 'boolean',
            'billable' => 'boolean',
            'bill_rate' => 'decimal:2',
            'pay_rate' => 'decimal:2',
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

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Tarifa de venta de esta entrada: la guardada al crearla (o, en datos viejos, la del servicio o el proyecto). */
    public function saleRate(): float
    {
        return (float) ($this->bill_rate ?? $this->service?->effectiveRate() ?? $this->project?->bill_rate ?? 0);
    }

    /** Tarifa del consultor de esta entrada: la guardada al crearla o, si no hay, la actual. */
    public function payRate(): float
    {
        return (float) ($this->pay_rate ?? $this->user?->terms?->rate ?? 0);
    }

    /** 'times' si se entró hora por hora (desde/hasta); 'total' si se entró el total de horas del día. */
    public function enteredAs(): string
    {
        return $this->start_time && $this->end_time ? 'times' : 'total';
    }

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    /** Segunda barrera: aunque algo llame al modelo directo, una hoja aprobada no se toca. */
    protected static function booted(): void
    {
        $guard = function (TimeEntry $e) {
            $sheet = Timesheet::find($e->timesheet_id);
            if ($sheet && $sheet->isLocked()) {
                throw new \App\Exceptions\SheetLockedException();
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }
}
