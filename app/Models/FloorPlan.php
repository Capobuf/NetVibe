<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'floor', 'image_width', 'image_height', 'revision'])]
#[Hidden(['image_path'])]
class FloorPlan extends Model
{
    protected function casts(): array
    {
        return ['image_width' => 'integer', 'image_height' => 'integer', 'revision' => 'integer'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FloorPlanItem::class);
    }
}
