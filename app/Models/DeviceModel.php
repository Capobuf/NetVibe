<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeviceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeviceModel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'brand',
        'model',
        'type',
        'u_size',
        'ethernet_ports',
        'sfp_ports',
        'description',
    ];

    protected $casts = [
        'type' => DeviceType::class,
        'u_size' => 'integer',
        'ethernet_ports' => 'integer',
        'sfp_ports' => 'integer',
    ];

    public function devices()
    {
        return $this->hasMany(Device::class, 'device_model_id');
    }

    // Friendly combined name for Filament relationship labels and selects
    public function getNameAttribute(): string
    {
        return trim(sprintf('%s %s', $this->brand ?? '', $this->model ?? ''));
    }
}
