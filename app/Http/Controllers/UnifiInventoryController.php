<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Device;
use App\Models\Site;
use App\Models\UnifiConnection;
use App\Models\UnifiDevice;
use App\Models\UnifiSite;
use App\Support\UnifiInventory;
use App\Support\UnifiIpam;
use App\Support\UnifiSync;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UnifiInventoryController extends Controller
{
    public function refresh(Request $request, UnifiConnection $unifiConnection, UnifiSync $sync): RedirectResponse
    {
        Gate::authorize('administer');
        $data = $request->validate(['revision' => ['required', 'uuid']]);
        if ($data['revision'] !== $unifiConnection->revision) {
            $this->invalid('La connessione è cambiata. Ricarica la pagina.');
        }
        $sync->refresh($unifiConnection);

        return $this->saved('Lettura UniFi aggiornata.');
    }

    public function activate(Request $request, UnifiConnection $unifiConnection, UnifiInventory $inventory): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        $this->locked($request, $unifiConnection, function (UnifiConnection $connection) use ($inventory) {
            $sites = $connection->unifiSites()->whereNotNull('site_id')->whereNotNull('confirmed_at')->where('available', true)->lockForUpdate()->get();
            if ($sites->isEmpty()) {
                $this->invalid('Collega prima una sede e aggiorna l’anteprima.');
            }
            foreach ($sites as $site) {
                if ($site->sync_status !== 'ok' || ! $site->last_synced_at) {
                    $this->invalid('Aggiorna l’anteprima prima di attivare l’importazione.');
                }
                Company::whereHas('sites', fn ($query) => $query->whereKey($site->site_id))->lockForUpdate()->firstOrFail();
                Site::whereKey($site->site_id)->lockForUpdate()->firstOrFail();
                $inventory->activate($site);
                app(UnifiIpam::class)->activate($site);
            }
        });

        return $this->saved('Importazione UniFi attivata.');
    }

    public function resolve(Request $request, UnifiConnection $unifiConnection, UnifiDevice $unifiDevice, UnifiInventory $inventory): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['create', 'link', 'ignore'])], 'device_id' => ['nullable', 'required_if:action,link', 'integer'], 'confirmed_relink' => ['sometimes', 'boolean']]);
        $this->locked($request, $unifiConnection, function (UnifiConnection $connection) use ($unifiDevice, $inventory, $data) {
            $source = $this->source($connection, $unifiDevice);
            if ($source->device_id) {
                $this->invalid('Il dispositivo è già collegato.');
            }
            if ($data['action'] === 'ignore') {
                $source->update(['status' => 'ignored']);
            } else {
                $device = $data['action'] === 'link' ? Device::where('site_id', $source->unifiSite->site_id)->lockForUpdate()->findOrFail($data['device_id']) : null;
                $inventory->link($source, $device, (bool) ($data['confirmed_relink'] ?? false));
            }
        });

        return $this->saved('Scelta salvata.');
    }

    public function adopt(Request $request, UnifiConnection $unifiConnection, UnifiDevice $unifiDevice, UnifiInventory $inventory): RedirectResponse
    {
        $data = $request->validate(['field' => ['required', Rule::in(['name', 'model'])], 'action' => ['required', Rule::in(['update', 'keep'])]]);
        $this->locked($request, $unifiConnection, fn ($connection) => $inventory->adopt($this->source($connection, $unifiDevice), $data['field'], $data['action'] === 'update'));

        return $this->saved('Dato aggiornato.');
    }

    public function port(Request $request, UnifiConnection $unifiConnection, UnifiDevice $unifiDevice, UnifiInventory $inventory): RedirectResponse
    {
        $data = $request->validate(['idx' => ['required', 'integer', 'min:1'], 'device_interface_id' => ['present', 'nullable', 'integer']]);
        $this->locked($request, $unifiConnection, fn ($connection) => $inventory->mapPort($this->source($connection, $unifiDevice), $data['idx'], $data['device_interface_id']));

        return $this->saved('Porta associata.');
    }

    private function source(UnifiConnection $connection, UnifiDevice $source): UnifiDevice
    {
        $source = UnifiDevice::whereHas('unifiSite', fn ($query) => $query->where('unifi_connection_id', $connection->id)
            ->whereNotNull('site_id')->whereNotNull('confirmed_at')->where('available', true))->lockForUpdate()->findOrFail($source->id);
        $site = UnifiSite::lockForUpdate()->findOrFail($source->unifi_site_id);
        $source->setRelation('unifiSite', $site);
        if ($site->sync_status !== 'ok' || ! $source->present) {
            $this->invalid('Aggiorna la lettura prima di modificare il collegamento.');
        }
        Company::whereHas('sites', fn ($query) => $query->whereKey($site->site_id))->lockForUpdate()->firstOrFail();
        Site::whereKey($site->site_id)->lockForUpdate()->firstOrFail();
        if ($source->device_id) {
            Device::whereKey($source->device_id)->lockForUpdate()->firstOrFail();
        }

        return $source;
    }

    private function locked(Request $request, UnifiConnection $connection, Closure $action): void
    {
        Gate::authorize('administer');
        $data = $request->validate(['revision' => ['required', 'uuid']]);
        DB::transaction(function () use ($connection, $data, $action) {
            $current = UnifiConnection::lockForUpdate()->findOrFail($connection->id);
            if ($current->revision !== $data['revision'] || ! $current->enabled) {
                $this->invalid('La connessione è cambiata o sospesa. Ricarica la pagina.');
            }
            $action($current);
            $current->update(['revision' => (string) Str::uuid()]);
        }, 3);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['inventory' => $message]);
    }

    private function saved(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
