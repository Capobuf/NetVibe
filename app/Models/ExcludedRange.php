<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['start_address', 'end_address'])]
class ExcludedRange extends Model
{
    protected function casts(): array
    {
        return ['start_address' => 'integer', 'end_address' => 'integer'];
    }
}
