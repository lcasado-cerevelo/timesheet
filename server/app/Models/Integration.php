<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    public static function accountisiConfig(Company $company): array
    {
        return static::where('company_id', $company->id)->where('type', 'accountisi')->first()?->config ?? [];
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ["enabled" => "boolean", "config" => "encrypted:array", "last_ok_at" => "datetime"];
    }
}
