<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['connector_id', 'name', 'kind', 'mac_address'])]
class DeviceInterface extends Model
{
    protected $with = ['mikrotikSource.device.mikrotikConnection'];

    protected $hidden = ['mikrotikSource'];

    protected $appends = ['mikrotik'];

    public function mikrotikSource(): HasOne
    {
        return $this->hasOne(MikrotikSource::class)->where('kind', 'interface');
    }

    public function getMikrotikAttribute(): ?array
    {
        return $this->mikrotikSource?->interfaceDetails();
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return HasMany<IpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }
}
