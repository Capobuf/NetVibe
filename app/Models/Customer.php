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

    public function vrfs()
    {
        return $this->hasMany(Vrf::class);
    }

    protected static function booted()
    {
        static::deleting(function ($customer) {
            if ($customer->isForceDeleting()) {
                $customer->locations()->withTrashed()->forceDelete();
                $customer->vrfs()->withTrashed()->forceDelete();
            } else {
                $customer->locations()->delete();
                $customer->vrfs()->delete();
            }
        });
    }
}
