<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\IpConversionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subnet extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $fillable = [
        'subnet', 'mask', 'cidr', 'name', 'vrf_id', 'section_id', 'description', 'linked_subnet_id',
        'firewall_address_object', 'master_subnet_id', 'allow_requests',
        'vlan_id', 'show_name', 'device_id', 'permissions', 'ping_subnet',
        'discover_subnet', 'resolve_dns', 'dns_recursive', 'dns_records',
        'nameserver_id', 'scan_agent_id', 'customer_id', 'location_id',
        'is_folder', 'is_full', 'is_pool', 'state', 'threshold', 'scan_interval',
        'last_scan', 'last_discovery',
    ];

    protected $casts = [
        'subnet' => 'string',
        'allow_requests' => 'boolean',
        'show_name' => 'boolean',
        'ping_subnet' => 'boolean',
        'discover_subnet' => 'boolean',
        'resolve_dns' => 'boolean',
        'dns_recursive' => 'boolean',
        'dns_records' => 'boolean',
        'is_folder' => 'boolean',
        'scan_interval' => 'integer',
        'is_full' => 'boolean',
        'is_pool' => 'boolean',
        'last_scan' => 'datetime',
        'last_discovery' => 'datetime',
    ];

    // Allow setting via CIDR string (e.g. 192.168.1.0/24)
    public function setCidrAttribute(string $cidr): void
    {
        $service = app(IpConversionService::class);

        $parsed = $service->parseCidr($cidr);

        // store as decimal string
        $this->attributes['subnet'] = (string) $parsed['subnet'];
        $this->attributes['mask'] = (int) $parsed['mask'];
        $this->attributes['cidr'] = $cidr;
    }

    public function getCidrAttribute(): ?string
    {
        if (empty($this->attributes['subnet']) || empty($this->attributes['mask'])) {
            return null;
        }

        $service = app(IpConversionService::class);
        return $service->toCidr((string) $this->attributes['subnet'], (int) $this->attributes['mask']);
    }

    // Relationships
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function vrf()
    {
        return $this->belongsTo(Vrf::class);
    }

    public function vlan()
    {
        return $this->belongsTo(Vlan::class);
    }

    public function scanAgent()
    {
        return $this->belongsTo(ScanAgent::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function ipAddresses()
    {
        return $this->hasMany(IpAddress::class);
    }

    public function childSubnets()
    {
        return $this->hasMany(Subnet::class, 'master_subnet_id');
    }

    public function parentSubnet()
    {
        return $this->belongsTo(Subnet::class, 'master_subnet_id');
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
