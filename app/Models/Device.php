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
            // Se non è stato impostato esplicitamente un customer, prova a inferirlo
            // da relazioni già note (location, rack). La scelta del tenant corrente
            // non viene più ricavata dalla sessione qui: in UI l'id cliente è
            // sempre passato esplicitamente tramite i form Filament.
            if (empty($device->customer_id)) {
                // Try to infer from provided location_id
                if (! empty($device->location_id)) {
                    $loc = \App\Models\Location::find($device->location_id);
                    if (! empty($loc?->customer_id)) {
                        $device->customer_id = $loc->customer_id;
                    }
                }

                // Fallback: infer from associated rack if present
                if (empty($device->customer_id) && ! empty($device->rack_id)) {
                    $rack = \App\Models\Rack::with('location')->find($device->rack_id);
                    if (! empty($rack?->customer_id)) {
                        $device->customer_id = $rack->customer_id;
                    } elseif (! empty($rack?->location?->customer_id)) {
                        $device->customer_id = $rack->location->customer_id;
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
    }

    protected $fillable = [
        'hostname',
        'serial_number',
        'device_model_id',
        'description',
        'rack_id',
        'rack_position',
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
        'last_seen_rest' => 'datetime',
        'last_seen_snmp' => 'datetime',
        'last_seen_icmp' => 'datetime',
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

    public function snmpSettings()
    {
        return $this->hasOne(SnmpSettings::class);
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

    /**
     * Computed accessor che restituisce l'ultimo "seen" complessivo per il device.
     * PDL: last_seen_overall è virtuale, derivato dai canali disponibili.
     */
    public function getLastSeenOverallAttribute(): ?\Illuminate\Support\Carbon
    {
        $candidates = array_filter([
            $this->last_seen_rest,
            $this->last_seen_snmp,
            $this->last_seen_icmp,
            // compatibilità con il campo legacy last_seen_at
            $this->last_seen_at,
        ]);

        if (empty($candidates)) {
            return null;
        }

        return collect($candidates)->max();
    }
}
