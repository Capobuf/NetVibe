<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $site_name = 'NetVibe';

    public string $site_color = '#0ea5a4';

    /**
     * Application URL used for URL generation and redirects
     * Defaults to http://localhost to preserve backwards compatibility
     */
    public string $app_url = '';

    /**
     * When enabled, force generated URLs to use the HTTPS scheme.
     */
    public bool $force_https = false;

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
