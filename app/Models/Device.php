<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'hostname',
        'serial_number',
        'device_model_id',
        'description',
        'sections',
        'rack_id',
        'rack_position',
        'rack_size',
        'location_id',
        'customer_id',
        'ip_addr',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function deviceModel()
    {
        return $this->belongsTo(DeviceModel::class);
    }

    public function rack()
    {
        return $this->belongsTo(Rack::class);
    }

    public function getNameAttribute(): ?string
    {
        return $this->hostname ?? null;
    }
}
