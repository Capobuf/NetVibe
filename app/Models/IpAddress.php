<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['address', 'description', 'hostname', 'mac_address', 'ip_status_id'])]
class IpAddress extends Model
{
    /** @return BelongsTo<IpStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(IpStatus::class, 'ip_status_id');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    protected function casts(): array
    {
        return ['address' => 'integer'];
    }
}
