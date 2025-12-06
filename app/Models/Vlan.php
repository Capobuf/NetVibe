<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantAware;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vlan extends Model
{
    use HasFactory;
    use TenantAware;
    protected $table = 'vlans';

    protected $fillable = ['name', 'number', 'description', 'customer_id'];
    
    public function customer() { return $this->belongsTo(Customer::class); }
    public function subnets() { return $this->hasMany(Subnet::class); }
    // l2_domain no longer used as tenancy; VLANs are scoped by customer
}
