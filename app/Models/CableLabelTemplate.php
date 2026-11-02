<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'width', 'cable_type', 'settings', 'revision'])]
class CableLabelTemplate extends Model
{
    protected function casts(): array
    {
        return ['width' => 'integer', 'settings' => 'array', 'revision' => 'integer'];
    }
}
