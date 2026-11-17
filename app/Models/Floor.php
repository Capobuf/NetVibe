<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'revision'])]
#[Hidden(['image_path'])]
class Floor extends Model
{
    protected function casts(): array
    {
        return ['image_width' => 'integer', 'image_height' => 'integer', 'revision' => 'integer',
            'crop_x' => 'float', 'crop_y' => 'float', 'crop_width' => 'float', 'crop_height' => 'float', 'scale' => 'float'];
    }

    /** Sort key: basements below zero, PT zero, upper floors above. */
    public function level(): int
    {
        return $this->code === 'PT' ? 0 : (int) substr($this->code, 1);
    }

    /** @return array{x: float, y: float, width: float, height: float}|null */
    public function crop(): ?array
    {
        return $this->crop_width ? ['x' => $this->crop_x, 'y' => $this->crop_y, 'width' => $this->crop_width, 'height' => $this->crop_height] : null;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FloorItem::class);
    }
}
