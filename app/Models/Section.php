<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    protected $fillable = ['name', 'description', 'master_section_id', 'permissions', 'strict_mode', 'subnet_ordering', 'order', 'show_subnet', 'show_vlan', 'show_vrf', 'show_supernet_only', 'dns'];
    
    protected $casts = ['strict_mode' => 'boolean', 'show_subnet' => 'boolean', 'show_vlan' => 'boolean', 'show_vrf' => 'boolean', 'show_supernet_only' => 'boolean'];
    
    public function subnets() { return $this->hasMany(Subnet::class); }
}
