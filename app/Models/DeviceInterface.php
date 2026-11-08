<?php

namespace App\Models;

use App\Support\MacAddress;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    protected function macAddress(): Attribute
    {
        return Attribute::set(fn (?string $value) => MacAddress::normalize($value));
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
