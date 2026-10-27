<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una tarea de un proyecto. 'active' = true es una tarea abierta; false es una tarea completada (deja de salir al registrar horas y las
 * horas ya registradas la conservan). Una tarea sin horas registradas se puede borrar; con horas, solo se completa.
 */
class Task extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'budget_hours' => 'decimal:2', 'completed_on' => 'date:Y-m-d'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
