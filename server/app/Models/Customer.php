<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** Aprobadores externos ligados a este cliente. */
    public function approvers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Approver::class, 'approver_customer');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
