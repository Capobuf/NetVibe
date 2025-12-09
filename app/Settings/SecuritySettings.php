<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class SecuritySettings extends Settings
{
    /**
     * Minimum agent version allowed to talk with this server.
     * Used to emit 426 Upgrade Required when the agent is too old.
     */
    public string $min_agent_version = '1.0.0';

    /**
     * Minimum supported schema_version for device_result payloads.
     */
    public string $min_supported_schema_version = '1.0.0';

    /**
     * When true, agents are expected to verify TLS certificates when
     * reaching Mikrotik/REST/SNMP endpoints. Fase 1 può mantenerlo false
     * per facilitare ambienti di test.
     */
    public bool $agent_tls_verify = false;

    /**
     * Hard limit for agent payload size (bytes) after compression.
     * Coerente con il limite PDL di ~20MB post-compress.
     */
    public int $max_agent_payload_bytes = 20_000_000;

    public static function group(): string
    {
        return 'security';
    }
}

