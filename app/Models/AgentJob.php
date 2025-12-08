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
        'device_id',
        'scan_agent_id',
        'type',
        'status',
        'payload',
        'output',
        'result',
        'attempts',
        'scheduled_at',
        'started_at',
        'finished_at',
        'initiated_by_user_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
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
