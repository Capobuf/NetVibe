<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_infrastructure', 'show_in_topology'])]
class ModelType extends Model
{
    protected function casts(): array
    {
        return ['is_infrastructure' => 'boolean', 'show_in_topology' => 'boolean'];
    }

    /** @return HasMany<DeviceModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(DeviceModel::class);
    }
}
