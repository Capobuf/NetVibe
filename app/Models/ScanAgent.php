<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

class ScanAgent extends Model implements Authenticatable
{

    use HasApiTokens;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name', 'description', 'ip_address', 'active', 'last_seen', 'customer_id',
        'settings', 'polling_interval_minutes', 'log_level',
    ];

    protected $casts = [
        'active' => 'boolean',
        'last_seen' => 'datetime',
        'settings' => 'array',
        'polling_interval_minutes' => 'integer',
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

    public function agentJobs()
    {
        return $this->hasMany(AgentJob::class);
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

    protected static function booted(): void
    {
        static::saving(function (ScanAgent $agent) {
            // Fast-fail: ScanAgents are always dedicated to a specific Customer.
            // NetVibe runs 1 agent per customer (outbound-only).
            if (blank($agent->customer_id)) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Il Remote Agent deve essere associato a un Cliente.',
                ]);
            }

            if (! $agent->active) {
                return;
            }

            $exists = static::query()
                ->where('active', true)
                ->where('customer_id', $agent->customer_id)
                ->when($agent->exists, fn ($q) => $q->where('id', '!=', $agent->id))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Esiste già un agent attivo per questo cliente.',
                ]);
            }
        });
    }
}
