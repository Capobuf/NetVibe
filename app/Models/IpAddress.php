<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\IpConversionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\TenantAware;
use App\Models\Concerns\HasLifecycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\IpAddressType;
use App\Enums\LifecycleStatus;
use Illuminate\Validation\ValidationException;

class IpAddress extends Model
{
    use SoftDeletes, HasFactory;
    use TenantAware;
    use HasLifecycle;
    protected $fillable = [
        'subnet_id', 'ip_addr', 'description', 'hostname',
        'mac_address', 'owner', 'state', 'switch_id', 'location_id', 'port',
        'note', 'last_seen', 'exclude_ping', 'ptr_ignore', 'ptr',
        'firewall_address_object', 'customer_id',
        'type', 'device_id',
    ];

    protected $casts = [
        'ip_addr' => 'string',
        'type' => IpAddressType::class,
        'exclude_ping' => 'boolean',
        'ptr_ignore' => 'boolean',
        'last_seen' => 'datetime',
        'lifecycle_status' => LifecycleStatus::class,
        'stale_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (IpAddress $ip): void {
            // Enforce tenancy invariants at the model layer (fast-fail).
            // ip_addresses.customer_id MUST be present and MUST match subnets.customer_id.
            if (blank($ip->subnet_id)) {
                return;
            }

            $subnetCustomerId = Subnet::query()->whereKey($ip->subnet_id)->value('customer_id');

            if (blank($subnetCustomerId)) {
                throw ValidationException::withMessages([
                    'subnet_id' => 'La Subnet selezionata deve essere associata a un Cliente.',
                ]);
            }

            if (blank($ip->customer_id)) {
                $ip->customer_id = (int) $subnetCustomerId;
                return;
            }

            if ((int) $ip->customer_id !== (int) $subnetCustomerId) {
                throw ValidationException::withMessages([
                    'customer_id' => 'customer_id deve coincidere con il Cliente della Subnet.',
                ]);
            }
        });
    }

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
