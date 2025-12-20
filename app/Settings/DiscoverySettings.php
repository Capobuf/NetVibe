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
     * How often agents should poll the server for new tasks/config (in minutes)
     */
    public int $agent_polling_interval_minutes = 5;

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

    /**
     * Default REST timeout (ms) used by agents when talking to Mikrotik/REST.
     */
    public int $timeout_rest_ms = 8000;

    /**
     * Default SNMP timeout (ms) for SNMP Fase 1.
     */
    public int $timeout_snmp_ms = 5000;

    /**
     * Max records per dataset before marking a payload as partial.
     */
    public int $max_records_per_dataset = 5000;

    /**
     * Enable inventory lifecycle management (soft-retire).
     */
    public bool $inventory_soft_retire_enabled = true;

    /**
     * Mark objects as STALE when last seen older than this many days.
     */
    public int $inventory_stale_after_days = 15;

    /**
     * Mark objects as RETIRED when last seen older than this many days.
     * Must be >= inventory_stale_after_days.
     */
    public int $inventory_retire_after_days = 45;

    public static function group(): string
    {
        return 'discovery';
    }
}