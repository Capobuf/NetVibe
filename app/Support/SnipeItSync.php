<?php

namespace App\Support;

use App\Exceptions\SnipeItException;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\IpAddress;
use App\Models\Manufacturer;
use App\Models\MikrotikSource;
use App\Models\ModelType;
use App\Models\SnipeItAsset;
use App\Models\SnipeItCompanyLink;
use App\Models\SnipeItModel;
use App\Models\SnipeItSetting;
use App\Models\SnipeItSync as Sync;
use App\Models\Subnet;
use App\Models\UnifiDevice;
use App\Models\UnifiObservation;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SnipeItSync
{
    public function __construct(private SnipeItClient $client) {}

    public function refresh(Company $company): Sync
    {
        $sync = DB::transaction(function () use ($company) {
            SnipeItSetting::whereKey('global')->lockForUpdate()->firstOrFail();
            $link = SnipeItCompanyLink::where('company_id', $company->id)->latest('id')->first();
            if (! $link || ! $link->confirmed_at) {
                $this->invalid('Conferma prima l’azienda Snipe-IT.');
            }
            $sync = Sync::firstOrCreate($link->only('company_id', 'connection_id', 'external_company_id'));
            $this->ensureActive($sync);

            return $sync;
        });
        $lock = Cache::lock('snipe-it-sync:'.$sync->connection_id, 120);
        if (! $lock->get()) {
            $this->invalid('Una sincronizzazione è già in corso. Attendi e riprova.');
        }
        try {
            $connection = $sync->connection;
            $inventory = $this->client->inventory($connection->server_url, $connection->api_key, $sync->external_company_id, $connection->mapping());
            DB::transaction(function () use ($sync, $inventory, $connection) {
                SnipeItSetting::whereKey('global')->lockForUpdate()->firstOrFail();
                $sync->refresh();
                $this->ensureActive($sync);
                $currentConnection = $connection->fresh();
                if ($connection->updated_at != $currentConnection->updated_at
                    || Arr::sortRecursive($connection->mapping()) !== Arr::sortRecursive($currentConnection->mapping())) {
                    $this->invalid('La connessione è cambiata durante la lettura. Sincronizza nuovamente.');
                }
                $models = [];
                foreach ($inventory['models'] as $externalId => $snapshot) {
                    $external = SnipeItModel::firstOrNew(['connection_id' => $sync->connection_id, 'external_id' => $externalId]);
                    $external->snapshot = $snapshot;
                    $external->save();
                    $models[$externalId] = $external;
                }
                // Lock catalog before company, matching the ordinary device editing path.
                DeviceModel::whereIn('id', collect($models)->pluck('device_model_id')->filter())->orderBy('id')->lockForUpdate()->get();
                Company::whereKey($sync->company_id)->lockForUpdate()->firstOrFail();
                $sync->assets()->update(['present' => false]);
                $assets = [];
                foreach ($inventory['assets'] as $externalId => $snapshot) {
                    $asset = $sync->assets()->firstOrNew(['external_id' => $externalId]);
                    $previous = $asset->snapshot ?? [];
                    if (! $asset->exists) {
                        $asset->state = 'pending';
                        $asset->needs_confirmation = true;
                    }
                    $asset->fill([
                        'snapshot' => $snapshot, 'present' => true,
                        'snipe_it_model_id' => ($models[$snapshot['model_id']] ?? null)?->id,
                    ])->save();
                    $asset->setRelation('sync', $sync);
                    $assets[] = [$asset, $previous];
                }
                // Release obsolete links first, so assets that swap addresses never block each other.
                foreach ($assets as [$asset, $previous]) {
                    $this->releaseNetwork($asset, $previous);
                }
                // Read all network claims before assigning any IP, regardless of API order.
                foreach ($assets as [$asset, $previous]) {
                    $this->applyAsset($asset, previous: $previous);
                }
                $sync->update(['activated_at' => $sync->activated_at ?? now(), 'synced_at' => now(), 'error' => null]);
                Sync::where('connection_id', $sync->connection_id)->increment('revision');
            }, 3);
        } catch (SnipeItException $e) {
            $sync->update(['error' => $e->getMessage()]);
            throw ValidationException::withMessages(['sync' => $e->getMessage()]);
        } finally {
            $lock->release();
        }

        return $sync->fresh();
    }

    public function confirm(Company $company, int $syncId, int $revision, array $decisions, bool $activate): void
    {
        $this->locked($company, $syncId, $revision, function (Sync $sync) use ($decisions, $activate) {
            $assets = $sync->assets()->whereIn('id', array_column($decisions, 'id'))->with('externalModel')->get()->keyBy('id');
            if ($assets->count() !== count($decisions)) {
                $this->invalid('La selezione contiene asset non disponibili per questa azienda.');
            }
            DeviceModel::whereIn('id', $assets->pluck('externalModel.device_model_id')->filter())->orderBy('id')->lockForUpdate()->get();
            Company::whereKey($sync->company_id)->lockForUpdate()->firstOrFail();
            foreach ($decisions as $decision) {
                $asset = $assets[$decision['id']];
                $asset->setRelation('sync', $sync);
                if (in_array($decision['action'], ['create', 'link'], true)
                    && Arr::sortRecursive($asset->snapshot['mapping'] ?? []) !== Arr::sortRecursive($sync->connection->mapping())) {
                    $this->invalid('I campi da sincronizzare sono cambiati. Sincronizza nuovamente prima di confermare gli asset.');
                }
                if ($asset->device_id) {
                    $this->invalid('Il dispositivo è già collegato. Usa l’azione Scollega dal dettaglio.');
                }
                if ($decision['action'] === 'exclude') {
                    $asset->update(['state' => 'excluded', 'problem' => null, 'needs_confirmation' => true]);

                    continue;
                }
                if ($decision['action'] === 'restore' && $asset->state === 'excluded') {
                    $asset->update(['state' => 'detached', 'needs_confirmation' => true]);

                    continue;
                }
                if (! $asset->present || $asset->state === 'excluded' || ! in_array($decision['action'], ['create', 'link'], true)) {
                    $this->invalid('Ricarica la pagina e verifica lo stato dell’asset.');
                }
                $device = null;
                if ($decision['action'] === 'link') {
                    $device = $this->companyDevices($sync)->lockForUpdate()->findOrFail($decision['target_id'] ?? 0);
                    if ($device->snipeItAsset()->exists()) {
                        $this->invalid('Il dispositivo scelto ha già un collegamento Snipe-IT.');
                    }
                }
                $siteId = $device?->site_id ?? ($decision['site_id'] ?? null);
                if (! $device && ! $sync->company->sites()->whereKey($siteId)->exists()) {
                    $this->invalid('Scegli una sede dell’azienda per ogni dispositivo da creare.');
                }
                $this->applyAsset($asset, true, $device, $siteId, $decision['model_id'] ?? null, $decision['change_model'] ?? false);
            }
            if ($activate && ! $sync->activated_at) {
                if ($sync->synced_at && $sync->assets()->whereNotNull('device_id')->exists()) {
                    $sync->update(['activated_at' => now()]);
                }
            }
            Sync::where('connection_id', $sync->connection_id)->increment('revision');
        });
    }

    public function detach(Company $company, int $assetId, bool $exclude): void
    {
        DB::transaction(function () use ($company, $assetId, $exclude) {
            SnipeItSetting::whereKey('global')->lockForUpdate()->firstOrFail();
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $asset = SnipeItAsset::whereHas('sync', fn ($q) => $q->where('company_id', $company->id))
                ->lockForUpdate()->findOrFail($assetId);
            if (! $asset->device_id) {
                $this->invalid('Il dispositivo è già scollegato.');
            }
            $asset->update(['device_id' => null, 'state' => $exclude ? 'excluded' : 'detached', 'needs_confirmation' => true, 'problem' => null]);
            $asset->sync()->increment('revision');
        }, 3);
    }

    /** Undoes a manual removal of the asset IP documentation and reconciles the current address; Snipe-IT is not modified. */
    public function restoreIp(Company $company, int $assetId): SnipeItAsset
    {
        return DB::transaction(function () use ($company, $assetId) {
            SnipeItSetting::whereKey('global')->lockForUpdate()->firstOrFail();
            $asset = SnipeItAsset::whereHas('sync', fn ($q) => $q->where('company_id', $company->id))
                ->with('sync.company', 'sync.connection', 'externalModel')->lockForUpdate()->findOrFail($assetId);
            $this->ensureActive($asset->sync);
            if ($asset->removed_address === null) {
                $this->invalid('La documentazione è già stata ripristinata. Ricarica la pagina.');
            }
            DeviceModel::whereKey($asset->externalModel?->device_model_id)->lockForUpdate()->get();
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $asset->update(['removed_address' => null]);
            $this->applyAsset($asset, previous: $asset->snapshot);
            Sync::where('connection_id', $asset->sync->connection_id)->increment('revision');

            return $asset;
        }, 3);
    }

    private function locked(Company $company, int $syncId, int $revision, Closure $action): void
    {
        DB::transaction(function () use ($company, $syncId, $revision, $action) {
            SnipeItSetting::whereKey('global')->lockForUpdate()->firstOrFail();
            $sync = Sync::where('company_id', $company->id)->lockForUpdate()->findOrFail($syncId);
            $this->ensureActive($sync);
            if ($sync->revision !== $revision) {
                $this->invalid('I dati sono cambiati. Ricarica la pagina prima di confermare.');
            }
            $action($sync);
        }, 3);
    }

    private function ensureActive(Sync $sync): void
    {
        if (! $sync->isActive()) {
            $this->invalid('Connessione sospesa. Riconferma l’azienda sulla connessione attuale.');
        }
    }

    public function modelCandidates(SnipeItModel $external): Builder
    {
        return DeviceModel::where('name', $external->snapshot['name'])
            ->whereHas('manufacturer', fn ($q) => $q->where('name', $external->snapshot['manufacturer']));
    }

    public function modelProblem(?SnipeItModel $external): ?string
    {
        if (! $external) {
            return 'Modello mancante in Snipe-IT.';
        }
        $data = $external->snapshot;
        if ($data['name'] === null) {
            return 'Nome del modello mancante in Snipe-IT.';
        }
        if ($data['manufacturer'] === null) {
            return 'Produttore del modello mancante in Snipe-IT.';
        }
        if (collect($data)->contains(fn ($value) => mb_strlen($value ?? '') > 255)) {
            return 'Nome, produttore o categoria del modello supera 255 caratteri in Snipe-IT.';
        }

        return null;
    }

    private function resolveModel(SnipeItModel $external, ?int $modelId): DeviceModel
    {
        if ($external->device_model_id) {
            if ($modelId && $modelId !== $external->device_model_id) {
                $this->invalid('Il modello Snipe-IT è già associato a un altro modello NetVibe. Ricarica la pagina.');
            }
            $this->updateModel($external);

            return $external->model()->firstOrFail();
        }
        $data = $external->snapshot;
        $candidates = $this->modelCandidates($external)->lockForUpdate()->get();
        if (! $modelId && $candidates->count() > 1) {
            $this->invalid('Più modelli corrispondenti: scegli il modello NetVibe nella riga del dispositivo.');
        }
        $model = $modelId ? DeviceModel::lockForUpdate()->findOrFail($modelId) : $candidates->first();
        if (! $model) {
            $manufacturer = Manufacturer::firstOrCreate(['name' => $data['manufacturer']]);
            $type = $data['category'] !== null ? ModelType::firstOrCreate(['name' => $data['category']]) : null;
            $model = $manufacturer->models()->firstOrCreate(['name' => $data['name']], ['model_type_id' => $type?->id]);
        }
        $current = $this->modelData($model);
        if ($this->modelCandidates($external)->whereKey($model->id)->exists() && $model->model_type_id === null && $data['category'] !== null
            && ! SnipeItModel::where('device_model_id', $model->id)->whereNotNull('snapshot->category')
                ->where('snapshot->category', '!=', $data['category'])->exists()) {
            $model->update(['model_type_id' => ModelType::firstOrCreate(['name' => $data['category']])->id]);
            $model->unsetRelation('modelType');
            $current = $this->modelData($model);
        }
        $data['category'] ??= $current['category'];
        $data = Arr::sortRecursive($data);
        // A confirmed mapping must not grant permission to rewrite a different local catalog entry.
        $external->update(['device_model_id' => $model->id, 'applied' => $data,
            'problem' => $current === $data ? null : 'Il modello associato in NetVibe ha dati diversi da Snipe-IT. Verifica il modello scelto nel catalogo.']);

        return $model;
    }

    private function modelData(DeviceModel $model): array
    {
        // MySQL JSON objects can return keys in a different order. Keep value comparisons strict.
        return Arr::sortRecursive(['name' => $model->name, 'manufacturer' => $model->manufacturer->name, 'category' => $model->modelType?->name]);
    }

    private function updateModel(SnipeItModel $external): void
    {
        $model = DeviceModel::lockForUpdate()->findOrFail($external->device_model_id);
        $current = $this->modelData($model);
        $desired = $external->snapshot;
        $desired['category'] ??= $current['category'];
        $desired = Arr::sortRecursive($desired);
        if ($current === $desired) {
            $external->update(['applied' => $current, 'problem' => null]);

            return;
        }
        $otherSources = SnipeItModel::where('device_model_id', $model->id)->whereKeyNot($external->id)->get();
        $compatibleSources = [...$otherSources->filter(fn ($source) => Arr::sortRecursive([...$source->snapshot, 'category' => $source->snapshot['category'] ?? $current['category']]) === $desired)->modelKeys(), $external->id];
        $manualDevices = $model->devices()->whereDoesntHave('snipeItAsset', fn ($q) => $q->whereIn('snipe_it_model_id', $compatibleSources))->exists();
        if ($desired['name'] === null || $desired['manufacturer'] === null || collect($desired)->contains(fn ($value) => mb_strlen($value ?? '') > 255)
            || $current !== Arr::sortRecursive($external->applied ?? []) || $manualDevices
            || $otherSources->contains(fn ($source) => Arr::sortRecursive([...$source->snapshot, 'category' => $source->snapshot['category'] ?? $current['category']]) !== $desired)
            || $this->modelCandidates($external)->whereKeyNot($model->id)->exists()) {
            $external->update(['problem' => 'Il modello non è stato aggiornato. Verifica le differenze con Snipe-IT nel catalogo NetVibe.']);

            return;
        }
        $manufacturer = Manufacturer::firstOrCreate(['name' => $desired['manufacturer']]);
        if (! $manufacturer->isMikrotik() && $model->devices()->whereHas('mikrotikConnection')->exists()) {
            $external->update(['problem' => 'Cambio produttore sospeso: rimuovi esplicitamente le connessioni MikroTik dei dispositivi prima di riprovare.']);

            return;
        }
        $type = $desired['category'] !== null ? ModelType::firstOrCreate(['name' => $desired['category']]) : null;
        $model->update(['name' => $desired['name'], 'manufacturer_id' => $manufacturer->id, 'model_type_id' => $type?->id]);
        $model->devices()->update(['manufacturer_id' => $manufacturer->id]);
        $external->update(['applied' => $desired, 'problem' => null]);
    }

    public function companyDevices(Sync $sync): Builder
    {
        return Device::whereHas('site', fn ($q) => $q->where('company_id', $sync->company_id));
    }

    public function deviceCandidates(SnipeItAsset $asset): Builder
    {
        $data = $asset->snapshot;
        $name = $data['name'] ?? $data['asset_tag'];

        // Names alone still prevent automatic creation when a manual record could be the same device.
        return $this->companyDevices($asset->sync)->where(function ($query) use ($data, $name) {
            $query->where('name', $name);
            if ($data['serial'] !== null) {
                $query->orWhere('serial_number', $data['serial']);
            }
            if ($data['asset_tag'] !== null) {
                $query->orWhere('asset_tag', $data['asset_tag']);
            }
        });
    }

    private function applyAsset(SnipeItAsset $asset, bool $explicit = false, ?Device $target = null, ?int $siteId = null, ?int $modelId = null, bool $changeModel = false, array $previous = []): void
    {
        if ($asset->state === 'excluded') {
            return;
        }
        $device = $target ?? ($asset->device_id ? $this->companyDevices($asset->sync)->lockForUpdate()->findOrFail($asset->device_id) : null);
        if (! $device && ! $explicit) {
            $problem = $this->applyNetwork($asset, previous: $previous);
            $asset->update([
                'state' => in_array($asset->state, ['linked', 'detached'], true) ? 'detached' : 'pending',
                'needs_confirmation' => true,
                'problem' => $problem,
            ]);

            return;
        }
        $previousNotes = $previous['notes'] ?? null;
        $data = $asset->snapshot;
        $name = $data['name'] ?? $data['asset_tag'];
        $externalModel = $asset->externalModel()->first();
        $problem = null;
        if ($name === null || collect([$name, $data['serial'], $data['asset_tag']])->contains(fn ($value) => mb_strlen($value ?? '') > 255)) {
            $problem = 'Nome/asset tag mancante o anagrafica troppo lunga. Correggi l’asset in Snipe-IT.';
        } elseif ($modelProblem = $this->modelProblem($externalModel)) {
            $problem = $modelProblem;
        } elseif (SnipeItAsset::where('external_id', $asset->external_id)->whereKeyNot($asset->id)->whereNotNull('device_id')
            ->whereHas('sync', fn ($q) => $q->where('connection_id', $asset->sync->connection_id))->exists()) {
            $problem = 'Asset già collegato in un’altra associazione aziendale. Scollegalo esplicitamente prima di riassociarlo.';
        }
        if ($problem) {
            $asset->update(['problem' => $problem]);

            return;
        }
        try {
            // Roll back catalog creation too when this device cannot be imported.
            DB::transaction(function () use ($asset, $externalModel, $modelId, $device, $siteId, $data, $name, $explicit, $changeModel, $previousNotes) {
                $model = $this->resolveModel($externalModel, $modelId);
                if ($device) {
                    if ($blocked = $device->modelChangeProblem($model->id)) {
                        $this->invalid($blocked);
                    }
                    if ($explicit && $device->device_model_id !== $model->id && ! $changeModel) {
                        $this->invalid('Modelli differenti: verifica il modello nella riga del dispositivo prima di confermare.');
                    }
                } else {
                    $device = $asset->sync->company->sites()->findOrFail($siteId)->devices()->make();
                }
                $notesProblem = null;
                if (($data['notes'] ?? null) !== null) {
                    if (mb_strlen($data['notes']) > 10000) {
                        $notesProblem = 'Le note Snipe-IT superano 10.000 caratteri.';
                    } elseif (! $device->exists || $device->notes === $previousNotes || ($previousNotes === null && blank($device->notes))) {
                        $device->notes = $data['notes'];
                    } elseif ($device->notes !== $data['notes']) {
                        $notesProblem = 'Le note del dispositivo sono state conservate perché diverse da Snipe-IT.';
                    }
                }
                $device->fill(['name' => $name, 'serial_number' => $data['serial'], 'asset_tag' => $data['asset_tag'],
                    'device_model_id' => $model->id, 'manufacturer_id' => $model->manufacturer_id])->save();
                $device->addModelInterfaces($model);
                $asset->update(['device_id' => $device->id, 'state' => 'linked', 'needs_confirmation' => false,
                    'problem' => implode(' ', array_filter([$notesProblem, $this->applyNetwork($asset, $device)])) ?: null]);
            });
        } catch (ValidationException $exception) {
            if ($asset->device_id && $device) {
                $device->update(['name' => $name, 'serial_number' => $data['serial'], 'asset_tag' => $data['asset_tag']]);
            }
            $asset->update(['problem' => $exception->errors()['sync'][0], 'needs_confirmation' => true]);
        }
    }

    /**
     * Drops the link to an address the asset no longer declares. The old record is deleted only when this
     * asset alone justified it; data of technicians or other sources keeps it documented without the link.
     */
    private function releaseNetwork(SnipeItAsset $asset, array $previous): void
    {
        if ($asset->state === 'excluded') {
            return;
        }
        $ip = $asset->snapshot['network']['ip'] ?? null;
        $address = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($ip) : null;
        if ($asset->removed_address !== null && $asset->removed_address !== $address) {
            // The manual removal concerned only the previous address: a real change is documented again.
            $asset->removed_address = null;
        }
        $record = $asset->ip_address_id ? IpAddress::with('subnet')->lockForUpdate()->find($asset->ip_address_id) : null;
        if ($record && $record->address !== $address) {
            $asset->ip_address_id = null;
        }
        $asset->save();
        if (! $record || $asset->ip_address_id) {
            return;
        }
        $names = array_filter([$previous['name'] ?? $previous['asset_tag'] ?? null, $asset->snapshot['name'] ?? $asset->snapshot['asset_tag'] ?? null]);
        $macs = array_map(MacAddress::normalize(...), array_filter([$previous['network']['mac'] ?? null, $asset->snapshot['network']['mac'] ?? null]));
        $onlySnipeIt = ! $record->auto_managed && $record->ip_status_id === null && $record->device_interface_id === null
            && $record->mikrotik_edit_mode === null && blank($record->description) && ! $record->tags()->exists()
            && ($record->device_id === null || $record->device_id === $asset->device_id)
            && (blank($record->hostname) || in_array($record->hostname, $names, true))
            && (blank($record->mac_address) || in_array($record->mac_address, $macs, true))
            && ! UnifiIpam::addressSources($record->subnet->site_id, true)->where('address', $record->address)->exists();
        if ($onlySnipeIt) {
            $record->delete();
        }
    }

    private function applyNetwork(SnipeItAsset $asset, ?Device $device = null, array $previous = []): ?string
    {
        $network = $asset->snapshot['network'] ?? [];
        $ip = $network['ip'] ?? null;
        $mac = MacAddress::normalize($network['mac'] ?? null);
        if ($ip === null) {
            return null;
        }
        if (Validator::make(['ip' => $ip, 'mac' => $mac], [
            'ip' => ['required', 'ipv4'], 'mac' => ['nullable', 'mac_address'],
        ])->fails()) {
            return 'IP o MAC non valido. Verifica i campi selezionati e i valori in Snipe-IT.';
        }
        $address = ip2long($ip);
        if ($asset->removed_address === $address) {
            // The technician removed this documentation: it stays visible as Snipe-IT evidence only.
            return null;
        }
        $subnets = Subnet::whereIn('site_id', $asset->sync->company->sites()->select('id'))
            ->when($device, fn ($query) => $query->where('site_id', $device->site_id))->visible()
            ->where('network_address', '<=', $address)->where('broadcast_address', '>=', $address)->get();
        if ($subnets->count() !== 1) {
            return "IP {$ip}: nessuna subnet univoca disponibile nelle sedi interessate.";
        }
        $subnet = $subnets->first();
        [$first, $last] = Ipv4Ranges::usableBounds($subnet->network_address, $subnet->broadcast_address);
        if ($address < $first || $address > $last) {
            return "IP {$ip}: indirizzo di rete o broadcast non assegnabile.";
        }
        $duplicate = $asset->sync->assets()->whereKeyNot($asset->id)->where('present', true)->where('state', '!=', 'excluded')
            ->where(fn ($query) => $query->whereNull('device_id')->orWhereHas('device', fn ($query) => $query->where('site_id', $subnet->site_id)))
            ->where(function ($query) use ($ip, $mac) {
                $query->where('snapshot->network->ip', $ip);
                if ($mac !== null) {
                    $query->orWhere('snapshot->network->mac', $mac);
                }
            })->exists();
        if ($duplicate) {
            return "IP {$ip}: IP o MAC dichiarato anche da un altro asset Snipe-IT. Verifica gli asset e le sedi.";
        }
        $record = $subnet->ipAddresses()->where('address', $address)->lockForUpdate()->first();
        if ($record && SnipeItAsset::where('ip_address_id', $record->id)->whereKeyNot($asset->id)->exists()) {
            return "IP {$ip}: già collegato a un altro asset Snipe-IT.";
        }
        $sourceConflicts = MikrotikSource::blocking($subnet->site_id)->where('address', $address)->with('device')->get()
            ->filter(fn ($source) => ($source->kind === 'address' && $source->device_id !== $device?->id)
                || ($mac !== null && ! empty($source->data['mac']) && MacAddress::normalize($source->data['mac']) !== $mac));
        $unifiConflicts = $this->unifiConflicts($subnet->site_id, $address, $mac, $device);
        if (! $device) {
            // Document the client independently; source discrepancies never assign a technical device.
            $record ??= $subnet->ipAddresses()->make(['address' => $address]);
            $problems = [];
            $kept = [];
            $name = $asset->snapshot['name'] ?? $asset->snapshot['asset_tag'];
            $values = ['hostname' => $name, 'mac_address' => $mac];
            $old = ['hostname' => $previous['name'] ?? $previous['asset_tag'] ?? null, 'mac_address' => MacAddress::normalize($previous['network']['mac'] ?? null)];
            foreach ($values as $field => $value) {
                if ($value === null) {
                    continue;
                }
                if (mb_strlen($value) > ($field === 'hostname' ? 255 : 17)) {
                    $problems[] = 'Nome o MAC troppo lungo in Snipe-IT.';
                } elseif ($record->device_id) {
                    $problems[] = 'Associato a un dispositivo tecnico: hostname e MAC non aggiornati in NetVibe.';
                } elseif (! $record->exists || ($asset->ip_address_id !== $record->id && blank($record->$field)) || $old[$field] === $record->$field) {
                    $record->$field = $value;
                } elseif ($value !== $record->$field) {
                    $kept[] = $field === 'hostname' ? 'hostname' : 'MAC';
                }
            }
            $record->save();
            $asset->update(['ip_address_id' => $record->id]);
            $rows = [];
            if ($kept || $sourceConflicts->isNotEmpty() || $unifiConflicts->isNotEmpty()) {
                $outcome = $kept ? ucfirst(Arr::join($kept, ', ', ' e ')).(count($kept) === 1 ? ' non aggiornato' : ' non aggiornati').' in NetVibe.' : '';
                $problems[] = $this->mismatch(['NetVibe' => $kept, 'MikroTik' => $sourceConflicts, 'UniFi' => $unifiConflicts], $outcome);
                $rows = $this->comparison($asset, $mac, $record, $sourceConflicts, $unifiConflicts);
            }

            return $problems ? implode("\n", ["IP {$ip}", ...array_unique($problems), ...$rows]) : null;
        }
        if ($record?->device_id && $record->device_id !== $device->id) {
            return "IP {$ip}: già associato a un altro dispositivo in NetVibe.";
        }
        $macMismatch = $mac !== null && $record?->mac_address && $record->mac_address !== $mac;
        if ($macMismatch || $sourceConflicts->isNotEmpty() || $unifiConflicts->isNotEmpty()) {
            return implode("\n", [
                "IP {$ip}",
                $this->mismatch(['NetVibe' => $macMismatch, 'MikroTik' => $sourceConflicts, 'UniFi' => $unifiConflicts], "IP non assegnato a {$device->name}."),
                ...$this->comparison($asset, $mac, $record, $sourceConflicts, $unifiConflicts),
            ]);
        }
        try {
            // A shared interface MAC may require confirmation; roll back all its changes in that case.
            DB::transaction(function () use ($asset, $device, $subnet, $record, $address, $mac) {
                $record ??= $subnet->ipAddresses()->make(['address' => $address]);
                $assignment = DeviceAssignment::validate($device->site_id, $device->id, $record->device_interface_id, $record);
                $assignment['mac_address'] = $mac ?? $record->mac_address;
                if ($record->device_interface_id) {
                    $interface = $device->interfaces()->lockForUpdate()->findOrFail($record->device_interface_id);
                    $assignment['mac_address'] = InterfaceMac::synchronize($interface, $assignment['mac_address'], $record, null);
                }
                $record->fill($assignment)->save();
                $asset->update(['ip_address_id' => $record->id]);
            });
        } catch (ValidationException $exception) {
            return "IP {$ip}: associazione sospesa. ".collect($exception->errors())->flatten()->first();
        }

        return $subnet->isExcluded($address) ? "IP {$ip} documentato in un intervallo escluso dai suggerimenti." : null;
    }

    /** One sentence naming the sources that disagree with Snipe-IT and what was not applied because of it. */
    private function mismatch(array $sources, string $outcome): string
    {
        $parties = array_keys(array_filter($sources, fn ($value) => $value instanceof Collection ? $value->isNotEmpty() : (bool) $value));

        return trim('Snipe-IT non corrisponde a '.Arr::join($parties, ', ', ' e ').'. '.$outcome);
    }

    /** Tab-separated "source, hostname, MAC" lines: the UI renders them as a comparison table. */
    private function comparison(SnipeItAsset $asset, ?string $mac, ?IpAddress $record, Collection $sources, Collection $unifi): array
    {
        $row = fn (string $label, ?string $hostname, ?string $mac) => implode("\t", [$label, $hostname ?: '—', MacAddress::normalize($mac) ?? '—']);
        $name = $asset->snapshot['name'] ?? $asset->snapshot['asset_tag'];
        $rows = [$row('Snipe-IT', $name, $mac)];
        // NetVibe matters only when its documentation differs from what Snipe-IT declares.
        if ($record && (filled($record->hostname) || filled($record->mac_address))
            && [$record->hostname, $record->mac_address] !== [$name, $mac]) {
            $rows[] = $row('NetVibe', $record->hostname, $record->mac_address);
        }
        // A lease and an ARP entry of one router describe the same client: show it once, with any observed hostname.
        $clients = $sources->where('kind', '!=', 'address')->groupBy(fn ($source) => $source->device->name."\t".MacAddress::normalize($source->data['mac'] ?? null));
        foreach ($clients as $group) {
            $rows[] = $row("MikroTik {$group->first()->device->name}", $group->pluck('data.hostname_observed')->filter()->first(), $group->first()->data['mac'] ?? null);
        }
        foreach ($sources->where('kind', 'address') as $source) {
            // A configured address belongs to the router interface itself, not to a client.
            $rows[] = "MikroTik {$source->device->name}\tIP dell’interfaccia {$source->data['interface']}";
        }
        foreach ($unifi as $observation) {
            $rows[] = $row("UniFi {$observation->unifiSite->name}", $observation->data['hostname_observed'] ?? $observation->data['name'] ?? null, $observation->data['mac'] ?? null);
        }

        return array_values(array_unique($rows));
    }

    /** Only current clients and devices of readable UniFi sites are comparable facts: a different MAC or mapped device. */
    private function unifiConflicts(int $siteId, int $address, ?string $mac, ?Device $device): Collection
    {
        return UnifiObservation::forSite($siteId)->where('address', $address)->whereIn('kind', ['client', 'device'])
            ->where('state', 'current')->with('unifiSite.unifiConnection')->get()->reject->stale()
            ->filter(function ($observation) use ($mac, $device) {
                if ($mac !== null && ! empty($observation->data['mac']) && MacAddress::normalize($observation->data['mac']) !== $mac) {
                    return true;
                }
                $mapped = $observation->kind === 'device' ? UnifiDevice::where('unifi_site_id', $observation->unifi_site_id)
                    ->where('external_id', $observation->source_key)->where('present', true)->value('device_id') : null;

                return $mapped !== null && $mapped !== $device?->id;
            });
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['sync' => $message]);
    }
}
