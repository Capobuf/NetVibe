<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['connection_id', 'external_id', 'device_model_id', 'snapshot', 'applied', 'problem'])]
class SnipeItModel extends Model
{
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'applied' => 'array'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(DeviceModel::class, 'device_model_id');
    }
}
