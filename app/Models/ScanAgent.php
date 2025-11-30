<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\Authenticatable;

class ScanAgent extends Model implements Authenticatable
{

    use HasApiTokens;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name', 'description', 'ip_address', 'active', 'last_seen', 'customer_id',
    ];

    protected $casts = [
        'active' => 'boolean',
        'last_seen' => 'datetime',
    ];

    // Relationships
    public function subnets()
    {
        return $this->hasMany(Subnet::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function agentLogs()
    {
        return $this->hasMany(AgentLog::class, 'scan_agent_id');
    }

    // Authenticatable interface methods
    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->getKey();
    }

    public function getAuthPassword()
    {
        return null; // Agents use API tokens, not passwords
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value)
    {
        // Not applicable for API tokens
    }

    public function getRememberTokenName()
    {
        return null;
    }

    public function getAuthPasswordName()
    {
        return null;
    }
}
