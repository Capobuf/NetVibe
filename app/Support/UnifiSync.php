<?php

namespace App\Support;

use App\Exceptions\UnifiException;
use App\Models\Company;
use App\Models\Site;
use App\Models\UnifiConnection;
use App\Models\UnifiSite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UnifiSync
{
    public function refresh(UnifiConnection $connection, ?int $siteId = null, bool $activatedOnly = false): void
    {
        $lock = Cache::lock('unifi-connection:'.$connection->id, 90);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['connection' => 'Una lettura è già in corso. Attendi e riprova.']);
        }
        try {
            $current = $connection->fresh();
            if (! $current || $current->revision !== $connection->revision || ! $current->enabled) {
                throw ValidationException::withMessages(['connection' => 'La connessione è cambiata o sospesa. Ricarica la pagina.']);
            }
            $connection = $current;
            $sites = $connection->unifiSites()->whereNotNull('site_id')->whereNotNull('confirmed_at')->where('available', true)
                ->when($siteId, fn ($query) => $query->whereKey($siteId))
                ->when($activatedOnly, fn ($query) => $query->whereNotNull('inventory_activated_at'))->get();
            if ($sites->isEmpty()) {
                throw ValidationException::withMessages(['connection' => 'Collega prima una sede disponibile.']);
            }
            $client = app(UnifiClient::class);
            $snapshots = [];
            $status = 'ok';
            try {
                foreach ($sites as $site) {
                    $console = $connection->mode === 'cloud' ? $site->console_id : null;
                    $devices = [];
                    $ignored = $site->unifiDevices()->where('status', 'ignored')->pluck('external_id')->all();
                    foreach ($client->devices($connection, $site->external_site_id, $console) as $device) {
                        if (in_array($device['state'], ['PENDING_ADOPTION', 'ADOPTING'], true)) {
                            continue;
                        }
                        $devices[] = in_array($device['id'], $ignored, true) ? $device
                            : $client->device($connection, $site->external_site_id, $device['id'], $console);
                    }
                    try {
                        $networks = $client->networkDetails($connection, $site->external_site_id, $site->version, $console);
                    } catch (UnifiException $exception) {
                        if ($exception->status !== 'unsupported') {
                            throw $exception;
                        }
                        $networks = null;
                    }
                    $clients = $client->clients($connection, $site->external_site_id, $console);
                    $snapshots[$site->id] = compact('devices', 'networks', 'clients');
                }
            } catch (UnifiException $exception) {
                $status = $exception->status;
            }
            try {
                DB::transaction(function () use ($connection, $sites, $snapshots, $status) {
                    $current = UnifiConnection::lockForUpdate()->findOrFail($connection->id);
                    if ($current->revision !== $connection->revision || ! $current->enabled) {
                        throw ValidationException::withMessages(['connection' => 'La connessione è cambiata durante la lettura. Ricarica la pagina.']);
                    }
                    foreach ($sites as $snapshotSite) {
                        $site = UnifiSite::lockForUpdate()->findOrFail($snapshotSite->id);
                        if ($site->site_id !== $snapshotSite->site_id || $site->confirmed_at != $snapshotSite->confirmed_at || ! $site->available) {
                            throw ValidationException::withMessages(['connection' => 'Il collegamento della sede è cambiato durante la lettura.']);
                        }
                        if ($status === 'ok') {
                            Company::whereHas('sites', fn ($query) => $query->whereKey($site->site_id))->lockForUpdate()->firstOrFail();
                            Site::whereKey($site->site_id)->lockForUpdate()->firstOrFail();
                            app(UnifiInventory::class)->capture($site, $snapshots[$site->id]['devices']);
                            app(UnifiIpam::class)->capture($site, $snapshots[$site->id]['networks'], $snapshots[$site->id]['clients'], $snapshots[$site->id]['devices']);
                            $site->last_synced_at = now();
                        }
                        $site->sync_status = $status;
                        $site->save();
                    }
                    $current->update(['revision' => (string) Str::uuid()]);
                }, 3);
            } catch (UnifiException $exception) {
                $status = $exception->status;
                DB::transaction(function () use ($connection, $sites, $status) {
                    $current = UnifiConnection::lockForUpdate()->findOrFail($connection->id);
                    if ($current->revision === $connection->revision) {
                        $current->unifiSites()->whereKey($sites->modelKeys())->update(['sync_status' => $status]);
                        $current->update(['revision' => (string) Str::uuid()]);
                    }
                });
            }
            if ($status !== 'ok') {
                throw ValidationException::withMessages(['connection' => UnifiException::messageFor($status)]);
            }
        } finally {
            $lock->release();
        }
    }
}
