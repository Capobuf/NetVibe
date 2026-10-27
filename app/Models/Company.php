<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Company extends Model
{
    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
