<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $fillable = ['name', 'description', 'address', 'lat', 'long'];
    
    public function devices() { return $this->hasMany(Device::class); }
    public function subnets() { return $this->hasMany(Subnet::class); }
    public function ipAddresses() { return $this->hasMany(IpAddress::class); }
}
