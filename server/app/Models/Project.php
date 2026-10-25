<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'billable' => 'boolean',
            'active' => 'boolean',
            'requires_internal_approval' => 'boolean',
            'meta' => 'array',
            'bill_rate' => 'decimal:2',
            'budget_hours' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Approver::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** Supervisores (usuarios con cuenta) que aprueban las horas de este proyecto antes del aprobador externo. */
    public function internalApprovers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_internal_approvers');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class)->orderBy('sort')->orderBy('id');
    }

    /** Todo proyecto tiene siempre al menos un servicio; el primero se crea solo y se comporta como antes. */
    protected static function booted(): void
    {
        static::created(function (Project $p) {
            if (! $p->services()->exists()) {
                $p->services()->create(['company_id' => $p->company_id, 'name' => 'General', 'bill_rate' => null,
                    'billable' => (bool) ($p->billable ?? true), 'capture_mode' => 'both', 'active' => true]);
            }
        });
    }
}
