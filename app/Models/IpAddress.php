<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['address', 'description', 'hostname', 'mac_address'])]
class IpAddress extends Model
{
    protected function casts(): array
    {
        return ['address' => 'integer'];
    }
}
