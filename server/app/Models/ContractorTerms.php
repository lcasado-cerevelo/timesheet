<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractorTerms extends Model
{
    protected $table = 'contractor_terms';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'retention_pct' => 'decimal:2',
            'ivu_pct' => 'decimal:2',
            'ivu_agent' => 'boolean',
        ];
    }
}
