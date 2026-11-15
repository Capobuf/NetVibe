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
        return ['image_width' => 'integer', 'image_height' => 'integer', 'revision' => 'integer'];
    }

    /** Sort key: basements below zero, PT zero, upper floors above. */
    public function level(): int
    {
        return $this->code === 'PT' ? 0 : (int) substr($this->code, 1);
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
