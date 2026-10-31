<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['site_id', 'type', 'color', 'purpose'])]
class Cable extends Model
{
    public function ends(): HasMany
    {
        return $this->hasMany(CableEnd::class);
    }

    public function workspaceData(): array
    {
        $ends = $this->ends->keyBy('side');

        return [
            ...$this->only('id', 'type', 'color', 'purpose'),
            'a' => $ends['a']->only('device_id', 'connector_id'),
            'b' => $ends['b']->only('device_id', 'connector_id'),
        ];
    }
}
