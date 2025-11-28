<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vrf extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'vrfs';

    protected $fillable = [
        'customer_id',
        'name',
        'rd',
        'description',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
