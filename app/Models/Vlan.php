<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vlan extends Model
{
    protected $fillable = ['domain_id', 'name', 'number', 'description', 'customer_id'];
    
    public function customer() { return $this->belongsTo(Customer::class); }
    public function subnets() { return $this->hasMany(Subnet::class); }
    public function l2Domain() { return $this->belongsTo(\App\Models\L2Domain::class, 'domain_id'); }
}
