<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Port extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'device_id',
        'name',
        'type',
        'mac_address',
        'last_seen',
        'discovered_by_scan_agent_id',
        'exclude_from_discovery',
        'status',
        'vlan_mode',
        'pvid',
        'tagged_vlans',
        'mtu',
        'speed',
        'duplex',
        'external_type',
        'description',
        'connected_to_port_id',
    ];

    protected $casts = [
        'last_seen' => 'datetime',
        'exclude_from_discovery' => 'boolean',
        'tagged_vlans' => 'array',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function connectedTo()
    {
        return $this->belongsTo(Port::class, 'connected_to_port_id');
    }

    public function linksAsA()
    {
        return $this->hasMany(Link::class, 'port_a_id');
    }

    public function linksAsB()
    {
        return $this->hasMany(Link::class, 'port_b_id');
    }
}
