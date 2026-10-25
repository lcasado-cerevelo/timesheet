<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'must_set_password' => 'boolean',
            'weekly_hours' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Approver::class);
    }

    /** Aprobador al que representa esta cuenta (solo cuentas con role 'approver'). */
    public function approverProfile(): BelongsTo
    {
        return $this->belongsTo(Approver::class, 'approver_profile_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }

    /** Proyectos donde esta persona es aprobador interno. */
    public function internalProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_internal_approvers');
    }

    /** Es aprobador interno si lo es de al menos un proyecto activo que exige aprobación interna. */
    public function isInternalApprover(): bool
    {
        return $this->internalProjects()->where('projects.active', true)->where('projects.requires_internal_approval', true)
            ->where('projects.company_id', $this->company_id)->exists();
    }

    public function terms(): HasOne
    {
        return $this->hasOne(ContractorTerms::class);
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    /** Idioma de esta persona: el suyo, si no el de su compañía. Es el de los correos que recibe. */
    public function lang(): string
    {
        return in_array($this->locale, ['es', 'en'], true) ? $this->locale : $this->company->lang();
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isApprover(): bool
    {
        return $this->role === 'approver';
    }

    /** 'pending' hasta que la persona crea su contraseña; luego 'active'. */
    public function invitationStatus(): string
    {
        return $this->must_set_password ? 'pending' : 'active';
    }

    public function isEmployee(): bool
    {
        return $this->worker_type === 'employee';
    }
}
