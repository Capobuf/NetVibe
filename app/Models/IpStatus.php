<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class IpStatus extends Model
{
    /** @return HasMany<IpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }
}
