<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CableType extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name',
        'color',
        'category',
        'description',
    ];

    public function links()
    {
        return $this->hasMany(Link::class, 'cable_type_id');
    }
}
