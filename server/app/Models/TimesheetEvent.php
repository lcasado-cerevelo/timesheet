<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimesheetEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
