<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Equipo recordado de un aprobador: salta el código por correo hasta que vence. Solo se guarda el hash. */
class TrustedDevice extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_used_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
