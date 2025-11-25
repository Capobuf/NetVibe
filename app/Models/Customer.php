<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['title', 'address', 'postcode', 'city', 'state', 'lat', 'long', 'contact_person', 'contact_phone', 'contact_mail', 'note', 'status'];
    
    public function subnets() { return $this->hasMany(Subnet::class); }
    public function ipAddresses() { return $this->hasMany(IpAddress::class); }
    public function vlans() { return $this->hasMany(Vlan::class); }
    public function vrfs() { return $this->hasMany(Vrf::class); }
}
