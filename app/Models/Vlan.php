<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vlan extends Model
{
    use HasFactory;
    protected $table = 'vlans';

    // use explicit l2_domain_id (clearer than domain_id)
    protected $fillable = ['l2_domain_id', 'name', 'number', 'description', 'customer_id'];
    
    public function customer() { return $this->belongsTo(Customer::class); }
    public function subnets() { return $this->hasMany(Subnet::class); }
    public function l2Domain() { return $this->belongsTo(\App\Models\L2Domain::class, 'l2_domain_id'); }
}
