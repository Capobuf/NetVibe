<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class ModelType extends Model
{
    /** @return HasMany<DeviceModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(DeviceModel::class);
    }
}
