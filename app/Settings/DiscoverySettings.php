<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class DiscoverySettings extends Settings
{
    /**
     * Default scan interval in minutes for subnets that do not override the global value
     */
    public int $default_scan_interval_minutes = 60;

    /**
     * Enable auto-discovery globally (can be overridden per subnet)
     */
    public bool $auto_discovery_enabled = true;

    /**
     * ICMP timeout in milliseconds for the scanner
     */
    public int $icmp_timeout_ms = 200;

    /**
     * Number of ICMP retries for the scanner
     */
    public int $icmp_retries = 1;

    /**
     * Agent logs retention in days
     */
    public int $prune_logs_days = 3;

    public static function group(): string
    {
        return 'discovery';
    }
}
