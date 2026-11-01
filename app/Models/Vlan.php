<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tag', 'name'])]
class Vlan extends Model
{
    protected function casts(): array
    {
        return ['excluded_at' => 'datetime'];
    }

    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->whereNull('vlans.excluded_at');
    }

    /** @return HasMany<Subnet, $this> */
    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }
}
