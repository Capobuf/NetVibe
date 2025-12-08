<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantAware;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    use SoftDeletes;
    use TenantAware;

    protected static function booted(): void
    {
        static::creating(function (Device $device) {
            // Ensure a Device created while a tenant is active gets associated with
            // that tenant. Also fallback to infer customer from the chosen location
            // or the rack owner when possible.
            if (empty($device->customer_id)) {
                // Prefer explicit tenant session if present
                $tenant = session('tenant_id');
                if (! empty($tenant)) {
                    $device->customer_id = $tenant;
                } else {
                    // Try to infer from provided location_id
                    if (! empty($device->location_id)) {
                        $loc = \App\Models\Location::find($device->location_id);
                        if (! empty($loc->customer_id)) {
                            $device->customer_id = $loc->customer_id;
                        }
                    }

                    // Fallback: infer from associated rack if present
                    if (empty($device->customer_id) && ! empty($device->rack_id)) {
                        $rack = \App\Models\Rack::with('location')->find($device->rack_id);
                        if (! empty($rack->customer_id)) {
                            $device->customer_id = $rack->customer_id;
                        } elseif (! empty($rack->location) && ! empty($rack->location->customer_id)) {
                            $device->customer_id = $rack->location->customer_id;
                        }
                    }
                }
            }

        });

        // Existing created lifecycle: generate ports after create
        static::created(function (Device $device) {
            // fire-and-forget generation; action is idempotent
            try {
                app(\App\Actions\Physical\GenerateDevicePortsAction::class)->execute($device);
            } catch (\Throwable $e) {
                // Log but don't break create flow
                logger()->warning('GenerateDevicePortsAction failed: ' . $e->getMessage());
            }
        });

        static::saving(function (Device $device) {
            // Safety net: if the schema is not yet migrated with scan_agent_id, avoid SQL errors.
            try {
                if (! \Schema::hasColumn($device->getTable(), 'scan_agent_id')) {
                    unset($device->scan_agent_id);
                }
            } catch (\Throwable $e) {
                // ignore schema check failures
            }
        });
    }

    protected $fillable = [
        'hostname',
        'serial_number',
        'device_model_id',
        'description',
        'sections',
        'rack_id',
        'rack_position',
        'rack_size',
        'location_id',
        'customer_id',
        'scan_agent_id',
        'ip_addr',
        'credential_profile_id',
        'auth_username',
        'auth_password',
        'is_monitored',
        'auth_driver',
        'management_ip',
        'brand',
        'auth_extra',
        'auth_status',
        'last_seen_at',
    ];

    protected $casts = [
        'auth_extra' => 'encrypted:array',
        'auth_password' => 'encrypted',
        'is_monitored' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function credentialProfile()
    {
        return $this->belongsTo(CredentialProfile::class, 'credential_profile_id');
    }

    public function deviceModel()
    {
        return $this->belongsTo(DeviceModel::class);
    }

    

    public function ports()
    {
        return $this->hasMany(Port::class);
    }

    public function agentJobs()
    {
        return $this->hasMany(AgentJob::class);
    }

    public function scanAgent()
    {
        return $this->belongsTo(ScanAgent::class);
    }
    public function rack()
    {
        return $this->belongsTo(Rack::class);
    }

    public function getNameAttribute(): ?string
    {
        return $this->hostname ?? null;
    }
}
