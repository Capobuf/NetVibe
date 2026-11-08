<?php

namespace App\Http\Controllers;

use App\Exceptions\MikrotikException;
use App\Models\Company;
use App\Models\Device;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyIntegrationController extends Controller
{
    /** The company integrations tab opens the first integration the user can manage. */
    public function show(Request $request, Company $company): RedirectResponse
    {
        return $request->user()->is_superadmin
            ? to_route('companies.settings.integrations.snipe-it.records', $company)
            : to_route('companies.settings.integrations.unifi.index', $company);
    }

    /** MikroTik gateways are configured on devices; this lists them for the whole installation or one company. */
    public function mikrotik(?Company $company = null): Response
    {
        $devices = Device::query()->when($company, fn ($query) => $query->whereHas('site', fn ($query) => $query->where('company_id', $company->id)));

        return Inertia::render('integrations/mikrotik', [
            'company' => $company?->only('id', 'name', 'observation_retention_days'),
            'gateways' => (clone $devices)->whereHas('mikrotikConnection')->with('site.company', 'mikrotikConnection')->orderBy('name')->get()
                ->map(fn (Device $device) => [
                    ...$this->device($device),
                    'activated_at' => $device->mikrotikConnection->activated_at,
                    'synced_at' => $device->mikrotikConnection->synced_at,
                    'error' => $device->mikrotikConnection->sync_status && $device->mikrotikConnection->sync_status !== 'ok'
                        ? MikrotikException::messageFor($device->mikrotikConnection->sync_status) : null,
                    'problems' => $device->mikrotikSources()->where('state', 'current')->whereNotNull('problem')->whereNull('excluded_at')->count(),
                ])->sortBy('company.name')->values(),
            // MikroTik devices that could be connected but are not yet.
            'candidates' => (clone $devices)->whereDoesntHave('mikrotikConnection')
                ->whereHas('manufacturer', fn ($query) => $query->where('name', 'MikroTik'))->with('site.company')->orderBy('name')->get()
                ->map(fn (Device $device) => $this->device($device))->sortBy('company.name')->values(),
        ]);
    }

    /** Retention of temporary observations, independent from the sync frequency. */
    public function retention(Request $request, Company $company): RedirectResponse
    {
        $company->update($request->validate(['observation_retention_days' => ['required', 'integer', 'between:1,365']], [
            'observation_retention_days.*' => 'Inserisci un numero di giorni da 1 a 365.',
        ]));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Conservazione delle osservazioni aggiornata.']);

        return back();
    }

    private function device(Device $device): array
    {
        return [
            ...$device->only('id', 'name', 'site_id'),
            'site' => $device->site->name,
            'company' => $device->site->company->only('id', 'name'),
        ];
    }
}
