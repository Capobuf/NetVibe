<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class FeatureFlagSettings extends Settings
{
    /**
     * Enable SNMP Fase 1 (device-level, MIB-II minimal).
     */
    public bool $snmp_fase1 = true;

    /**
     * Enable chunking for large agent payloads.
     */
    public bool $chunking = true;

    /**
     * Enable gzip compression for agent payloads.
     */
    public bool $gzip = true;

    public static function group(): string
    {
        return 'feature_flags';
    }
}

