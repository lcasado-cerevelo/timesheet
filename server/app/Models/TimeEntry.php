<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'hours' => 'decimal:2',
            'running' => 'boolean',
            'billable' => 'boolean',
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
