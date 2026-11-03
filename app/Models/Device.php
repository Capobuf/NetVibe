<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'manufacturer_id', 'device_model_id', 'serial_number', 'asset_tag', 'notes'])]
#[Hidden(['image_path'])]
class Device extends Model
{
    public function imageUrl(int $companyId): ?string
    {
        if ($this->snipeItAsset) {
            return $this->snipeItAsset->snapshot['image'] ?? null;
        }

        return $this->image_path ? route('companies.sites.devices.image', [$companyId, $this->site_id, $this]) : null;
    }

    public function mikrotikSources(): HasMany
    {
        return $this->hasMany(MikrotikSource::class);
    }

    public function mikrotikConnection(): HasOne
    {
        return $this->hasOne(MikrotikConnection::class);
    }

    public function snipeItAsset(): HasOne
    {
        return $this->hasOne(SnipeItAsset::class);
    }

    public function unifiDevice(): HasOne
    {
        return $this->hasOne(UnifiDevice::class);
    }

    public function modelChangeProblem(int $modelId): ?string
    {
        if ($this->mikrotikConnection()->exists() && ! DeviceModel::findOrFail($modelId)->manufacturer->isMikrotik()) {
            return 'Rimuovi esplicitamente la connessione MikroTik prima di cambiare produttore.';
        }
        if ($this->device_model_id === $modelId) {
            return null;
        }
        if (CableEnd::where('device_id', $this->id)->exists()) {
            return 'Il dispositivo ha cavi collegati. Scollegali esplicitamente prima di cambiarne il modello.';
        }
        if ($this->rackItem()->exists()) {
            return 'Rimuovi prima il dispositivo dal rack per cambiarne il modello.';
        }
        if ($this->interfaces()->whereNotNull('connector_id')->exists()) {
            return 'Il dispositivo ha interfacce legate al pannello. Il cambio modello non può conservarne l’identità: mantieni il modello attuale.';
        }

        return null;
    }

    public function rackItem(): HasOne
    {
        return $this->hasOne(RackItem::class);
    }

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
