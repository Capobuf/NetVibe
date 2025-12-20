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
        'vlan_number',
        'vlan_name',
        'reason',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
