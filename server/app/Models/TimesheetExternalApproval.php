<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una parte (servicio) de una hoja aprobada por un aprobador externo. approved_by_approver_id nulo = parte sin aprobadores (dueño que se aprueba solo). */
class TimesheetExternalApproval extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Approver::class, 'approved_by_approver_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
