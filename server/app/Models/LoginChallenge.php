<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Desafío de segundo paso: el código de 6 dígitos enviado por correo (solo su hash). */
class LoginChallenge extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['code_expires_at' => 'datetime', 'last_sent_at' => 'datetime', 'expires_at' => 'datetime',
            'used_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
