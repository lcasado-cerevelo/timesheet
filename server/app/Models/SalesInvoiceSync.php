<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesInvoiceSync extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ["project_ids" => "array", "period_from" => "date:Y-m-d", "period_to" => "date:Y-m-d"];
    }
}
