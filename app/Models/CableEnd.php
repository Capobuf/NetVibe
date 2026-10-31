<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['side', 'device_id', 'connector_id'])]
class CableEnd extends Model
{
    public $timestamps = false;
}
