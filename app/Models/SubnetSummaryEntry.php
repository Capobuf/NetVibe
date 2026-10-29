<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'values'])]
class SubnetSummaryEntry extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['values' => 'array'];
    }
}
