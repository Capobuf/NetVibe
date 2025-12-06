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
        'status',
        'connected_to_port_id',
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
