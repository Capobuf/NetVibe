<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Location extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'name',
        'address',
    ];
    
    public function devices() { return $this->hasMany(Device::class); }
    public function subnets() { return $this->hasMany(Subnet::class); }
    public function ipAddresses() { return $this->hasMany(IpAddress::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
}
