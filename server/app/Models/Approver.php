<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Approver extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['require_code' => 'boolean', 'active' => 'boolean', 'can_login' => 'boolean', 'project_manager' => 'boolean'];
    }

    /** Clientes a los que sirve este aprobador externo. */
    public function customers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'approver_customer');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Cuenta de entrada de este aprobador (si el dueño le dio acceso). */
    public function account(): HasOne
    {
        return $this->hasOne(User::class, 'approver_profile_id');
    }

    /** none | pending | active | disabled */
    public function accountStatus(): string
    {
        $u = $this->account;
        if (! $u) {
            return 'none';
        }
        if (! $u->active) {
            return 'disabled';
        }
        return $u->must_set_password ? 'pending' : 'active';
    }
}
