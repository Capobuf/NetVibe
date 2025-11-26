<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subnet extends Model
{
    protected $fillable = [
        'subnet', 'mask', 'section_id', 'description', 'linked_subnet_id',
        'firewall_address_object', 'vrf_id', 'master_subnet_id', 'allow_requests',
        'vlan_id', 'show_name', 'device_id', 'permissions', 'ping_subnet',
        'discover_subnet', 'resolve_dns', 'dns_recursive', 'dns_records',
        'nameserver_id', 'scan_agent_id', 'customer_id', 'location_id',
        'is_folder', 'is_full', 'is_pool', 'state', 'threshold',
        'last_scan', 'last_discovery',
    ];

    protected $casts = [
        'allow_requests' => 'boolean',
        'show_name' => 'boolean',
        'ping_subnet' => 'boolean',
        'discover_subnet' => 'boolean',
        'resolve_dns' => 'boolean',
        'dns_recursive' => 'boolean',
        'dns_records' => 'boolean',
        'is_folder' => 'boolean',
        'is_full' => 'boolean',
        'is_pool' => 'boolean',
        'last_scan' => 'datetime',
        'last_discovery' => 'datetime',
    ];

    // Relationships
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function vlan()
    {
        return $this->belongsTo(Vlan::class);
    }

    public function vrf()
    {
        return $this->belongsTo(Vrf::class);
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
