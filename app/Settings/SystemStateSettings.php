<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class SystemStateSettings extends Settings
{
    /**
     * True quando le migrazioni iniziali sono state eseguite correttamente
     * e l'applicazione è installata.
     */
    public bool $installed = false;

    /**
     * True quando il wizard di configurazione di base è stato completato
     * (app_url, primo customer/location, queue driver, ecc.).
     */
    public bool $configured = false;

    /**
     * Timestamp ISO8601 dell'ultima esecuzione nota dello scheduler.
     */
    public ?string $last_scheduler_run_at = null;

    /**
     * Timestamp ISO8601 dell'ultimo heartbeat noto del worker.
     */
    public ?string $last_worker_heartbeat_at = null;

    /**
     * Timestamp ISO8601 dell'ultimo health check eseguito (System Health page).
     */
    public ?string $last_health_check_at = null;

    public static function group(): string
    {
        return 'system_state';
    }
}

