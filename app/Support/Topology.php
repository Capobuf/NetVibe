<?php

namespace App\Support;

use App\Models\Cable;
use App\Models\Device;
use App\Models\MikrotikSource;
use App\Models\Site;
use App\Models\UnifiDevice;
use Illuminate\Support\Collection;

class Topology
{
    public function forSite(Site $site): array
    {
        $devices = $site->devices()->with([
            'model', 'rackItem.rack', 'mikrotikConnection',
            'interfaces' => fn ($query) => $query->without('mikrotikSource'),
        ])->orderBy('name')->orderBy('id')->get()->keyBy('id');
        $sources = MikrotikSource::where('site_id', $site->id)->whereIn('device_id', $devices->keys())
            ->whereIn('kind', ['interface', 'observation', 'vlan'])->orderByDesc('seen_at')->orderByDesc('id')->get()
            ->filter(fn ($source) => ! $devices[$source->device_id]->mikrotikConnection?->activated_at
                || $source->generation === $devices[$source->device_id]->mikrotikConnection->source_generation);
        $interfaces = $sources->where('kind', 'interface')->groupBy('device_id');
        $vlanInterfaces = $sources->where('kind', 'vlan')->where('data.menu', '/interface/vlan')->groupBy('device_id');
        $indirect = $sources->where('kind', 'observation')->filter(fn ($source) => in_array($source->data['menu'] ?? null,
            ['/ip/arp', '/ip/dhcp-server/lease', '/interface/bridge/host'], true))->groupBy('device_id');
        $nodes = $edges = $ports = $names = $macs = $logicalInterfaces = [];
        foreach ($devices as $device) {
            $logicalInterfaces[$device->id] = array_unique([
                ...$device->interfaces->where('kind', 'logical')->pluck('name')->filter()->all(),
                ...$vlanInterfaces->get($device->id, collect())->pluck('data.name')->filter()->all(),
            ]);
            $rack = $device->rackItem?->rack;
            $nodes['device:'.$device->id] = [
                'id' => 'device:'.$device->id, 'device_id' => $device->id, 'label' => $device->name,
                'model' => $device->model?->name, 'rack' => $rack?->only('id', 'name'), 'identity' => null, 'mac' => null,
                'model_id' => $device->device_model_id,
                'indirect' => $indirect->get($device->id, collect())->groupBy('data.menu')->map(fn ($items, $menu) => [
                    'menu' => $menu, 'count' => $items->count(), 'seen_at' => $items->first()->seen_at?->toIso8601String(),
                ])->values()->all(),
            ];
            $ports[$device->id] = collect($device->model?->connectors() ?? [])->keyBy('id');
            foreach (array_unique(array_filter([$device->name, $device->mikrotikConnection?->sync_identity['name'] ?? null])) as $name) {
                $names[$name][] = $device->id;
            }
            foreach ($device->interfaces as $interface) {
                if ($mac = MikrotikSnapshot::mac($interface->mac_address)) {
                    $macs[$mac][] = $device->id;
                }
            }
            foreach ($interfaces->get($device->id, collect()) as $source) {
                if (! $source->problem && ($mac = MikrotikSnapshot::mac($source->data['mac_address'] ?? null))) {
                    $macs[$mac][] = $device->id;
                }
            }
        }
        $devicesWithMacs = array_fill_keys(array_merge([], ...array_values($macs)), true);
        $cables = Cable::where('site_id', $site->id)->with('ends')->orderBy('id')->get();
        $occupied = [];
        foreach ($cables as $cable) {
            $ends = $cable->ends->keyBy('side');
            if (! isset($ends['a'], $ends['b'], $devices[$ends['a']->device_id], $devices[$ends['b']->device_id])) {
                continue;
            }
            foreach ($ends as $end) {
                $occupied[$end->device_id.':'.$end->connector_id] = $cable->id;
            }
            if (! in_array($cable->type, ['copper', 'fiber'], true)) {
                continue;
            }
            $a = $this->documentedEnd($devices[$ends['a']->device_id], $ends['a']->connector_id, $ports);
            $b = $this->documentedEnd($devices[$ends['b']->device_id], $ends['b']->connector_id, $ports);
            $edges['cable:'.$cable->id] = [
                'id' => 'cable:'.$cable->id, 'kind' => 'documented',
                'source' => 'device:'.$a['device_id'], 'target' => 'device:'.$b['device_id'],
                'a' => $a, 'b' => $b, 'protocols' => [], 'evidence' => [], 'reciprocal' => false,
                'stale' => false, 'issues' => [], 'cable_ids' => [$cable->id], 'suggestion' => null,
                'purpose' => $cable->purpose,
            ];
        }
        $neighbors = $sources->where('kind', 'observation')->filter(fn ($source) => ($source->data['menu'] ?? null) === '/ip/neighbor')
            ->unique(fn ($source) => json_encode([$source->device_id, $source->data['interface'] ?? null, $source->data['mac'] ?? null, $source->data['identity'] ?? null]));
        foreach ($neighbors as $source) {
            $device = $devices[$source->device_id];
            $data = $source->data;
            $reportedNames = array_filter(array_map('trim', explode(',', $data['interface'] ?? '')));
            $localNames = array_values(array_diff($reportedNames, $logicalInterfaces[$device->id]));
            $a = $this->observedEnd($device, $interfaces->get($device->id, collect()), $localNames, $ports);
            // A VLAN observation cannot imply a physical link through its parent.
            if (count($localNames) < count($reportedNames) && ! $a) {
                continue;
            }
            $lldp = $data['lldp'] ?? [];
            $identity = $lldp['lldp-system-name'] ?? $data['identity'] ?? null;
            $remoteMac = MikrotikSnapshot::mac($data['mac'] ?? null);
            $chassis = ($lldp['lldp-chassis-id-subtype'] ?? null) === 'mac-address'
                ? MikrotikSnapshot::mac($lldp['lldp-chassis-id'] ?? null) : null;
            // Require corroborating inventory data, never a name or MAC alone.
            $macCandidates = array_unique([...($macs[$remoteMac ?? ''] ?? []), ...($macs[$chassis ?? ''] ?? [])]);
            $candidates = array_values(array_intersect($names[$identity ?? ''] ?? [], $macCandidates));
            $remote = count($candidates) === 1 && $candidates[0] !== $device->id ? $devices[$candidates[0]] : null;
            $inventoryMatch = $remote ? 'name_mac' : null;
            if (! $remote && empty($macCandidates) && ! empty($data['board'])) {
                $modelCandidates = array_values(array_unique(array_filter($names[$identity ?? ''] ?? [],
                    fn ($id) => $id !== $device->id && ! isset($devicesWithMacs[$id])
                        && $devices[$id]->model?->name === $data['board'])));
                if (count($modelCandidates) === 1) {
                    $remote = $devices[$modelCandidates[0]];
                    $inventoryMatch = 'name_model';
                }
            }
            $target = $remote ? 'device:'.$remote->id : 'neighbor:'.$source->id;
            if (! $remote) {
                $nodes[$target] = [
                    'id' => $target, 'device_id' => null, 'label' => $identity ?: ($remoteMac ?: 'Neighbor non identificato'),
                    'model' => $data['board'] ?? $data['platform'] ?? null, 'rack' => null,
                    'model_id' => null,
                    'identity' => $identity, 'mac' => $remoteMac, 'indirect' => [],
                ];
            }
            $protocols = array_values(array_intersect(['lldp', 'cdp', 'mndp'], explode(',', $data['discovered-by'] ?? '')));
            if ($lldp && ! in_array('lldp', $protocols, true)) {
                $protocols[] = 'lldp';
            }
            $remoteName = null;
            if (($lldp['lldp-port-id-subtype'] ?? null) === 'interface-name') {
                $remoteName = $lldp['lldp-port-id'] ?? null;
            } elseif (! $lldp && in_array('cdp', $protocols, true)) {
                $remoteName = $data['interface-name'] ?? null;
            }
            if ($remote && $remoteName && in_array($remoteName, $logicalInterfaces[$remote->id], true)) {
                continue;
            }
            $b = $remote && $remoteName ? $this->observedEnd($remote, $interfaces->get($remote->id, collect()), [$remoteName], $ports) : null;
            $stale = $this->stale($source, $device) || ($lldp && ! ($data['lldp_read'] ?? false))
                || ($a['stale'] ?? false) || ($b['stale'] ?? false);
            $known = $a && $b && count(array_intersect(['lldp', 'cdp'], $protocols)) > 0;
            $evidence = [
                'device_id' => $device->id, 'source' => $device->name, 'seen_at' => $source->seen_at?->toIso8601String(),
                'inventory_match' => $inventoryMatch,
                'state' => $source->state, 'stale' => (bool) $stale, 'protocols' => $protocols,
                'local_port' => $localNames ? implode(',', $localNames) : null, 'remote_port' => $remoteName,
                'remote_port_description' => $lldp['lldp-port-description'] ?? null,
                'remote_port_mac' => ($lldp['lldp-port-id-subtype'] ?? null) === 'mac-address'
                    ? MikrotikSnapshot::mac($lldp['lldp-port-id'] ?? null) : null,
                'remote_identity' => $identity, 'remote_mac' => $remoteMac,
                'chassis_id' => $lldp['lldp-chassis-id'] ?? null,
                'lldp_seen_at' => $data['lldp_seen_at'] ?? null,
                'menu' => $lldp ? '/ip/neighbor + /ip/neighbor/lldp' : '/ip/neighbor',
            ];
            $edge = [
                'id' => 'neighbor:'.$source->id, 'kind' => $known ? 'discovered' : 'partial',
                'source' => 'device:'.$device->id, 'target' => $target, 'a' => $a, 'b' => $b,
                'protocols' => $protocols, 'evidence' => [$evidence], 'reciprocal' => false,
                'stale' => (bool) $stale, 'issues' => [], 'cable_ids' => [], 'suggestion' => null, 'purpose' => null,
            ];
            if ($source->problem) {
                $edge['issues'][] = $source->problem;
            }
            if ($known) {
                $pair = [$a['key'], $b['key']];
                sort($pair);
                $key = 'adjacency:'.implode('|', $pair);
                if (isset($edges[$key])) {
                    $edges[$key]['evidence'][] = $evidence;
                    $edges[$key]['protocols'] = array_values(array_unique([...$edges[$key]['protocols'], ...$protocols]));
                    $edges[$key]['stale'] = $edges[$key]['stale'] || $stale;

                    continue;
                }
                $edge['id'] = $key;
            } else {
                $key = $edge['id'];
            }
            $edges[$key] = $edge;
        }
        $unifi = UnifiDevice::whereIn('device_id', $devices->keys())
            ->whereHas('unifiSite', fn ($query) => $query->where('site_id', $site->id))
            ->with('unifiSite.unifiConnection')->get();
        $byExternalId = $unifi->groupBy(fn ($source) => $source->unifi_site_id.':'.$source->external_id);
        foreach ($unifi as $source) {
            $parentId = $source->observed['uplink_device_id'] ?? null;
            $matches = $parentId ? $byExternalId->get($source->unifi_site_id.':'.$parentId, collect()) : collect();
            if ($matches->count() !== 1 || $matches->first()->device_id === $source->device_id) {
                continue;
            }
            $parent = $matches->first();
            $stale = $source->stale() || $parent->stale();
            $edges['unifi:'.$source->id] = [
                'id' => 'unifi:'.$source->id, 'kind' => 'logical',
                'source' => 'device:'.$source->device_id, 'target' => 'device:'.$parent->device_id,
                'a' => null, 'b' => null, 'protocols' => ['unifi'], 'reciprocal' => false,
                'stale' => $stale, 'issues' => [], 'cable_ids' => [], 'suggestion' => null, 'purpose' => null,
                'evidence' => [[
                    'device_id' => $source->device_id, 'source' => 'UniFi · '.$source->unifiSite->name,
                    'seen_at' => $source->synced_at?->toIso8601String(), 'inventory_match' => 'external_id',
                    'state' => $source->present ? 'current' : 'missing', 'stale' => $stale,
                    'protocols' => ['unifi'], 'local_port' => null, 'remote_port' => null,
                    'remote_port_description' => null, 'remote_port_mac' => null,
                    'remote_identity' => $parent->observed['name'] ?? null, 'remote_mac' => null,
                    'chassis_id' => null, 'lldp_seen_at' => null, 'menu' => 'Uplink UniFi',
                ]],
            ];
        }
        $peers = [];
        foreach ($edges as $edge) {
            if ($edge['kind'] === 'discovered' && ! $edge['stale']) {
                $peers[$edge['a']['key']][$edge['b']['key']] = true;
                $peers[$edge['b']['key']][$edge['a']['key']] = true;
            }
        }
        foreach ($edges as $key => &$edge) {
            if ($edge['kind'] === 'documented') {
                continue;
            }
            $edge['reciprocal'] = $edge['kind'] === 'discovered' && count(array_unique(array_column(
                array_filter($edge['evidence'], fn ($evidence) => ! $evidence['stale']), 'device_id'
            ))) === 2;
            $a = $edge['a'];
            $b = $edge['b'];
            if (($a && count($peers[$a['key']] ?? []) > 1) || ($b && count($peers[$b['key']] ?? []) > 1)) {
                $edge['issues'][] = 'Più adiacenze riportano la stessa porta. Verifica le terminazioni.';
            }
            if (! $a || ! $b || ! $a['connector_id'] || ! $b['connector_id']) {
                continue;
            }
            $aCable = $occupied[$a['device_id'].':'.$a['connector_id']] ?? null;
            $bCable = $occupied[$b['device_id'].':'.$b['connector_id']] ?? null;
            $edge['cable_ids'] = array_values(array_unique(array_filter([$aCable, $bCable])));
            if ($aCable && $aCable === $bCable) {
                if (isset($edges['cable:'.$aCable])) {
                    $edges['cable:'.$aCable]['evidence'] = [...$edges['cable:'.$aCable]['evidence'], ...$edge['evidence']];
                    $edges['cable:'.$aCable]['reciprocal'] = $edge['reciprocal'];
                    $edges['cable:'.$aCable]['protocols'] = $edge['protocols'];
                    $edges['cable:'.$aCable]['issues'] = array_values(array_unique([...$edges['cable:'.$aCable]['issues'], ...$edge['issues']]));
                    unset($edges[$key]);
                }

                continue;
            }
            if ($aCable || $bCable) {
                $edge['issues'][] = 'Terminazioni già documentate su altri cavi: '.implode(', ', array_map(fn ($id) => 'C-'.$id, $edge['cable_ids'])).'. Verifica l’eventuale percorso intermedio.';

                continue;
            }
            $aPort = $ports[$a['device_id']]->get($a['connector_id']);
            $bPort = $ports[$b['device_id']]->get($b['connector_id']);
            $compatible = $aPort && $bPort && array_intersect(RackCabling::connectionTypes($aPort['type']), RackCabling::connectionTypes($bPort['type']));
            $rack = $devices[$a['device_id']]->rackItem?->rack_id ?? $devices[$b['device_id']]->rackItem?->rack_id;
            if ($edge['kind'] === 'discovered' && ! $edge['stale'] && ! $a['stale'] && ! $b['stale'] && ! $edge['issues'] && $rack && $compatible) {
                $edge['suggestion'] = [
                    'rack_id' => $rack,
                    'a' => ['device_id' => $a['device_id'], 'connector_id' => $a['connector_id']],
                    'b' => ['device_id' => $b['device_id'], 'connector_id' => $b['connector_id']],
                ];
            }
        }
        unset($edge);
        $integrations = $devices->filter(fn ($device) => $device->mikrotikConnection)->map(function ($device) {
            $connection = $device->mikrotikConnection;

            return ['device_id' => $device->id, 'vendor' => 'mikrotik', 'name' => $device->name, 'seen_at' => $connection->synced_at?->toIso8601String(),
                'stale' => ! $connection->activated_at || $connection->sync_status !== 'ok', 'status' => $connection->sync_status];
        })->values()->all();

        foreach ($unifi as $source) {
            $integrations[] = ['device_id' => $source->device_id, 'vendor' => 'unifi', 'name' => 'UniFi · '.$devices[$source->device_id]->name,
                'seen_at' => $source->synced_at?->toIso8601String(), 'stale' => $source->stale(),
                'status' => $source->unifiSite->sync_status];
        }

        $models = $devices->pluck('model')->filter(fn ($model) => $model?->panel)->unique('id')
            ->mapWithKeys(fn ($model) => [$model->id => ['panel' => $model->panel, 'assetUrls' => $model->assetUrls()]])->all();

        return ['nodes' => array_values($nodes), 'edges' => array_values($edges), 'integrations' => $integrations, 'models' => $models];
    }

    private function stale(MikrotikSource $source, Device $device): bool
    {
        $connection = $device->mikrotikConnection;

        return $source->state !== 'current' || ! $connection?->activated_at
            || $source->generation !== $connection->source_generation || $connection->sync_status !== 'ok';
    }

    private function documentedEnd(Device $device, string $connector, array $ports): array
    {
        $interface = $device->interfaces->firstWhere('connector_id', $connector);
        $port = $ports[$device->id]->get($connector);

        return ['device_id' => $device->id, 'interface_id' => $interface?->id, 'connector_id' => $connector,
            'label' => $interface?->name ?: ($port['label'] ?? $connector), 'key' => $device->id.':'.$connector, 'stale' => false];
    }

    private function observedEnd(Device $device, Collection $sources, array $names, array $ports): ?array
    {
        $matches = $sources->filter(fn ($source) => ! $source->problem && in_array($source->data['name'] ?? null, $names, true));
        if ($matches->count() === 1) {
            $source = $matches->first();
            $interface = $device->interfaces->firstWhere('id', $source->device_interface_id);
            $connector = $interface?->connector_id;
            $connector = $connector && $ports[$device->id]->has($connector) ? $connector : null;

            return ['device_id' => $device->id, 'interface_id' => $interface?->id,
                'connector_id' => $connector,
                'label' => $source->data['name'], 'key' => $connector ? $device->id.':'.$connector : $device->id.':interface:'.$source->data['default_name'],
                'stale' => $this->stale($source, $device)];
        }
        if ($matches->isNotEmpty()) {
            return null;
        }
        $interfaces = $device->interfaces->filter(fn ($interface) => $interface->kind === 'physical' && $interface->name && in_array($interface->name, $names, true));
        if ($interfaces->isEmpty()) {
            $mapped = $ports[$device->id]->filter(fn ($port) => ! empty($port['mikrotikName'])
                && in_array($port['mikrotikName'], $names, true));
            if ($mapped->isEmpty()) {
                $automatic = MikrotikPortMap::resolve($ports[$device->id]->values()->all(), $names);
                $mapped = $ports[$device->id]->only(array_keys($automatic));
            }
            if ($mapped->count() === 1) {
                return $this->documentedEnd($device, $mapped->first()['id'], $ports);
            }
        }
        if ($interfaces->count() !== 1) {
            return null;
        }
        $interface = $interfaces->first();
        $connector = $interface->connector_id && $ports[$device->id]->has($interface->connector_id) ? $interface->connector_id : null;

        return ['device_id' => $device->id, 'interface_id' => $interface->id,
            'connector_id' => $connector,
            'label' => $interface->name, 'key' => $connector ? $device->id.':'.$connector : $device->id.':documented-interface:'.$interface->id, 'stale' => false];
    }
}
