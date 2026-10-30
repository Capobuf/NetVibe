<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'manufacturer_id', 'device_model_id', 'serial_number'])]
class Device extends Model
{
    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Manufacturer, $this> */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    /** @return BelongsTo<DeviceModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(DeviceModel::class, 'device_model_id');
    }

    /** @return HasMany<DeviceInterface, $this> */
    public function interfaces(): HasMany
    {
        return $this->hasMany(DeviceInterface::class);
    }

    /** @return HasMany<IpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    public function addModelInterfaces(DeviceModel $model): void
    {
        foreach ($model->connectors() as $port) {
            if (in_array($port['type'], ['rj45', 'sfp', 'qsfp'], true)) {
                $this->interfaces()->firstOrCreate(['connector_id' => $port['id']], ['kind' => 'physical']);
            }
        }
    }
}
