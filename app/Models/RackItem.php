<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['key', 'kind', 'device_id', 'shelf_key', 'position', 'height', 'width', 'x'])]
class RackItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['position' => 'integer', 'height' => 'float', 'width' => 'float', 'x' => 'float'];
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
