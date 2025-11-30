<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiscoveryEvent extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'scan_agent_id',
        'ip_addr',
        'mac_address_current',
        'hostname_current',
        'status',
        'conflict_type',
        'payload_raw',
    ];

    protected $casts = [
        'payload_raw' => 'array',
    ];

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_DISCARDED = 'DISCARDED';

    public function scanAgent()
    {
        return $this->belongsTo(ScanAgent::class);
    }
}
