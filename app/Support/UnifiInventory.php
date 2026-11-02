<?php

namespace App\Support;

use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Manufacturer;
use App\Models\ModelType;
use App\Models\UnifiDevice;
use App\Models\UnifiSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class UnifiInventory
{
    public function capture(UnifiSite $site, array $devices): void
    {
        $site->unifiDevices()->update(['present' => false]);
        foreach ($devices as $data) {
            $source = $site->unifiDevices()->firstOrNew(['external_id' => $data['id']]);
            $source->fill(['observed' => $data, 'present' => true, 'synced_at' => now()]);
            $source->status ??= 'pending';
            $source->save();
            $source->setRelation('unifiSite', $site);
        }
        // Persist every identity before deciding whether a new object has a plausible duplicate.
        foreach ($site->unifiDevices()->where('present', true)->get() as $source) {
            $source->setRelation('unifiSite', $site);
            if ($site->inventory_activated_at && $source->status === 'pending' && ! $this->hasCandidate($source)) {
                $this->link($source);
            } elseif ($source->device_id && $source->status === 'linked') {
                $this->ports($source);
            }
        }
    }

    public function activate(UnifiSite $site): void
    {
        $site->update(['inventory_activated_at' => $site->inventory_activated_at ?? now()]);
        foreach ($site->unifiDevices()->where('present', true)->where('status', 'pending')->get() as $source) {
            $source->setRelation('unifiSite', $site);
            if (! $this->hasCandidate($source)) {
                $this->link($source);
            }
        }
    }

    public static function resetForMapping(UnifiSite $site): void
    {
        // Keep the external identities as tombstones; changing a mapping cannot recreate old assets silently.
        $site->unifiDevices()->update(['device_id' => null, 'status' => 'deleted', 'present' => false,
            'observed' => json_encode([]), 'ports' => null, 'maintained' => null]);
        $site->fill(['inventory_activated_at' => null, 'last_synced_at' => null, 'sync_status' => null]);
    }

    public function candidates(UnifiDevice $source): Builder
    {
        $data = $source->observed;

        return Device::whereHas('site', fn ($query) => $query->where('company_id', $source->unifiSite->site->company_id))
            ->where(function ($query) use ($data) {
                $query->where('name', $data['name']);
                $mac = $data['mac_address'];
                $query->orWhereHas('interfaces', fn ($query) => $query->where('mac_address', $mac))
                    ->orWhereHas('ipAddresses', fn ($query) => $query->where('mac_address', $mac))
                    ->orWhereHas('unifiDevice', fn ($query) => $query->where('observed->mac_address', $mac));
                if ($data['ip_address'] !== null) {
                    $query->orWhereHas('ipAddresses', fn ($query) => $query->where('address', ip2long($data['ip_address'])));
                }
            });
    }

    private function hasCandidate(UnifiDevice $source): bool
    {
        return $this->candidates($source)->exists()
            || UnifiDevice::whereKeyNot($source->id)->where('observed->mac_address', $source->observed['mac_address'])->exists();
    }

    public function link(UnifiDevice $source, ?Device $device = null, bool $confirmedRelink = false): void
    {
        if ($source->device_id || ! $source->present) {
            $this->invalid('Il dispositivo è già collegato o non è presente nell’ultima lettura.');
        }
        if ($device && $device->site_id !== $source->unifiSite->site_id) {
            $this->invalid('Scegli un dispositivo della sede di destinazione.');
        }
        if ($device && ($previous = $device->unifiDevice()->lockForUpdate()->first())) {
            if (! $confirmedRelink || $previous->unifiSite->site_id !== $source->unifiSite->site_id) {
                $this->invalid('Conferma esplicitamente la sostituzione del collegamento UniFi nella stessa sede.');
            }
            $previous->update(['device_id' => null, 'status' => 'ignored']);
        }
        if (! $device) {
            $model = $this->model($source->observed);
            $device = $source->unifiSite->site->devices()->create([
                'name' => $source->observed['name'], 'manufacturer_id' => $model->manufacturer_id, 'device_model_id' => $model->id,
            ]);
            $device->addModelInterfaces($model);
        }
        $source->update(['device_id' => $device->id, 'status' => 'linked', 'maintained' => null, 'ports' => null]);
        $source->setRelation('device', $device);
        $this->ports($source);
    }

    private function model(array $data): DeviceModel
    {
        $manufacturer = Manufacturer::firstOrCreate(['name' => 'Ubiquiti']);
        $typeName = self::type($data['features']);
        $type = $typeName ? ModelType::firstOrCreate(['name' => $typeName]) : null;

        return $manufacturer->models()->firstOrCreate(['name' => $data['model']], ['model_type_id' => $type?->id]);
    }

    public static function type(array $features): ?string
    {
        $types = array_intersect_key(['switching' => 'Switch', 'accessPoint' => 'Access point', 'gateway' => 'Gateway'], array_flip($features));

        return count($types) === 1 ? reset($types) : null;
    }

    private function ports(UnifiDevice $source): void
    {
        $device = $source->device;
        if (! $device || $device->site_id !== $source->unifiSite->site_id) {
            return;
        }
        $links = collect($source->ports ?? [])->keyBy('idx');
        $interfaces = $device->interfaces()->setEagerLoads([])->lockForUpdate()->get()->keyBy('id');
        $knownIds = $links->pluck('device_interface_id')->filter()->all();
        $canCreate = $interfaces->keys()->diff($knownIds)->isEmpty();
        $ports = [];
        foreach ($source->observed['ports'] ?? [] as $port) {
            $previous = $links->get($port['idx']);
            $id = $previous['device_interface_id'] ?? null;
            if ($id !== null && ! $interfaces->has($id)) {
                $id = null;
            }
            if ($previous === null && $canCreate) {
                $id = $device->interfaces()->create(['kind' => 'physical', 'name' => 'Porta '.$port['idx']])->id;
            }
            $ports[] = [...$port, 'device_interface_id' => $id];
        }
        // Missing API ports retain their mappings, but never appear as fresh observations.
        foreach ($links as $idx => $previous) {
            if (! in_array($idx, array_column($ports, 'idx'), true)) {
                $ports[] = [...$previous, 'missing' => true];
            }
        }
        $source->update(['ports' => $ports]);
    }

    public function mapPort(UnifiDevice $source, int $idx, ?int $interfaceId): void
    {
        $ports = $source->ports ?? [];
        $position = array_search($idx, array_column($ports, 'idx'), true);
        if ($position === false || ! $source->device_id || ($ports[$position]['missing'] ?? false)) {
            $this->invalid('La porta non è disponibile. Aggiorna la lettura.');
        }
        if ($interfaceId !== null) {
            $interface = $source->device->interfaces()->where('kind', 'physical')->lockForUpdate()->findOrFail($interfaceId);
            foreach ($ports as $port) {
                if ($port['idx'] !== $idx && $port['device_interface_id'] === $interface->id) {
                    $this->invalid('L’interfaccia è già associata a un’altra porta UniFi.');
                }
            }
        } else {
            if ($ports[$position]['device_interface_id'] !== null) {
                $this->invalid('La porta ha già un’interfaccia associata.');
            }
            $interface = $source->device->interfaces()->create(['kind' => 'physical', 'name' => 'Porta '.$idx]);
        }
        $ports[$position]['device_interface_id'] = $interface->id;
        $source->update(['ports' => $ports]);
    }

    public function adopt(UnifiDevice $source, string $field, bool $update): void
    {
        $device = $source->device;
        if (! $device || $device->site_id !== $source->unifiSite->site_id) {
            $this->invalid('Il dispositivo non è più disponibile in questa sede.');
        }
        $maintained = $source->maintained ?? [];
        if ($update) {
            if ($device->snipeItAsset()->exists()) {
                $this->invalid('Aggiorna questi dati nell’asset Snipe-IT collegato.');
            }
            if ($field === 'name') {
                $device->update(['name' => $source->observed['name']]);
            } else {
                $model = $this->model($source->observed);
                if ($problem = $device->modelChangeProblem($model->id)) {
                    $this->invalid($problem);
                }
                $device->update(['device_model_id' => $model->id, 'manufacturer_id' => $model->manufacturer_id]);
                $device->addModelInterfaces($model);
            }
            unset($maintained[$field]);
        } else {
            $maintained[$field] = $source->observed[$field];
        }
        $source->update(['maintained' => $maintained]);
    }

    public function summary(UnifiSite $site): array
    {
        $site->loadMissing('unifiDevices.device');

        return [
            'activated_at' => $site->inventory_activated_at, 'last_synced_at' => $site->last_synced_at,
            'status' => $site->sync_status,
            'devices' => $site->unifiDevices->filter(fn ($source) => ! empty($source->observed['name']))->map(function (UnifiDevice $source) use ($site) {
                $source->setRelation('unifiSite', $site);
                $device = $source->device?->site_id === $site->site_id ? $source->device : null;
                $deleted = $source->status === 'linked' && ! $device;

                return [...$source->only('id', 'external_id', 'present', 'synced_at', 'observed'),
                    'status' => $deleted ? 'deleted' : $source->status,
                    'device' => $device?->only('id', 'name', 'device_model_id'),
                    'candidates' => ! $device && $site->site_id ? $this->candidates($source)->with('unifiDevice.unifiSite.unifiConnection')->get(['id', 'name', 'site_id'])->map(fn ($candidate) => [...$candidate->only('id', 'name', 'site_id'), 'current_site' => $candidate->site_id === $site->site_id, 'requires_relink' => $candidate->unifiDevice !== null, 'linked_source' => $candidate->unifiDevice?->unifiSite->unifiConnection->name])->all() : [],
                    'problem' => $deleted || $source->status === 'deleted' ? 'Il collegamento precedente è stato rimosso. Scegli esplicitamente come procedere.'
                        : (! $device && $site->site_id && $this->hasCandidate($source) ? 'Possibile apparato già documentato: verifica il collegamento.' : null),
                ];
            })->values()->all(),
        ];
    }

    public function deviceDetails(UnifiDevice $source): ?array
    {
        $site = $source->unifiSite;
        $device = $source->device;
        if (! $device || $site->site_id !== $device->site_id) {
            return null;
        }
        $connection = $site->unifiConnection;
        $snipe = $device->snipeItAsset()->exists();
        $differences = [];
        foreach (['name' => $device->name, 'model' => $device->model?->name] as $field => $local) {
            if ($local !== $source->observed[$field]) {
                $differences[] = ['field' => $field, 'local' => $local, 'observed' => $source->observed[$field],
                    'maintained' => ($source->maintained[$field] ?? null) === $source->observed[$field], 'can_update' => ! $snipe];
            }
        }

        return [
            'id' => $source->id, 'connection_id' => $connection->id, 'revision' => $connection->revision,
            'source_name' => $connection->name, 'site_name' => $site->name, 'synced_at' => $source->synced_at,
            'stale' => $source->stale(),
            'observed' => $source->observed, 'ports' => $source->ports ?? [], 'differences' => $differences, 'snipe_owned' => $snipe,
        ];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['inventory' => $message]);
    }
}
