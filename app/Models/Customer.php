<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'contact_email',
        'address',
        'notes',
    ];

    public function locations()
    {
        return $this->hasMany(Location::class);
    }

    // VRF model removed — tenancy now handled by Subnet / Vlan customer_id

    protected static function booted()
    {
        static::deleting(function ($customer) {
            // When dropping VRF/L2Domain concepts we now own subnets, vlans and ip_addresses by customer
            if ($customer->isForceDeleting()) {
                $customer->locations()->withTrashed()->forceDelete();
                $customer->subnets()->withTrashed()->forceDelete();
                $customer->vlans()->withTrashed()->forceDelete();
                $customer->ipAddresses()->withTrashed()->forceDelete();
            } else {
                $customer->locations()->delete();
                $customer->subnets()->delete();
                $customer->vlans()->delete();
                $customer->ipAddresses()->delete();
            }
        });
    }

    public function subnets()
    {
        return $this->hasMany(Subnet::class);
    }

    public function vlans()
    {
        return $this->hasMany(Vlan::class);
    }

    public function ipAddresses()
    {
        return $this->hasMany(IpAddress::class);
    }
}
