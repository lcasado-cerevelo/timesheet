<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ["period_from" => "date:Y-m-d", "period_to" => "date:Y-m-d", "created_at" => "datetime"];
    }
}
