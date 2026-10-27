<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'color'])]
class Tag extends Model
{
    public const COLORS = ['gray', 'blue', 'green', 'amber', 'orange', 'red', 'purple'];
}
