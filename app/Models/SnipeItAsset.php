<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sync_id', 'external_id', 'snipe_it_model_id', 'device_id', 'snapshot', 'state', 'present', 'needs_confirmation', 'problem'])]
class SnipeItAsset extends Model
{
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'present' => 'boolean', 'needs_confirmation' => 'boolean'];
    }

    public function sync(): BelongsTo
    {
        return $this->belongsTo(SnipeItSync::class);
    }

    public function externalModel(): BelongsTo
    {
        return $this->belongsTo(SnipeItModel::class, 'snipe_it_model_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
