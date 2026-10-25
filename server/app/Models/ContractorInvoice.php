<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractorInvoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            "period_from" => "date:Y-m-d", "period_to" => "date:Y-m-d",
            "issued_on" => "date:Y-m-d", "due_on" => "date:Y-m-d",
            "gross" => "decimal:2", "retention" => "decimal:2", "ivu" => "decimal:2", "net" => "decimal:2",
            "lines" => "array",
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
