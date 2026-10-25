<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ["config" => "array", "is_system" => "boolean"];
    }
}
