<?php

namespace App\Support;

use App\Models\Cable;
use App\Models\CableEnd;
use App\Models\Site;
use Illuminate\Validation\ValidationException;

class RackCabling
{
    public static function connectionTypes(string $connector): array
    {
        return match ($connector) {
            'rj45', 'console' => ['copper'],
            'sfp', 'qsfp' => ['copper', 'fiber'],
            'usbA', 'usbC', 'usbMiniB', 'usbMicroB' => ['usb'],
            'ac', 'acC19', 'acC7', 'acC5', 'schuko', 'schukoBipasso', 'dc' => ['power'],
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

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['cables' => $message]);
    }
}
