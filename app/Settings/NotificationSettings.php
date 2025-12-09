<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class NotificationSettings extends Settings
{
    // Webhook Fase 1
    public bool $webhook_enabled = false;
    public ?string $webhook_url = null;

    // Telegram Fase 2 (predisposizione)
    public bool $telegram_enabled = false;
    public ?string $telegram_bot_token = null;
    public ?string $telegram_chat_id = null;

    // Filtering & routing
    public array $notify_on = [
        'agent_offline' => true,
        'agent_online' => false,
        'discovery_error' => true,
        'discovery_conflict' => true,
        'backup_completed' => true,
        'backup_failed' => true,
    ];

    // Rate limiting (seconds)
    public int $rate_limit_seconds = 300; // default 5 minutes

    public static function group(): string
    {
        return 'notifications';
    }
}
