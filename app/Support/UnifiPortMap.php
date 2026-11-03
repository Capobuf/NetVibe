<?php

namespace App\Support;

use App\Models\DeviceModel;
use App\Models\UnifiDevice;

class UnifiPortMap
{
    public static function type(?string $connector): ?string
    {
        return match ($connector) {
            'RJ45' => 'rj45',
            'SFP' => 'sfp',
            'QSFP' => 'qsfp',
            default => null,
        };
    }

    public static function observed(DeviceModel $model): array
    {
        return UnifiDevice::query()->where('status', 'linked')->where('present', true)
            ->whereHas('device', fn ($query) => $query->where('device_model_id', $model->id))
            ->whereHas('unifiSite', fn ($query) => $query->where('available', true)->where('sync_status', 'ok')
                ->whereNotNull('inventory_activated_at')->whereHas('unifiConnection', fn ($connection) => $connection->where('enabled', true)))
            ->with(['device', 'unifiSite'])->get()
            ->filter(fn ($source) => $source->device->site_id === $source->unifiSite->site_id)
            ->flatMap(fn ($source) => $source->observed['ports'] ?? [])
            ->groupBy('idx')->filter(fn ($ports) => $ports->pluck('connector')->unique()->count() === 1)
            ->map(fn ($ports) => $ports->first())->sortBy('idx')->values()->all();
    }

    /** @return array<string, int> Connector ID => UniFi port index. */
    public static function resolve(array $connectors, array $observed): array
    {
        $network = collect($connectors)->whereIn('type', ['rj45', 'sfp', 'qsfp']);
        $reserved = $network->pluck('unifiPort')->filter()->all();
        $available = collect($observed)->groupBy('idx')->filter(fn ($ports) => $ports->count() === 1)->map->first();
        $matches = [];
        foreach ($network as $port) {
            $explicit = array_key_exists('unifiPort', $port);
            $idx = $explicit ? $port['unifiPort'] : (preg_match('/^[1-9][0-9]*$/', trim($port['label'])) ? (int) trim($port['label']) : null);
            $source = $available->get($idx);
            if ($source && ($explicit || ! in_array($idx, $reserved, true)) && self::type($source['connector'] ?? null) === $port['type']) {
                $matches[$port['id']] = $idx;
            }
        }
        $counts = array_count_values($matches);

        return array_filter($matches, fn ($idx) => $counts[$idx] === 1);
    }
}
