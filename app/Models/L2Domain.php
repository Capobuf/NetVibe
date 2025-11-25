<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class L2Domain extends Model
{
    protected $fillable = ['name', 'description'];

    public function vlans()
    {
        return $this->hasMany(Vlan::class, 'domain_id');
    }
}
