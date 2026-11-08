<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['address', 'description', 'hostname', 'mac_address', 'ip_status_id', 'device_id', 'device_interface_id'])]
class IpAddress extends Model
{
    public function snipeItAsset(): HasOne
    {
        return $this->hasOne(SnipeItAsset::class);
    }

    /** @return BelongsTo<Subnet, $this> */
    public function subnet(): BelongsTo
    {
        return $this->belongsTo(Subnet::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<DeviceInterface, $this> */
    public function deviceInterface(): BelongsTo
    {
        return $this->belongsTo(DeviceInterface::class);
    }

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
        return ['address' => 'integer', 'auto_managed' => 'boolean', 'mikrotik_expected' => 'array', 'mikrotik_matched_at' => 'datetime'];
    }
}
