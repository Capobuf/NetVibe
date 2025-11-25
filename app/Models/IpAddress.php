<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpAddress extends Model
{
    protected $fillable = [
        'subnet_id', 'ip_addr', 'is_gateway', 'description', 'hostname',
        'mac', 'owner', 'state', 'switch_id', 'location_id', 'port',
        'note', 'last_seen', 'exclude_ping', 'ptr_ignore', 'ptr',
        'firewall_address_object', 'customer_id',
    ];

    protected $casts = [
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
}
