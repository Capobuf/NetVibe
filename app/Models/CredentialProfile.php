<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CredentialProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'description',
        'username',
        'auth_password',
        'auth_credentials',
        'customer_id',
    ];

    protected $casts = [
        // store auth_password in encrypted column and any JSON credentials as encrypted array
        'auth_password' => 'encrypted',
        'auth_credentials' => 'encrypted:array',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
