<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanAgent extends Model
{
    protected $fillable = [
        'name', 'description', 'type', 'code', 'last_access',
    ];

    protected $casts = [
        'last_access' => 'datetime',
    ];

    // Relationships
    public function subnets()
    {
        return $this->hasMany(Subnet::class);
    }
}
