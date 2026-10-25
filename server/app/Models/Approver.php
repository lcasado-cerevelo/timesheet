<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approver extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['require_code' => 'boolean', 'active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
