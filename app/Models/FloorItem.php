<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['device_id', 'rack_id', 'x', 'y'])]
class FloorItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float'];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }
}
