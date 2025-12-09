<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLog extends Model
{
    protected $fillable = [
        'scan_agent_id',
        'tenant_id',
        'level',
        'message',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scanAgent(): BelongsTo
    {
        return $this->belongsTo(ScanAgent::class);
    }

    public function tenant(): BelongsTo
    {
        // In questo dominio il tenant coincide con il Customer.
        return $this->belongsTo(Customer::class, 'tenant_id');
    }
}
