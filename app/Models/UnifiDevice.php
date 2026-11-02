<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['external_id', 'device_id', 'status', 'present', 'observed', 'ports', 'maintained', 'synced_at'])]
class UnifiDevice extends Model
{
    protected function casts(): array
    {
        return ['present' => 'boolean', 'observed' => 'array', 'ports' => 'array', 'maintained' => 'array', 'synced_at' => 'datetime'];
    }

    public function unifiSite(): BelongsTo
    {
        return $this->belongsTo(UnifiSite::class);
    }

    public function stale(): bool
    {
        return ! $this->present || ! $this->unifiSite->available || $this->unifiSite->sync_status !== 'ok'
            || ! $this->unifiSite->unifiConnection->enabled;
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
