<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $site_name = 'Modern IPAM';

    public string $site_color = '#0ea5a4';

    public static function group(): string
    {
        return 'general';
    }
}
