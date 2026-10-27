<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una tarea de un proyecto (opcional en las horas). Las tareas no se borran, se desactivan. */
class Task extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'budget_hours' => 'decimal:2'];
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
