<?php

namespace App\Support;

use App\Models\Cable;
use App\Models\CableEnd;
use App\Models\Device;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RackCabling
{
    public static function connectionTypes(string $connector): array
    {
        return match ($connector) {
            'rj45', 'console' => ['copper'],
            'sfp', 'qsfp' => ['copper', 'fiber'],
            'usbA', 'usbC', 'usbMiniB', 'usbMicroB' => ['usb'],
            'ac', 'acC19', 'acC7', 'acC5', 'schuko', 'dc' => ['power'],
            default => [],
        };
    }

    // Called within the rack transaction, under the company lock shared by device deletions.
    public static function save(Site $site, array $data): void
    {
        $site = Site::query()->lockForUpdate()->findOrFail($site->id);
        if ((int) $site->cabling_revision !== (int) $data['cabling_revision']) {
            self::fail('I cablaggi della sede sono cambiati. Ricarica la pagina prima di riprovare.');
        }
        $existing = Cable::query()->where('site_id', $site->id)->with('ends')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $incoming = collect($data['cables']);
        $keptIds = $incoming->pluck('id')->filter(fn ($id) => $id > 0);
        $removed = $existing->keys()->diff($keptIds);
        if ($keptIds->diff($existing->keys())->isNotEmpty()
            || $removed->diff($data['removed_cable_ids'] ?? [])->isNotEmpty()) {
            self::fail('Un cavo non è disponibile o non è stato scollegato esplicitamente. Ricarica la pagina.');
        }
        $deviceIds = $incoming->flatMap(fn ($cable) => [$cable['a']['device_id'], $cable['b']['device_id']])->unique();
        $devices = $site->devices()->whereKey($deviceIds)->orderBy('id')->lockForUpdate()
            ->with(['model' => fn ($query) => $query->sharedLock()])->get()->keyBy('id');
        $seen = [];
        foreach ($incoming as $cable) {
            foreach (['a', 'b'] as $side) {
                $end = $cable[$side];
                $device = $devices->get($end['device_id']);
                $port = collect($device?->model?->connectors() ?? [])->firstWhere('id', $end['connector_id']);
                if (! $port) {
                    self::fail('Scegli due porte fisiche esistenti su dispositivi di questa sede.');
                }
                if (! in_array($cable['type'], self::connectionTypes($port['type']), true)) {
                    self::fail('Il tipo di cavo non è compatibile con la porta «'.($port['label'] ?: $port['id']).'» di «'.$device->name.'».');
                }
                $key = $device->id.':'.$port['id'];
                if (isset($seen[$key])) {
                    self::fail('La porta «'.($port['label'] ?: $port['id']).'» di «'.$device->name.'» è già occupata. Scollega esplicitamente il cavo prima di riutilizzarla.');
                }
                $seen[$key] = true;
            }
        }
        $changed = $incoming->filter(function ($cable) use ($existing) {
            $old = $existing->get($cable['id'])?->workspaceData();

            return ! $old || $old != $cable;
        });
        // Release only explicitly changed terminations, allowing atomic swaps without losing cable IDs.
        CableEnd::query()->whereIn('cable_id', $changed->pluck('id'))->delete();
        Cable::query()->whereKey($removed)->delete();
        foreach ($changed as $cable) {
            $record = $existing->get($cable['id']) ?? new Cable(['site_id' => $site->id]);
            $record->fill(collect($cable)->only(['type', 'color', 'purpose', 'name'])->all())->save();
            $record->ends()->createMany([
                ['side' => 'a', ...$cable['a']], ['side' => 'b', ...$cable['b']],
            ]);
        }
        if ($changed->isNotEmpty() || $removed->isNotEmpty()) {
            $site->increment('cabling_revision');
        }
    }

    /** Site devices with the ports the cabling editors connect. */
    public static function devices(Site $site): Collection
    {
        return $site->devices()->with([
            'model', 'manufacturer:id,name', 'rackItem.rack:id,name',
            'interfaces' => fn ($query) => $query->without('mikrotikSource'),
        ])->orderBy('name')->get()
            ->map(function ($device) {
                // Ports use documented aliases, not MikroTik diagnostics.
                $device->interfaces->each->setAppends([]);

                return [...$device->toArray(), 'assetUrls' => $device->model?->assetUrls() ?? []];
            });
    }

    /** Documented cables on the ports of one device, each with the port at its other end. */
    public static function deviceLinks(Site $site, Device $device): array
    {
        $cables = Cable::query()->where('site_id', $site->id)->whereHas('ends', fn ($query) => $query->where('device_id', $device->id))
            ->with('ends')->orderBy('id')->get();
        $peers = $site->devices()->whereKey($cables->flatMap->ends->pluck('device_id')->unique())
            ->with(['model', 'interfaces' => fn ($query) => $query->without('mikrotikSource')])->get()->keyBy('id');
        $links = [];
        foreach ($cables as $cable) {
            foreach ($cable->ends->where('device_id', $device->id) as $end) {
                $other = $cable->ends->firstWhere('side', $end->side === 'a' ? 'b' : 'a');
                $peer = $peers->get($other->device_id);
                $interface = $peer?->interfaces->firstWhere('connector_id', $other->connector_id);
                $port = collect($peer?->model?->connectors() ?? [])->firstWhere('id', $other->connector_id);
                $links[] = [
                    ...$cable->only('id', 'type', 'color', 'name'), 'connector_id' => $end->connector_id,
                    'peer' => ['device_id' => $other->device_id, 'name' => $peer?->name, 'interface_id' => $interface?->id,
                        'port' => $interface?->name ?: (($port['label'] ?? '') ?: substr($other->connector_id, 0, 8))],
                ];
            }
        }

        return $links;
    }

    /** Adds one cable, committing only the explicit disconnections it needs. Called under the company lock. */
    public static function add(Site $site, array $data): void
    {
        $site = Site::query()->lockForUpdate()->findOrFail($site->id);
        $cables = Cable::query()->where('site_id', $site->id)->with('ends')->orderBy('id')->lockForUpdate()->get()->map->workspaceData();
        $newCable = ['id' => -1, ...collect($data)->except(['cabling_revision', 'removed_cable_ids'])->all()];
        // Leave unrelated drafts alone.
        $removed = $cables->whereIn('id', $data['removed_cable_ids'] ?? [])
            ->filter(fn ($cable) => collect([$cable['a'], $cable['b']])->contains(
                fn ($end) => $end == $newCable['a'] || $end == $newCable['b']
            ))->pluck('id');
        self::save($site, [
            'cabling_revision' => $data['cabling_revision'],
            'removed_cable_ids' => $removed->all(),
            'cables' => [...$cables->whereNotIn('id', $removed)->values()->all(), $newCable],
        ]);
    }

    /** Removes one documented cable of the site. Called under the company lock. */
    public static function remove(Site $site, int $cableId, int $revision): void
    {
        $site = Site::query()->lockForUpdate()->findOrFail($site->id);
        $cables = Cable::query()->where('site_id', $site->id)->with('ends')->orderBy('id')->lockForUpdate()->get()->map->workspaceData();
        if (! $cables->contains('id', $cableId)) {
            self::fail('Il collegamento non è più disponibile. Ricarica la pagina.');
        }
        self::save($site, [
            'cabling_revision' => $revision, 'removed_cable_ids' => [$cableId],
            'cables' => $cables->where('id', '!=', $cableId)->values()->all(),
        ]);
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['cables' => $message]);
    }
}
