<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanAgent extends Model
{
    protected $fillable = [
        'name', 'description', 'type', 'code', 'last_access', 'customer_id',
    ];

    protected $casts = [
        'last_access' => 'datetime',
    ];

    // Relationships
    public function subnets()
    {
        return $this->hasMany(Subnet::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
