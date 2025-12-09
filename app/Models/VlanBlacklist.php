<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantAware;
use Illuminate\Database\Eloquent\Model;

class VlanBlacklist extends Model
{
    use TenantAware;

    protected $fillable = [
        'customer_id',
        'vlan_id',
        'reason',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function vlan()
    {
        return $this->belongsTo(Vlan::class);
    }
}

