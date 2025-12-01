<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $site_name = 'Modern IPAM';

    public string $site_color = '#0ea5a4';

    /**
     * Agent critical alert level threshold
     */
    public string $agent_alert_level = 'ERROR';

    /**
     * How to display timestamps in UI (relative vs precise)
     */
    public string $time_display_format = 'relative';

    public static function group(): string
    {
        return 'general';
    }
}
