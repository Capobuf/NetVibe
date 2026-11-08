<?php

namespace App\Http\Controllers;

use App\Exceptions\UnifiException;
use App\Http\Requests\UnifiRequest;
use App\Models\Company;
use App\Models\Site;
use App\Models\UnifiConnection;
use App\Models\UnifiSite;
use App\Support\UnifiClient;
use App\Support\UnifiInventory;
use App\Support\UnifiIpam;
use App\Support\UnifiSync;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UnifiController extends Controller
{
    /** Errors that concern the API key or the transport, not a single console. */
    private const CONNECTION_ERRORS = ['unauthorized', 'rate_limit', 'tls', 'redirect', 'unsafe_url'];

    public function index(Request $request, ?Company $company = null): Response
    {
        $company ? Gate::authorize('access-company', $company) : Gate::authorize('administer');
        $administer = $request->user()->can('administer');
        // Cloud Connectors see every customer's consoles: a company shows only its own hosts and the sites linked to it.
        $query = UnifiConnection::query()->orderBy('name')->with(['unifiSites' => fn ($query) => $query
            ->with(['site.company', 'unifiDevices.device', 'unifiConnection'])->orderBy('console_name')->orderBy('name')
            ->when($company, fn ($query) => $query->where(fn ($query) => $query
                ->whereHas('site', fn ($query) => $query->where('company_id', $company->id))
                ->orWhereHas('unifiConnection', fn ($query) => $query->where('company_id', $company->id))))]);
        $company
            ? $query->where(fn ($query) => $query->where('company_id', $company->id)
                ->orWhereHas('unifiSites.site', fn ($query) => $query->where('company_id', $company->id)))
            : $query->whereNull('company_id');
        $companies = Company::query()->with(['sites' => fn ($query) => $query->orderBy('name')])->orderBy('name')
            ->when($company, fn ($query) => $query->whereKey($company->id));

        return Inertia::render('integrations/unifi', [
            'company' => $company?->only('id', 'name', 'observation_retention_days'),
            'connections' => $query->get()->map(fn (UnifiConnection $connection) => $connection->summary(
                $administer && $connection->company_id === $company?->id, $company?->id)),
            'companies' => $administer ? $companies->get()
                ->map(fn (Company $company) => [...$company->only('id', 'name'), 'sites' => $company->sites->map->only('id', 'name')]) : [],
        ]);
    }

    public function store(UnifiRequest $request): RedirectResponse
    {
        $data = $request->connectionData();
        if (empty($data['api_key'])) {
            throw ValidationException::withMessages(['api_key' => 'Inserisci la API key.']);
        }
        try {
            UnifiConnection::create([...$data, 'revision' => (string) Str::uuid(), 'hosts' => [], 'selected_console_ids' => []]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['mode' => 'Il Cloud Connector UniFi è già configurato.']);
        }

        return $this->saved('Connessione salvata.');
    }

    public function update(UnifiRequest $request, UnifiConnection $unifiConnection): RedirectResponse
    {
        $data = $request->connectionData();
        $this->locked($request, $unifiConnection, function (UnifiConnection $connection) use ($data) {
            if ($connection->mode !== $data['mode'] || $connection->server_url !== $data['server_url'] || $connection->company_id !== $data['company_id']) {
                throw ValidationException::withMessages(['server_url' => 'Crea una nuova connessione per cambiare destinazione o metodo di accesso.']);
            }
            $keyChanged = ! empty($data['api_key']) && $data['api_key'] !== $connection->api_key;
            $securityChanged = $keyChanged || $connection->allow_unverified_certificate !== (bool) $data['allow_unverified_certificate'];
            $connection->fill(collect($data)->except(['revision', 'api_key'])->all());
            if ($keyChanged) {
                $connection->api_key = $data['api_key'];
            }
            if ($securityChanged || $connection->isDirty('enabled')) {
                $connection->fill(['checked_at' => null, 'check_status' => null]);
                $connection->unifiSites()->update(['available' => false]);
                if ($keyChanged && $connection->mode === 'cloud') {
                    $connection->hosts = [];
                }
            }
        });

        return $this->saved('Connessione salvata.');
    }

    public function destroy(Request $request, UnifiConnection $unifiConnection): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        $this->locked($request, $unifiConnection, function (UnifiConnection $connection) {
            // Linked sites move to another access of the same UniFi site, keeping their data, before the connection goes.
            foreach ($connection->unifiSites()->whereNotNull('site_id')->with('site')->lockForUpdate()->get() as $source) {
                if ($target = $source->alternatives()->first()) {
                    $source->transferTo($target);
                }
            }
            $connection->delete();
        });

        return $this->saved('Connessione rimossa.');
    }

    public function verify(Request $request, UnifiConnection $unifiConnection, UnifiClient $client): RedirectResponse
    {
        return $this->inspect($request, $unifiConnection, $client, false);
    }

    public function discover(Request $request, UnifiConnection $unifiConnection, UnifiClient $client): RedirectResponse
    {
        return $this->inspect($request, $unifiConnection, $client, true);
    }

    public function selectConsoles(Request $request, UnifiConnection $unifiConnection, UnifiClient $client): RedirectResponse
    {
        $data = $request->validate(['selected_console_ids' => ['present', 'array'], 'selected_console_ids.*' => ['required', 'string', 'distinct', 'max:255']]);
        $added = array_diff($data['selected_console_ids'], $unifiConnection->selected_console_ids ?? []);
        $this->locked($request, $unifiConnection, function (UnifiConnection $connection) use ($data) {
            if ($connection->mode !== 'cloud' || $connection->check_status !== 'ok') {
                throw ValidationException::withMessages(['connection' => 'Verifica la connessione cloud prima di scegliere le console.']);
            }
            if (array_diff($data['selected_console_ids'], array_column($connection->hosts ?? [], 'id'))) {
                throw ValidationException::withMessages(['selected_console_ids' => 'Una console non è disponibile. Verifica nuovamente la connessione.']);
            }
            $connection->selected_console_ids = $data['selected_console_ids'];
            $connection->unifiSites()->whereNotIn('console_id', $data['selected_console_ids'])->update(['available' => false]);
        });
        if ($added) {
            // A newly included console is useful only with its sites: read them right away.
            $request->merge(['revision' => $unifiConnection->fresh()->revision]);

            return $this->inspect($request, $unifiConnection->fresh(), $client, true);
        }

        return $this->saved('Console selezionate.');
    }

    public function associate(Request $request, UnifiConnection $unifiConnection, UnifiSite $unifiSite): RedirectResponse
    {
        $data = $request->validate(['site_id' => ['required', 'integer', 'exists:sites,id'], 'confirmed' => ['accepted']]);
        try {
            $this->locked($request, $unifiConnection, function (UnifiConnection $connection) use ($unifiSite, $data) {
                $source = $connection->unifiSites()->lockForUpdate()->findOrFail($unifiSite->id);
                if (! $connection->enabled || $connection->check_status !== 'ok' || ! $source->available) {
                    throw ValidationException::withMessages(['connection' => 'Completa una discovery prima di collegare il sito.']);
                }
                $site = Site::query()->lockForUpdate()->findOrFail($data['site_id']);
                if ($connection->company_id !== null && $site->company_id !== $connection->company_id) {
                    throw ValidationException::withMessages(['site_id' => 'Scegli una sede dell’azienda di questo host.']);
                }
                // A removed local site must not reserve an external identity indefinitely.
                UnifiSite::where('mapped_site_uuid', $source->external_site_id)->whereNull('site_id')
                    ->update(['mapped_site_uuid' => null, 'confirmed_at' => null]);
                if (UnifiSite::where('mapped_site_uuid', $source->external_site_id)->whereKeyNot($source->id)->exists()) {
                    throw ValidationException::withMessages(['site_id' => 'Questo sito UniFi è già collegato tramite un’altra connessione. Revoca prima quel collegamento.']);
                }
                if ($source->confirmed_at !== null && $source->site_id !== $site->id) {
                    UnifiInventory::resetForMapping($source);
                    UnifiIpam::resetForMapping($source);
                    $source->fill(['preview' => null, 'available' => false]);
                }
                $source->fill(['site_id' => $site->id, 'confirmed_at' => now(), 'mapped_site_uuid' => $source->external_site_id])->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['site_id' => 'Questo sito UniFi è già collegato tramite un’altra connessione. Ricarica la pagina.']);
        }

        if ($unifiSite->fresh()->available) {
            try {
                app(UnifiSync::class)->refresh($unifiConnection->fresh(), $unifiSite->id);
            } catch (ValidationException $exception) {
                return $this->saved('Sito collegato. '.collect($exception->errors())->flatten()->first(), 'warning');
            }
        }

        return $this->saved('Sito collegato.');
    }

    public function revoke(Request $request, UnifiConnection $unifiConnection, UnifiSite $unifiSite): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        $this->locked($request, $unifiConnection, function (UnifiConnection $connection) use ($unifiSite) {
            $source = $connection->unifiSites()->lockForUpdate()->findOrFail($unifiSite->id);
            UnifiInventory::resetForMapping($source);
            UnifiIpam::resetForMapping($source);
            $source->fill(['site_id' => null, 'confirmed_at' => null, 'mapped_site_uuid' => null, 'preview' => null, 'available' => false])->save();
        });

        return $this->saved('Collegamento revocato.');
    }

    private function inspect(Request $request, UnifiConnection $connection, UnifiClient $client, bool $discover): RedirectResponse
    {
        Gate::authorize('administer');
        $lock = Cache::lock('unifi-connection:'.$connection->id, 90);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['connection' => 'Una lettura è già in corso. Attendi e riprova.']);
        }
        try {
            $connection = $this->locked($request, $connection, function (UnifiConnection $current) {
                if (! $current->enabled) {
                    throw ValidationException::withMessages(['connection' => 'Riattiva la connessione prima di verificarla.']);
                }

                return $current;
            }, false);
            $hosts = null;
            $sources = [];
            $consoleErrors = [];
            try {
                if ($connection->mode === 'cloud') {
                    $hosts = $client->hosts($connection);
                    $connection->hosts = $hosts;
                    $selected = $connection->selected_console_ids ?? [];
                    if ($discover && $selected === []) {
                        throw ValidationException::withMessages(['connection' => 'Seleziona almeno una console.']);
                    }
                    if ($discover && array_diff($selected, array_column($hosts, 'id'))) {
                        throw new UnifiException('forbidden');
                    }
                    $consoles = array_values(array_filter($hosts, fn ($host) => in_array($host['id'], $selected, true)));
                } else {
                    $consoles = [['id' => 'direct', 'name' => $connection->name]];
                }
                if ($discover || $connection->mode === 'direct') {
                    foreach ($consoles as $console) {
                        $consoleId = $connection->mode === 'cloud' ? $console['id'] : null;
                        $found = [];
                        try {
                            $info = $client->info($connection, $consoleId);
                            if (! $discover) {
                                continue;
                            }
                            foreach ($client->sites($connection, $consoleId) as $site) {
                                $devices = $client->devices($connection, $site['id'], $consoleId);
                                try {
                                    $networks = $client->networks($connection, $site['id'], $info['applicationVersion'], $consoleId);
                                    $networksStatus = 'ok';
                                } catch (UnifiException $exception) {
                                    if ($exception->status !== 'unsupported') {
                                        throw $exception;
                                    }
                                    $networks = null;
                                    $networksStatus = 'unsupported';
                                }
                                $found[] = [
                                    'console_id' => $console['id'], 'console_name' => $console['name'], 'external_site_id' => $site['id'],
                                    'name' => $site['name'], 'version' => $info['applicationVersion'],
                                    'preview' => ['devices' => $devices, 'networks' => $networks, 'networks_status' => $networksStatus],
                                ];
                            }
                            $sources = [...$sources, ...$found];
                        } catch (UnifiException $exception) {
                            // One unreadable cloud console must not suspend the whole connection and its other consoles.
                            if ($connection->mode !== 'cloud' || in_array($exception->status, self::CONNECTION_ERRORS, true)) {
                                throw $exception;
                            }
                            $consoleErrors[$console['id']] = $exception->status;
                        }
                    }
                }
                $status = 'ok';
            } catch (UnifiException $exception) {
                $status = $exception->status;
            }
            $this->locked($request, $connection, function (UnifiConnection $current) use ($hosts, $sources, $status, $discover, $consoleErrors) {
                $current->fill(['checked_at' => now(), 'check_status' => $status]);
                if ($status !== 'ok') {
                    $current->unifiSites()->update(['available' => false]);

                    return;
                }
                if ($hosts !== null) {
                    // Console read errors are kept per console until the next discovery.
                    $previous = collect($current->hosts ?? [])->keyBy('id');
                    $current->hosts = array_map(fn ($host) => [...$host,
                        'status' => $discover ? ($consoleErrors[$host['id']] ?? null) : ($previous[$host['id']]['status'] ?? null),
                    ], $hosts);
                    $current->unifiSites()->whereNotIn('console_id', array_column($hosts, 'id'))->update(['available' => false]);
                }
                if ($discover) {
                    $current->unifiSites()->update(['available' => false]);
                    foreach ($sources as $source) {
                        $current->unifiSites()->updateOrCreate([
                            'console_id' => $source['console_id'], 'external_site_id' => $source['external_site_id'],
                        ], [...$source, 'discovered_at' => now(), 'available' => true]);
                    }
                    $current->discovered_at = now();
                    if ($current->mode === 'direct') {
                        $this->preferDirect($current);
                    }
                }
            });
        } finally {
            $lock->release();
        }

        if ($status === 'ok' && $consoleErrors) {
            return $this->saved('Siti aggiornati. Alcune console non sono accessibili.', 'warning');
        }

        return $this->saved($status === 'ok' && $discover ? 'Siti aggiornati.' : UnifiException::messageFor($status), $status === 'ok' ? 'success' : 'error');
    }

    private function locked(Request $request, UnifiConnection $connection, Closure $action, bool $save = true): mixed
    {
        Gate::authorize('administer');
        $data = $request->validate(['revision' => ['required', 'uuid']]);

        return DB::transaction(function () use ($connection, $data, $action, $save) {
            $current = UnifiConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($current->revision !== $data['revision']) {
                throw ValidationException::withMessages(['connection' => 'La connessione è cambiata. Ricarica la pagina prima di continuare.']);
            }
            $result = $action($current);
            if ($save && $current->exists) {
                $current->revision = (string) Str::uuid();
                $current->save();
            }

            return $result;
        }, 3);
    }

    /** A host of the company takes over its sites from the Cloud Connector, with the data acquired so far. */
    private function preferDirect(UnifiConnection $connection): void
    {
        foreach ($connection->unifiSites()->whereNull('site_id')->where('available', true)->get() as $direct) {
            $owner = UnifiSite::where('mapped_site_uuid', $direct->external_site_id)->whereKeyNot($direct->id)
                ->with('site', 'unifiConnection')->lockForUpdate()->first();
            if ($owner?->unifiConnection->mode === 'cloud' && $owner->site?->company_id === $connection->company_id) {
                $owner->transferTo($direct);
            }
        }
    }

    private function saved(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
