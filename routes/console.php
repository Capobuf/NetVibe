<?php

use App\Models\MikrotikConnection;
use App\Models\SnipeItSetting;
use App\Models\UnifiConnection;
use App\Support\MikrotikSync;
use App\Support\SnipeItSync;
use App\Support\UnifiSync;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('snipe-it:sync', function (SnipeItSync $synchronizer) {
    $failed = false;
    // The command runs every 15 minutes; the margin keeps a sync finished a few seconds late from slipping a whole slot.
    $due = now()->subMinutes(SnipeItSetting::forCompany(null)->sync_interval)->addMinutes(5);
    foreach (App\Models\SnipeItSync::whereNotNull('activated_at')
        ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<=', $due))
        ->with('company')->lazyById() as $sync) {
        if (! $sync->isActive()) {
            continue;
        }
        try {
            $synchronizer->refresh($sync->company);
        } catch (ValidationException $e) {
            $failed = true;
            $this->error('Azienda '.$sync->company_id.': '.collect($e->errors())->flatten()->first());
        }
    }

    return $failed ? 1 : 0;
})->purpose('Sincronizza le aziende Snipe-IT attivate');

Schedule::command('snipe-it:sync')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('mikrotik:sync', function (MikrotikSync $synchronizer) {
    $failed = false;
    foreach (MikrotikConnection::whereNotNull('activated_at')->lazyById() as $connection) {
        try {
            $synchronizer->refresh($connection);
        } catch (ValidationException $exception) {
            $failed = true;
            $this->error('Dispositivo '.$connection->device_id.': '.collect($exception->errors())->flatten()->first());
        }
    }

    return $failed ? 1 : 0;
})->purpose('Sincronizza i dispositivi MikroTik attivati');

Schedule::command('mikrotik:sync')->hourly()->withoutOverlapping();

Artisan::command('unifi:sync', function (UnifiSync $synchronizer) {
    $failed = false;
    foreach (UnifiConnection::where('enabled', true)->whereHas('unifiSites', fn ($query) => $query
        ->whereNotNull('inventory_activated_at')->whereNotNull('site_id')->where('available', true))->lazyById() as $connection) {
        try {
            $synchronizer->refresh($connection, activatedOnly: true);
        } catch (ValidationException $exception) {
            $failed = true;
            $this->error('Connessione '.$connection->id.': '.collect($exception->errors())->flatten()->first());
        }
    }

    return $failed ? 1 : 0;
})->purpose('Sincronizza le sedi UniFi attivate');

Schedule::command('unifi:sync')->hourly()->withoutOverlapping();
