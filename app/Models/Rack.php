<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantAware;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rack extends Model
{
    use SoftDeletes;
    use TenantAware;

    // Keep backward-compatible fields but honour the new schema fields
    protected $fillable = [
        'name',
        'units',
        'model',
        'description',
        'customer_id',
        'location_id',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }
}
