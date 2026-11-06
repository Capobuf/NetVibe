<?php

namespace App\Http\Controllers;

use App\Exceptions\SnipeItException;
use App\Http\Requests\SnipeItRequest;
use App\Models\Company;
use App\Models\SnipeItCompanyLink;
use App\Models\SnipeItConnection;
use App\Models\SnipeItSetting;
use App\Models\SnipeItSync;
use App\Support\SnipeItClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SnipeItController extends Controller
{
    public function __construct(private SnipeItClient $client) {}

    public function edit(?Company $company = null): Response
    {
        $global = SnipeItSetting::forCompany(null);
        $setting = $company ? SnipeItSetting::forCompany($company) : $global;
        $connection = $setting->use_global ? $global->connection : $setting->connection;
        $link = $company ? SnipeItCompanyLink::where('company_id', $company->id)->latest('id')->first() : null;
        $affected = $company ? [] : Company::whereNotIn('id', SnipeItSetting::where('use_global', false)->whereNotNull('company_id')->select('company_id'))
            ->orderBy('name')->get(['id', 'name']);
        $sync = $company ? SnipeItSync::current($company) : null;

        return Inertia::render('integrations/snipe-it', [
            'company' => $company?->only('id', 'name'),
            'settings' => [
                'use_global' => $setting->use_global,
                'revision' => $setting->revision,
                'global_revision' => $global->revision,
                'own_connection' => $setting->connection?->summary(),
                'global_connection' => $global->connection?->summary(),
                'connection' => $connection?->summary(),
                'association' => $link ? [
                    ...$link->only('external_company_id', 'external_company_name', 'confirmed_at'),
                    'confirmed' => $connection && $link->connection_id === $connection->id && $link->confirmed_at !== null,
                ] : null,
            ],
            'syncInterval' => $global->sync_interval,
            'affectedCompanies' => $affected,
            'sync' => $sync ? [
                ...$sync->only('id', 'activated_at', 'synced_at', 'error'),
                'active' => $sync->isActive(),
                'counts' => $sync->counts(),
            ] : null,
        ]);
    }

    public function mapping(Company $company): Response
    {
        return $this->edit($company)->with('section', 'fields');
    }

    public function update(SnipeItRequest $request, ?Company $company = null): RedirectResponse
    {
        $data = $request->connectionData();
        $this->locked($request, $company, function ($setting, $global) use ($data, $company) {
            $previous = $setting->use_global ? $global->connection : $setting->connection;
            $useGlobal = $company !== null && $data['use_global'];
            if (! $useGlobal) {
                $own = $setting->connection;
                $sameServer = $own && $own->server_url === $data['server_url'];
                if (! $company && $own && ! $sameServer && ! ($data['confirm_change'] ?? false)) {
                    throw ValidationException::withMessages(['confirm_change' => 'Conferma il cambio server per le aziende che ereditano la connessione globale.']);
                }
                $token = $this->token($data, $sameServer ? $own : null);
                if ($sameServer && $token !== $own->api_key) {
                    try {
                        $companies = $this->client->verify($data['server_url'], $token);
                        $linked = SnipeItCompanyLink::where('connection_id', $own->id)->whereNotNull('confirmed_at')->pluck('external_company_id');
                        if ($linked->diff(array_column($companies, 'id'))->isNotEmpty()) {
                            throw new SnipeItException('missing_company');
                        }
                    } catch (SnipeItException $exception) {
                        throw ValidationException::withMessages(['api_key' => $exception->getMessage()]);
                    }
                    $own->fill(['api_key' => $token, 'checked_at' => now(), 'check_status' => 'ok'])->save();
                } elseif (! $sameServer) {
                    $own = SnipeItConnection::create(['server_url' => $data['server_url'], 'api_key' => $token]);
                }
                $setting->connection_id = $own->id;
                $setting->setRelation('connection', $own);
            }
            $setting->use_global = $useGlobal;
            $current = $useGlobal ? $global->connection : $setting->connection;
            if ($previous?->id !== $current?->id) {
                $this->suspendLinks($company, $previous?->id);
            }
            $setting->revision++;
            $setting->save();
        });

        return $this->saved($company, 'Connessione salvata.');
    }

    public function interval(Request $request): RedirectResponse
    {
        $data = $request->validate(['sync_interval' => ['required', 'integer', Rule::in(SnipeItSetting::SYNC_INTERVALS)]]);
        SnipeItSetting::whereKey('global')->update($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Frequenza di sincronizzazione salvata.']);

        return back();
    }

    public function destroy(Request $request, ?Company $company = null): RedirectResponse
    {
        $request->validate(['confirm_change' => ['accepted']]);
        $this->locked($request, $company, function ($setting) use ($company) {
            $own = $setting->connection;
            if ($own && (! $company || ! $setting->use_global)) {
                $this->suspendLinks($company, $own->id);
            }
            $setting->connection_id = null;
            $setting->revision++;
            $setting->save();
        });

        return $this->saved($company, 'Connessione rimossa.');
    }

    public function fields(Request $request, ?Company $company = null): JsonResponse
    {
        return $this->locked($request, $company, function ($setting, $global) {
            $connection = $this->activeConnection($setting, $global);
            $fields = $this->availableFields($connection);
            $mapping = $connection->mapping();
            foreach ($mapping as $key => $value) {
                if ($value !== null && str_starts_with($value, 'label:')) {
                    $mapping[$key] = collect($fields)->firstWhere('label', substr($value, 6))['value'] ?? $value;
                }
            }

            return response()->json(['fields' => $fields, 'mapping' => $mapping]);
        });
    }

    public function mapFields(Request $request, ?Company $company = null): RedirectResponse
    {
        $data = $request->validate([
            'field_mapping' => ['required', 'array:ip,mac,name,description'],
            'field_mapping.ip' => ['required', 'string', 'max:262'],
            'field_mapping.mac' => ['present', 'nullable', 'string', 'max:262'],
            'field_mapping.name' => ['required', 'string', 'max:262'],
            'field_mapping.description' => ['present', 'nullable', 'string', 'max:262'],
        ]);
        $this->locked($request, $company, function ($setting, $global) use ($data) {
            $connection = $this->activeConnection($setting, $global);
            $available = array_column($this->availableFields($connection), 'value');
            foreach ($data['field_mapping'] as $key => $value) {
                if ($value !== null && ! in_array($value, $available, true)) {
                    throw ValidationException::withMessages(['field_mapping.'.$key => 'Il campo non è disponibile in Snipe-IT. Ricarica l’elenco e scegli un campo.']);
                }
            }
            if (Arr::sortRecursive($connection->mapping()) !== Arr::sortRecursive($data['field_mapping'])) {
                $connection->update(['field_mapping' => $data['field_mapping']]);
                SnipeItSync::where('connection_id', $connection->id)->increment('revision');
            }
            // The mapping belongs to the connection: editing the global one from a company invalidates every page using it.
            if ($setting->use_global && $setting !== $global) {
                $global->revision++;
                $global->save();
            }
            $setting->revision++;
            $setting->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Mappatura salvata. Sarà usata dalla prossima sincronizzazione.']);

        return $company ? to_route('companies.settings.integrations.snipe-it.mapping', $company)
            : to_route('administration.integrations.snipe-it.edit');
    }

    private function availableFields(SnipeItConnection $connection): array
    {
        try {
            return $this->client->fields($connection->server_url, $connection->api_key);
        } catch (SnipeItException $exception) {
            throw ValidationException::withMessages(['connection' => $exception->getMessage()]);
        }
    }

    public function test(SnipeItRequest $request, ?Company $company = null): JsonResponse
    {
        $data = $request->connectionData();

        return $this->locked($request, $company, function ($setting, $global) use ($data, $company) {
            $useGlobal = $company !== null && $data['use_global'];
            $saved = $useGlobal ? $global->connection : $setting->connection;
            $url = $useGlobal ? $saved?->server_url : $data['server_url'];
            if (! $url) {
                throw ValidationException::withMessages(['connection' => 'La connessione globale non è configurata.']);
            }
            $token = $useGlobal ? $saved->api_key : $this->token($data, $saved?->server_url === $url ? $saved : null);
            $draft = $useGlobal !== $setting->use_global || ! $saved || $url !== $saved->server_url || $token !== $saved->api_key;
            try {
                $this->client->verify($url, $token);
                $status = 'ok';
            } catch (SnipeItException $exception) {
                $status = $exception->status;
            }
            $checkedAt = now();
            if (! $draft) {
                $saved->fill(['checked_at' => $checkedAt, 'check_status' => $status])->save();
            }

            return response()->json([
                'status' => $status, 'message' => SnipeItException::messageFor($status),
                'checked_at' => $checkedAt, 'draft' => $draft,
            ]);
        });
    }

    public function companies(Request $request, Company $company): JsonResponse
    {
        return $this->locked($request, $company, function ($setting, $global) use ($company) {
            $connection = $this->activeConnection($setting, $global);
            $companies = $this->visibleCompanies($connection);
            $matches = array_values(array_filter($companies, fn ($item) => mb_strtolower(trim($item['name'])) === mb_strtolower(trim($company->name))));

            return response()->json([
                'companies' => $companies, 'connection_id' => $connection->id,
                'suggested_id' => count($matches) === 1 ? $matches[0]['id'] : null,
            ]);
        });
    }

    public function associate(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['connection_id' => ['required', 'integer'], 'external_company_id' => ['required', 'integer', 'min:1']]);
        $this->locked($request, $company, function ($setting, $global) use ($data, $company) {
            $connection = $this->activeConnection($setting, $global);
            if ($connection->id !== (int) $data['connection_id']) {
                throw ValidationException::withMessages(['connection' => 'La sorgente è cambiata. Ricarica le aziende e riconferma.']);
            }
            $external = collect($this->visibleCompanies($connection))->firstWhere('id', $data['external_company_id']);
            if (! $external) {
                throw ValidationException::withMessages(['external_company_id' => 'L’azienda non è visibile con questa connessione. Ricarica l’elenco.']);
            }
            $this->suspendLinks($company, $connection->id);
            SnipeItCompanyLink::create([
                'company_id' => $company->id, 'connection_id' => $connection->id,
                'external_company_id' => $external['id'], 'external_company_name' => $external['name'], 'confirmed_at' => now(),
            ]);
            $setting->revision++;
            $setting->save();
        });

        return $this->saved($company, 'Azienda associata.');
    }

    private function token(#[\SensitiveParameter] array $data, ?SnipeItConnection $connection): string
    {
        $token = $data['api_key'] ?? $connection?->api_key;
        if (! $token) {
            throw ValidationException::withMessages(['api_key' => 'Inserisci una API key per questo server.']);
        }

        return $token;
    }

    private function activeConnection(SnipeItSetting $setting, SnipeItSetting $global): SnipeItConnection
    {
        $connection = $setting->use_global ? $global->connection : $setting->connection;
        if (! $connection) {
            throw ValidationException::withMessages(['connection' => 'Configura e salva la connessione prima di associare l’azienda.']);
        }

        return $connection;
    }

    private function visibleCompanies(SnipeItConnection $connection): array
    {
        try {
            return $this->client->companies($connection->server_url, $connection->api_key);
        } catch (SnipeItException $exception) {
            throw ValidationException::withMessages(['connection' => $exception->getMessage()]);
        }
    }

    private function suspendLinks(?Company $company, ?int $connectionId): void
    {
        $query = SnipeItCompanyLink::whereNotNull('confirmed_at');
        if ($company) {
            $query->where('company_id', $company->id);
        } else {
            $query->where('connection_id', $connectionId)->whereIn('company_id', SnipeItSetting::where('use_global', true)->select('company_id'));
        }
        $query->update(['confirmed_at' => null]);
    }

    private function locked(Request $request, ?Company $company, Closure $action): mixed
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'global_revision' => ['required', 'integer', 'min:0']]);

        return DB::transaction(function () use ($data, $company, $action) {
            // A common lock orders global changes, key rotations and company confirmations consistently.
            $global = SnipeItSetting::whereKey('global')->lockForUpdate()->firstOrFail();
            $setting = $company ? SnipeItSetting::forCompany($company) : $global;
            if ($setting->revision !== (int) $data['revision'] || $global->revision !== (int) $data['global_revision']) {
                throw ValidationException::withMessages(['connection' => 'Le impostazioni sono cambiate. Ricarica la pagina prima di continuare.']);
            }

            return $action($setting, $global);
        });
    }

    private function saved(?Company $company, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return $company ? to_route('companies.settings.integrations.snipe-it.edit', $company)
            : to_route('administration.integrations.snipe-it.edit');
    }
}
