<?php

namespace App\Http\Controllers;

use App\Exceptions\MikrotikException;
use App\Exceptions\UnifiException;
use App\Models\Company;
use App\Models\Device;
use App\Models\SnipeItCompanyLink;
use App\Models\SnipeItSetting;
use App\Models\SnipeItSync;
use App\Models\UnifiConnection;
use App\Models\UnifiSite;
use Inertia\Inertia;
use Inertia\Response;

class CompanyIntegrationController extends Controller
{
    /** Installation-wide status of every integration for the administration overview. */
    public function overview(): Response
    {
        $connection = SnipeItSetting::forCompany(null)->connection;
        $syncs = SnipeItSync::with('company')->get()->filter->isActive();

        return Inertia::render('integrations/index', [
            'snipeIt' => [
                'server_url' => $connection?->server_url,
                'check_status' => $connection?->check_status,
                'checked_at' => $connection?->checked_at,
                'companies' => $syncs->map(fn (SnipeItSync $sync) => [
                    'id' => $sync->company_id, 'name' => $sync->company->name,
                    'synced_at' => $sync->synced_at, 'error' => $sync->error,
                    'pending' => $sync->assets()->forView('pending')->count(),
                ])->sortBy('name')->values(),
            ],
            'mikrotik' => Device::whereHas('mikrotikConnection')->with('site.company', 'mikrotikConnection')->orderBy('name')->get()
                ->map(fn (Device $device) => [
                    ...$device->only('id', 'name', 'site_id'),
                    'company' => $device->site->company->only('id', 'name'),
                    'synced_at' => $device->mikrotikConnection->synced_at,
                    'error' => $device->mikrotikConnection->sync_status && $device->mikrotikConnection->sync_status !== 'ok'
                        ? MikrotikException::messageFor($device->mikrotikConnection->sync_status) : null,
                ])->sortBy('company.name')->values(),
            'unifi' => [
                'connections' => UnifiConnection::count(),
                'errors' => UnifiConnection::whereNotNull('check_status')->where('check_status', '!=', 'ok')->count(),
                'sites' => UnifiSite::whereNotNull('site_id')->with('site.company')->orderBy('name')->get()
                    ->map(fn (UnifiSite $site) => [
                        ...$site->only('id', 'name', 'console_name', 'last_synced_at'),
                        'company' => $site->site->company->only('id', 'name'),
                        'error' => $site->sync_status && $site->sync_status !== 'ok' ? UnifiException::messageFor($site->sync_status) : null,
                    ]),
                'unassigned' => UnifiSite::whereNull('site_id')->count(),
            ],
        ]);
    }

    public function show(Company $company): Response
    {
        $setting = SnipeItSetting::forCompany($company);
        $connection = $setting->use_global ? SnipeItSetting::forCompany(null)->connection : $setting->connection;
        $link = SnipeItCompanyLink::where('company_id', $company->id)->latest('id')->first();
        $sync = SnipeItSync::current($company);
        $devices = Device::whereHas('site', fn ($query) => $query->where('company_id', $company->id));

        return Inertia::render('integrations/company', [
            'company' => $company->only('id', 'name'),
            'snipeIt' => [
                'connected' => $connection !== null,
                'external_company' => $link?->external_company_name,
                'confirmed' => $connection && $link?->connection_id === $connection->id && $link->confirmed_at !== null,
                'active' => $sync?->isActive() ?? false,
                'synced_at' => $sync?->synced_at,
                'error' => $sync?->error,
                'counts' => $sync?->counts(),
            ],
            'mikrotik' => (clone $devices)->whereHas('mikrotikConnection')->with('site', 'mikrotikConnection')->orderBy('name')->get()
                ->map(fn (Device $device) => [
                    ...$device->only('id', 'name', 'site_id'),
                    'site' => $device->site->name,
                    'activated_at' => $device->mikrotikConnection->activated_at,
                    'synced_at' => $device->mikrotikConnection->synced_at,
                    'error' => $device->mikrotikConnection->sync_status && $device->mikrotikConnection->sync_status !== 'ok'
                        ? MikrotikException::messageFor($device->mikrotikConnection->sync_status) : null,
                    'problems' => $device->mikrotikSources()->where('state', 'current')->whereNotNull('problem')->whereNull('excluded_at')->count(),
                ]),
            // MikroTik devices that could be connected but are not yet.
            'mikrotikCandidates' => (clone $devices)->whereDoesntHave('mikrotikConnection')
                ->whereHas('manufacturer', fn ($query) => $query->where('name', 'MikroTik'))->with('site')->orderBy('name')->get()
                ->map(fn (Device $device) => [...$device->only('id', 'name', 'site_id'), 'site' => $device->site->name]),
            'unifi' => UnifiSite::whereIn('site_id', $company->sites()->select('id'))->with('site', 'unifiConnection')->withCount([
                'unifiDevices as devices_count',
                'unifiDevices as linked_count' => fn ($query) => $query->whereNotNull('device_id'),
            ])->orderBy('name')->get()->map(fn (UnifiSite $site) => [
                ...$site->only('id', 'name', 'console_name', 'site_id', 'inventory_activated_at', 'last_synced_at', 'devices_count', 'linked_count'),
                'connection' => $site->unifiConnection->name,
                'site' => $site->site->name,
                'error' => $site->sync_status && $site->sync_status !== 'ok' ? UnifiException::messageFor($site->sync_status) : null,
            ]),
        ]);
    }
}
