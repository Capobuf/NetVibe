<?php

namespace App\Support;

use App\Exceptions\SnipeItException;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Manufacturer;
use App\Models\ModelType;
use App\Models\SnipeItAsset;
use App\Models\SnipeItCompanyLink;
use App\Models\SnipeItModel;
use App\Models\SnipeItSetting;
use App\Models\SnipeItSync as Sync;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            $inventory = $this->client->inventory($connection->server_url, $connection->api_key, $sync->external_company_id);
            DB::transaction(function () use ($sync, $inventory, $connection) {
                SnipeItSetting::whereKey('global')->lockForUpdate()->firstOrFail();
                $sync->refresh();
                $this->ensureActive($sync);
                if ($connection->updated_at != $connection->fresh()->updated_at) {
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
                foreach ($inventory['assets'] as $externalId => $snapshot) {
                    $asset = $sync->assets()->firstOrNew(['external_id' => $externalId]);
                    if (! $asset->exists) {
                        $asset->state = 'pending';
                        $asset->needs_confirmation = ! $sync->activated_at;
                    }
                    $asset->fill([
                        'snapshot' => $snapshot, 'present' => true,
                        'snipe_it_model_id' => ($models[$snapshot['model_id']] ?? null)?->id,
                    ])->save();
                    $asset->setRelation('sync', $sync);
                    $this->applyAsset($asset);
                }
                $sync->update(['synced_at' => now(), 'error' => null]);
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
                if ($asset->device_id) {
                    $this->invalid('Il dispositivo è già collegato. Usa l’azione Scollega dal dettaglio.');
                }
                if ($decision['action'] === 'exclude') {
                    $asset->update(['state' => 'excluded', 'problem' => null, 'needs_confirmation' => true]);

                    continue;
                }
                if ($decision['action'] === 'restore' && $asset->state === 'excluded') {
                    $asset->update(['state' => 'pending', 'needs_confirmation' => true]);

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
            $asset->update(['device_id' => null, 'state' => $exclude ? 'excluded' : 'pending', 'needs_confirmation' => true, 'problem' => null]);
            $asset->sync()->increment('revision');
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

    private function applyAsset(SnipeItAsset $asset, bool $explicit = false, ?Device $target = null, ?int $siteId = null, ?int $modelId = null, bool $changeModel = false): void
    {
        if ($asset->state === 'excluded') {
            return;
        }
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
        $device = $target ?? ($asset->device_id ? $this->companyDevices($asset->sync)->lockForUpdate()->findOrFail($asset->device_id) : null);
        if (! $device && ! $explicit) {
            if ($asset->state === 'linked') {
                // Ordinary deletion is intentional: never recreate the deleted device implicitly.
                $asset->needs_confirmation = true;
            }
            if ($asset->needs_confirmation || ! $asset->sync->activated_at) {
                $asset->update(['state' => 'pending', 'needs_confirmation' => true, 'problem' => null]);

                return;
            }
            if ($this->deviceCandidates($asset)->exists()) {
                $asset->update(['state' => 'pending', 'needs_confirmation' => true, 'problem' => 'Possibile dispositivo esistente: conferma l’associazione.']);

                return;
            }
            $sites = $asset->sync->company->sites()->pluck('id');
            if ($sites->count() !== 1) {
                $asset->update(['state' => 'site', 'problem' => null]);

                return;
            }
            $siteId = $sites->first();
        }
        try {
            // Roll back catalog creation too when this device cannot be imported.
            DB::transaction(function () use ($asset, $externalModel, $modelId, $device, $siteId, $data, $name, $explicit, $changeModel) {
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
                $device->fill(['name' => $name, 'serial_number' => $data['serial'], 'asset_tag' => $data['asset_tag'],
                    'device_model_id' => $model->id, 'manufacturer_id' => $model->manufacturer_id])->save();
                $device->addModelInterfaces($model);
                $asset->update(['device_id' => $device->id, 'state' => 'linked', 'needs_confirmation' => false, 'problem' => null]);
            });
        } catch (ValidationException $exception) {
            if ($asset->device_id && $device) {
                $device->update(['name' => $name, 'serial_number' => $data['serial'], 'asset_tag' => $data['asset_tag']]);
            }
            $asset->update(['problem' => $exception->errors()['sync'][0], 'needs_confirmation' => true]);
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['sync' => $message]);
    }
}
