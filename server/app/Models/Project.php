<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Project extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'billable' => 'boolean',
            'active' => 'boolean',
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

    public function requiresTimes(): bool
    {
        return $this->invoice_detail === 'with_times';
    }
}
