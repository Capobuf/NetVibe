<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'connection_id', 'external_company_id', 'external_company_name', 'confirmed_at'])]
class SnipeItCompanyLink extends Model
{
    protected function casts(): array
    {
        return ['external_company_id' => 'integer', 'confirmed_at' => 'datetime'];
    }
}
