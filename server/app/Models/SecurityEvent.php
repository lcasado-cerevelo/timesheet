<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Registro de seguridad: quién hizo qué a quién, cuándo y desde dónde. Nunca guarda contraseñas. */
class SecurityEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
