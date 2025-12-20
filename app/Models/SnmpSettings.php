<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SnmpSettings extends Model
{
    protected $table = 'snmp_settings';

    protected $fillable = [
        'device_id',
        'enabled',
        'host',
        'port',
        'version',
        'community',
        'timeout',
        'retries',
        'port_name_priority',
        'custom_oids',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'port' => 'integer',
        'timeout' => 'integer',
        'retries' => 'integer',
        'community' => 'encrypted',
        'port_name_priority' => 'array',
        'custom_oids' => 'array',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}

