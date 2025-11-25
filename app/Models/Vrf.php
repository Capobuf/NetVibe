<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vrf extends Model
{
    protected $fillable = ['name', 'rd', 'description', 'sections', 'customer_id'];
    
    public function customer() { return $this->belongsTo(Customer::class); }
    public function subnets() { return $this->hasMany(Subnet::class); }
}
