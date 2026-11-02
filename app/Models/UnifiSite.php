<?php

namespace App\Models;

use App\Support\UnifiInventory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['console_id', 'console_name', 'external_site_id', 'name', 'version', 'available', 'preview', 'discovered_at', 'site_id', 'confirmed_at', 'mapped_site_uuid', 'inventory_activated_at', 'last_synced_at', 'sync_status'])]
class UnifiSite extends Model
{
    protected function casts(): array
    {
        return ['available' => 'boolean', 'preview' => 'array', 'discovered_at' => 'datetime', 'confirmed_at' => 'datetime', 'inventory_activated_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function unifiConnection(): BelongsTo
    {
        return $this->belongsTo(UnifiConnection::class, 'unifi_connection_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function unifiDevices(): HasMany
    {
        return $this->hasMany(UnifiDevice::class);
    }

    public function summary(): array
    {
        return [
            ...$this->only('id', 'console_id', 'console_name', 'external_site_id', 'name', 'version', 'available', 'preview', 'discovered_at', 'confirmed_at'),
            'inventory' => $this->site_id ? app(UnifiInventory::class)->summary($this) : null,
            'site' => $this->site ? [
                ...$this->site->only('id', 'name'), 'company' => $this->site->company->only('id', 'name'),
            ] : null,
        ];
    }
}
