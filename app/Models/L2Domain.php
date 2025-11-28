<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class L2Domain extends Model
{
    protected $fillable = ['name', 'description'];

    // vlans are linked via l2_domain_id
    public function vlans()
    {
        return $this->hasMany(Vlan::class, 'l2_domain_id');
    }
}
