<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tag', 'name'])]
class Vlan extends Model
{
    /** @return HasMany<Subnet, $this> */
    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }
}
