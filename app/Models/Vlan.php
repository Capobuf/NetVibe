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

    public function blacklist()
    {
        // Match by VLAN number so blacklist entries can exist before VLAN import
        // and survive VLAN deletion.
        return $this->hasOne(VlanBlacklist::class, 'vlan_number', 'number')
            ->whereColumn('customer_id', 'vlans.customer_id');
    }

    public function getIsBlacklistedAttribute(): bool
    {
        if ($this->relationLoaded('blacklist')) {
            return $this->blacklist !== null;
        }

        if (! $this->customer_id) {
            return false;
        }

        return VlanBlacklist::where('customer_id', $this->customer_id)
            ->where('vlan_number', (int) $this->number)
            ->exists();
    }
    // l2_domain no longer used as tenancy; VLANs are scoped by customer
}
