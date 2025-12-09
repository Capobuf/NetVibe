<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AgentJob extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'device_id',
        'scan_agent_id',
        'type',
        'scope',
        'status',
        'payload',
        'output',
        'result',
        'result_metadata',
        'error_message',
        'attempts',
        'scheduled_at',
        'started_at',
        'finished_at',
        'initiated_by_user_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'result_metadata' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function tenant()
    {
        // In questo progetto, il tenant coincide con il Customer.
        return $this->belongsTo(Customer::class, 'tenant_id');
    }

    public function initiator()
    {
        return $this->belongsTo(\App\Models\User::class, 'initiated_by_user_id');
    }

    public function scanAgent()
    {
        return $this->belongsTo(ScanAgent::class);
    }
}
