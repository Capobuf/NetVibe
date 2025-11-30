<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScanAgent extends Model
{

    use HasApiTokens;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name', 'description', 'ip_address', 'active', 'last_seen', 'customer_id',
    ];

    protected $casts = [
        'active' => 'boolean',
        'last_seen' => 'datetime',
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
