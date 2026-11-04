<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array{faces: array{front: array{ports: list<array<string, mixed>>}, rear: array{ports: list<array<string, mixed>>}}, ...}|null $panel
 * @property array<string, string>|null $assets
 */
#[Fillable(['name', 'manufacturer_id', 'model_type_id', 'panel', 'assets', 'panel_revision', 'rack_height_u', 'without_rack_ears'])]
class DeviceModel extends Model
{
    protected $hidden = ['assets'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['panel' => 'array', 'assets' => 'array', 'panel_revision' => 'integer', 'rack_height_u' => 'integer', 'without_rack_ears' => 'boolean'];
    }

    public function isInfrastructure(): bool
    {
        return $this->modelType?->is_infrastructure ?? false;
    }

    /** @return BelongsTo<Manufacturer, $this> */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    /** @return BelongsTo<ModelType, $this> */
    public function modelType(): BelongsTo
    {
        return $this->belongsTo(ModelType::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return list<array<string, mixed>> */
    public function connectors(): array
    {
        return [...($this->panel['faces']['front']['ports'] ?? []), ...($this->panel['faces']['rear']['ports'] ?? [])];
    }

    /** @return array<string, string> */
    public function assetUrls(): array
    {
        return collect($this->assets ?? [])->map(fn ($path, $id) => route('device-models.assets', [$this, $id]))->all();
    }
}
