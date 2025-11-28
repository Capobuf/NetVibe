<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\IpConversionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IpAddress extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'subnet_id', 'ip_addr', 'is_gateway', 'description', 'hostname',
        'mac', 'owner', 'state', 'switch_id', 'location_id', 'port',
        'note', 'last_seen', 'exclude_ping', 'ptr_ignore', 'ptr',
        'firewall_address_object', 'customer_id',
        'type', 'device_id',
    ];

    protected $casts = [
        'ip_addr' => 'string',
        'is_gateway' => 'boolean',
        'exclude_ping' => 'boolean',
        'ptr_ignore' => 'boolean',
        'last_seen' => 'datetime',
    ];

    // Relationships
    public function subnet()
    {
        return $this->belongsTo(Subnet::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function switchDevice()
    {
        return $this->belongsTo(Device::class, 'switch_id');
    }

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function setIpAddrAttribute(string $value): void
    {
        $service = app(IpConversionService::class);

        // If it's already numeric (decimal string) store as-is
        if (preg_match('/^\d+$/', $value)) {
            $this->attributes['ip_addr'] = (string) $value;
            $this->attributes['ip_str'] = $service->fromDecimal($value)?->toString();
            return;
        }

        // Otherwise treat as textual IP and convert
        $decimal = $service->toDecimal($value);
        $this->attributes['ip_addr'] = $decimal;
        $this->attributes['ip_str'] = $value;
    }

    public function getIpStrAttribute(): ?string
    {
        $service = app(IpConversionService::class);

        $dec = $this->attributes['ip_addr'] ?? null;
        if (! $dec) {
            return $this->attributes['ip_str'] ?? null;
        }

        $addr = $service->fromDecimal((string) $dec);
        return $addr ? $addr->toString() : ($this->attributes['ip_str'] ?? null);
    }
}
