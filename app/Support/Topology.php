<?php

namespace App\Support;

use App\Models\Cable;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\IpAddress;
use App\Models\MikrotikSource;
use App\Models\Site;
use App\Models\UnifiDevice;
use App\Models\UnifiObservation;
use Illuminate\Support\Collection;

class Topology
{
    /** Port observations of every site device, hidden models included, from the last forSite() call. */
    private array $observed = [];

    /** Remote device and port that LLDP or CDP report beyond each port, from the last forSite() call. */
    private array $adjacent = [];

    /**
     * What integrations report on the ports of one device, also when its model is hidden from the topology:
     * the MACs seen beyond each port, and the device and port facing it when LLDP or CDP name both.
     */
    public function portsOn(Site $site, Device $device): array
    {
        $this->forSite($site);

        return ['observed' => $this->observed[$device->id] ?? [], 'adjacent' => (object) ($this->adjacent[$device->id] ?? [])];
    }

    public function forSite(Site $site): array
    {
        $devices = $site->devices()->with([
            'model.modelType', 'rackItem.rack', 'mikrotikConnection',
            'interfaces' => fn ($query) => $query->without('mikrotikSource'),
            'ipAddresses' => fn ($query) => $query->select('id', 'device_id', 'address')->orderBy('address'),
        ])->orderBy('name')->orderBy('id')->get()->keyBy('id');
        $inGeneration = fn ($source) => ! $devices[$source->device_id]->mikrotikConnection?->activated_at
            || $source->generation === $devices[$source->device_id]->mikrotikConnection->source_generation;
        $sources = MikrotikSource::where('site_id', $site->id)->whereIn('device_id', $devices->keys())
            ->where(fn ($query) => $query->where('kind', 'interface')
                ->orWhere(fn ($query) => $query->where('kind', 'vlan')->where('data->menu', '/interface/vlan'))
                ->orWhere(fn ($query) => $query->where('kind', 'observation')->where('data->menu', '/ip/neighbor')))
            ->orderByDesc('seen_at')->orderByDesc('id')->get()->filter($inGeneration);
        $interfaces = $sources->where('kind', 'interface')->groupBy('device_id');
        $vlanInterfaces = $sources->where('kind', 'vlan')->where('data.menu', '/interface/vlan')->groupBy('device_id');
        // Indirect observations contribute counts, not individual topology links.
        $indirect = MikrotikSource::where('site_id', $site->id)->whereIn('device_id', $devices->keys())
            ->where('kind', 'observation')->whereIn('data->menu', ['/ip/arp', '/ip/dhcp-server/lease', '/interface/bridge/host'])
            ->select('device_id', 'generation', 'data->menu as menu')
            ->selectRaw('COUNT(*) as total, MAX(seen_at) as seen_at, MAX(id) as last_id')
            ->groupBy('device_id', 'generation', 'menu')->orderByDesc('seen_at')->orderByDesc('last_id')
            ->get()->filter($inGeneration)->groupBy('device_id');
        $nodes = $edges = $ports = $names = $macs = $interfaceMacs = $logicalInterfaces = [];
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
                'ips' => $device->ipAddresses->map(fn ($ip) => long2ip($ip->address))->all(), 'unifi' => null, 'observed' => [],
                'indirect' => $indirect->get($device->id, collect())->groupBy('menu')->map(fn ($items, $menu) => [
                    'menu' => $menu, 'count' => (int) $items->sum('total'), 'seen_at' => $items->first()->seen_at?->toIso8601String(),
                ])->values()->all(),
            ];
            $ports[$device->id] = collect($device->model?->connectors() ?? [])->keyBy('id');
            foreach (array_unique(array_filter([$device->name, $device->mikrotikConnection?->sync_identity['name'] ?? null])) as $name) {
                $names[$name][] = $device->id;
            }
            foreach ($device->interfaces as $interface) {
                if ($mac = MikrotikSnapshot::mac($interface->mac_address)) {
                    $macs[$mac][] = $device->id;
                    $interfaceMacs[$mac][] = $interface;
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
            $portMac = ($lldp['lldp-port-id-subtype'] ?? null) === 'mac-address'
                ? MikrotikSnapshot::mac($lldp['lldp-port-id'] ?? null) : null;
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
            // NIC firmware LLDP agents announce the card, not the host name: a MAC the technician documented
            // on exactly one inventory interface, claimed by no other device, identifies the remote device.
            $remoteInterface = null;
            if (! $remote) {
                $reported = array_unique(array_filter([$remoteMac, $chassis, $portMac]));
                $owners = array_values(array_unique(array_merge([], ...array_map(fn ($mac) => $macs[$mac] ?? [], $reported))));
                $documented = collect($reported)->flatMap(fn ($mac) => $interfaceMacs[$mac] ?? [])->unique('id');
                if (count($owners) === 1 && $owners[0] !== $device->id && $documented->count() === 1) {
                    $remote = $devices[$owners[0]];
                    $inventoryMatch = 'interface_mac';
                    // Only the port identifier proves the cabled port; a chassis MAC may belong to another port.
                    $remoteInterface = $portMac && MikrotikSnapshot::mac($documented->first()->mac_address) === $portMac
                        ? $documented->first() : null;
                }
            }
            $target = $remote ? 'device:'.$remote->id : 'neighbor:'.$source->id;
            if (! $remote) {
                $nodes[$target] = [
                    'id' => $target, 'device_id' => null, 'label' => $identity ?: ($remoteMac ?: 'Neighbor non identificato'),
                    'model' => $data['board'] ?? $data['platform'] ?? null, 'rack' => null,
                    'model_id' => null, 'ips' => [], 'unifi' => null, 'observed' => [],
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
            $b = $remote && $remoteName ? $this->observedEnd($remote, $interfaces->get($remote->id, collect()), [$remoteName], $ports)
                : ($remoteInterface ? $this->interfaceEnd($remote, $remoteInterface, $ports) : null);
            $stale = $this->stale($source, $device) || ($lldp && ! ($data['lldp_read'] ?? false))
                || ($a['stale'] ?? false) || ($b['stale'] ?? false);
            $known = $a && $b && count(array_intersect(['lldp', 'cdp'], $protocols)) > 0;
            $evidence = [
                'device_id' => $device->id, 'source' => $device->name, 'seen_at' => $source->seen_at?->toIso8601String(),
                'inventory_match' => $inventoryMatch,
                'state' => $source->state, 'stale' => (bool) $stale, 'protocols' => $protocols,
                'local_port' => $localNames ? implode(',', $localNames) : null, 'remote_port' => $remoteName,
                'remote_port_description' => $lldp['lldp-port-description'] ?? null,
                'remote_port_mac' => $portMac,
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
            $nodes['device:'.$source->device_id]['unifi'] = [
                ...array_intersect_key($source->observed, array_flip(['state', 'ip_address', 'mac_address', 'firmware_version'])),
                'stale' => $source->stale(), 'synced_at' => $source->synced_at?->toIso8601String(),
            ];
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
        $observed = $this->portObservations($site, $devices, $interfaces, $logicalInterfaces, $ports, $macs, $unifi, $byExternalId, $inGeneration);
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
                // Name what the documented cable says, so the technician can compare it with the observed link.
                $documented = [];
                foreach ([[$a, $aCable], [$b, $bCable]] as [$end, $cableId]) {
                    if (! $cableId) {
                        continue;
                    }
                    $other = $cables->firstWhere('id', $cableId)->ends
                        ->first(fn ($cableEnd) => $cableEnd->device_id !== $end['device_id'] || $cableEnd->connector_id !== $end['connector_id']);
                    $documented[] = $this->portName($devices[$end['device_id']], $end['connector_id'], $ports)
                        .' è collegata con C-'.$cableId.' a '.$this->portName($devices[$other->device_id], $other->connector_id, $ports).'.';
                }
                $edge['issues'][] = 'Diverso dalla documentazione: '.implode(' ', array_unique($documented));

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
        // LLDP or CDP name the remote port; a MAC alone never does. Ports already documented are merged above.
        $this->adjacent = [];
        foreach ($edges as $edge) {
            if ($edge['kind'] === 'discovered' && ! $edge['issues'] && $edge['a']['connector_id'] && $edge['b']['connector_id']) {
                foreach ([[$edge['a'], $edge['b']], [$edge['b'], $edge['a']]] as [$local, $remote]) {
                    $this->adjacent[$local['device_id']][$local['connector_id']] = [
                        'device_id' => $remote['device_id'], 'connector_id' => $remote['connector_id'],
                        'protocols' => $edge['protocols'], 'stale' => $edge['stale'],
                    ];
                }
            }
        }
        $this->observed = $observed;
        foreach ($observed as $deviceId => $rows) {
            $nodes['device:'.$deviceId]['observed'] = $rows;
        }
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

        // Hidden models stay documented in racks and cabling; they are dropped only here, after
        // matching and port checks, together with every edge that touches them.
        $visible = $devices->filter(fn ($device) => $device->model?->showsInTopology() ?? true);
        $hidden = $devices->diffKeys($visible)->keys()->mapWithKeys(fn ($id) => ['device:'.$id => true]);
        $nodes = array_diff_key($nodes, $hidden->all());
        $edges = array_filter($edges, fn ($edge) => ! isset($hidden[$edge['source']]) && ! isset($hidden[$edge['target']]));
        $integrations = array_filter($integrations, fn ($integration) => $visible->has($integration['device_id']));

        $models = $visible->pluck('model')->filter(fn ($model) => $model?->panel)->unique('id')
            ->mapWithKeys(fn ($model) => [$model->id => ['panel' => $model->panel, 'assetUrls' => $model->assetUrls()]])->all();

        return ['nodes' => array_values($nodes), 'edges' => array_values($edges), 'integrations' => array_values($integrations), 'models' => $models];
    }

    /**
     * MACs directly attached to each port: MikroTik bridge hosts on their learning port and UniFi clients and
     * devices on the switch port the controller reports. They are observations to consult, never cables or links;
     * a MAC names a device only when exactly one inventory device owns it, and a port only when every sighting agrees.
     */
    private function portObservations(Site $site, Collection $devices, Collection $interfaces, array $logicalInterfaces, array $ports,
        array $macs, Collection $unifi, Collection $byExternalId, \Closure $inGeneration): array
    {
        foreach ($unifi as $source) {
            if ($mac = MikrotikSnapshot::mac($source->observed['mac_address'] ?? null)) {
                $macs[$mac][] = $source->device_id;
            }
        }
        $hints = [];
        $documented = IpAddress::whereHas('subnet', fn ($query) => $query->where('site_id', $site->id))
            ->whereNotNull('mac_address')->get(['address', 'mac_address', 'hostname', 'device_id']);
        foreach ($documented as $ip) {
            if (! $mac = MikrotikSnapshot::mac($ip->mac_address)) {
                continue;
            }
            $hints[$mac]['documented'][long2ip($ip->address)] = $ip->hostname;
            if ($devices->has($ip->device_id)) {
                $macs[$mac][] = $ip->device_id;
            }
        }
        $clients = UnifiObservation::forSite($site->id)->whereIn('kind', ['client', 'device'])->where('state', 'current')
            ->with('unifiSite.unifiConnection')->orderBy('id')->get();
        $sightings = MikrotikSource::where('site_id', $site->id)->whereIn('device_id', $devices->keys())
            ->where('kind', 'observation')->where('state', 'current')->whereIn('data->menu', ['/ip/arp', '/ip/dhcp-server/lease'])
            ->get()->filter($inGeneration);
        foreach ([...$sightings->pluck('data'), ...$clients->pluck('data')] as $data) {
            if (($mac = MikrotikSnapshot::mac($data['mac'] ?? null)) && is_string($data['ip'] ?? null)) {
                $hints[$mac]['observed'][$data['ip']] = true;
                if ($name = $data['name'] ?? $data['hostname_observed'] ?? null) {
                    $hints[$mac]['names'][$name] = true;
                }
            }
        }
        $found = [];
        $hosts = MikrotikSource::where('site_id', $site->id)->whereIn('device_id', $devices->keys())
            ->where('kind', 'observation')->where('state', 'current')->where('data->menu', '/interface/bridge/host')
            ->orderBy('id')->get()->filter($inGeneration);
        foreach ($hosts as $source) {
            $mac = MikrotikSnapshot::mac($source->data['mac'] ?? null);
            // The bridge's own addresses (local, or not stated) are not hosts beyond a port.
            if (! $mac || MikrotikSnapshot::flag($source->data['local'] ?? null) !== false) {
                continue;
            }
            $device = $devices[$source->device_id];
            $reported = $source->data['on-interface'] ?? null;
            // A VLAN or other logical interface is not a physical port.
            $end = is_string($reported) && ! in_array($reported, $logicalInterfaces[$device->id], true)
                ? $this->observedEnd($device, $interfaces->get($device->id, collect()), [$reported], $ports) : null;
            $found[$device->id][$mac][] = [$end, $reported, false, ['vendor' => 'mikrotik', 'source' => 'MikroTik',
                'seen_at' => $source->seen_at?->toIso8601String(), 'stale' => $this->stale($source, $device)]];
        }
        $byMac = $unifi->groupBy(fn ($source) => $source->unifi_site_id.':'.MikrotikSnapshot::mac($source->observed['mac_address'] ?? null));
        foreach ($clients as $client) {
            $mac = MikrotikSnapshot::mac($client->data['mac'] ?? null);
            $switch = $client->data['switch'] ?? null;
            // The Network application names the switch port; without it a client keeps only its uplink device.
            $parents = $switch ? $byMac->get($client->unifi_site_id.':'.$switch['switch_mac'], collect())
                : ($client->kind === 'client' ? $byExternalId->get($client->unifi_site_id.':'.($client->data['uplink_device_id'] ?? ''), collect()) : collect());
            if (! $mac || $parents->count() !== 1) {
                continue;
            }
            $parent = $parents->first();
            $end = $switch ? $this->unifiPortEnd($devices[$parent->device_id], $parent, $switch['switch_port'], $ports) : null;
            $found[$parent->device_id][$mac][] = [$end, null, ! $switch && ($client->data['type'] ?? null) === 'WIRELESS', [
                'vendor' => 'unifi', 'source' => 'UniFi · '.$client->unifiSite->name,
                'seen_at' => $client->seen_at?->toIso8601String(), 'stale' => $client->stale() || $parent->stale()]];
        }
        $resolved = [];
        foreach ($found as $deviceId => $sightingsByMac) {
            foreach ($sightingsByMac as $mac => $sightings) {
                $ends = collect($sightings)->map(fn ($sighting) => $sighting[0])->unique(fn ($end) => $end['key'] ?? null);
                $resolved[$deviceId][$mac] = $ends->count() === 1 ? ($ends->first()['key'] ?? null) : null;
            }
        }
        $ownerOf = function (string $mac) use ($macs): ?int {
            $owners = array_values(array_unique($macs[$mac] ?? []));

            return count($owners) === 1 ? $owners[0] : null;
        };
        // Only directly attached hosts are kept: a MAC that a device seen on the same port reports on one of its
        // own ports, other than the one facing back, lies behind that device.
        $behind = [];
        foreach ($resolved as $deviceId => $keys) {
            foreach ($keys as $mac => $key) {
                $other = $key ? $ownerOf($mac) : null;
                if (! $other || $other === $deviceId || ! isset($resolved[$other])) {
                    continue;
                }
                $facing = collect($resolved[$other])->filter(fn ($otherKey, $otherMac) => $otherKey && $ownerOf($otherMac) === $deviceId)->values()->all();
                foreach ($resolved[$other] as $farMac => $farKey) {
                    if ($farKey && ! in_array($farKey, $facing, true) && ($keys[$farMac] ?? null) === $key && $ownerOf($farMac) !== $other) {
                        $behind[$deviceId][$farMac] = true;
                    }
                }
            }
        }
        $result = [];
        foreach ($found as $deviceId => $sightingsByMac) {
            $rows = [];
            foreach ($sightingsByMac as $mac => $sightings) {
                $owners = array_values(array_unique($macs[$mac] ?? []));
                if (in_array($deviceId, $owners, true) || isset($behind[$deviceId][$mac])) {
                    continue;
                }
                $ends = collect($sightings)->map(fn ($sighting) => $sighting[0])->unique(fn ($end) => $end['key'] ?? null);
                $end = $ends->count() === 1 ? $ends->first() : null;
                $owner = count($owners) === 1 ? $devices[$owners[0]] : null;
                $documentedIps = $hints[$mac]['documented'] ?? [];
                $ips = array_keys($documentedIps ?: ($hints[$mac]['observed'] ?? []));
                $names = array_keys($hints[$mac]['names'] ?? []);
                $name = $owner?->name ?? (count($documentedIps) === 1 && reset($documentedIps) ? reset($documentedIps)
                    : (count($names) === 1 ? $names[0] : null));
                $key = ($end['key'] ?? 'unknown').'|'.($owner ? 'device:'.$owner->id : 'mac:'.$mac);
                $row = $rows[$key] ?? [
                    'key' => $key, 'port' => $end['label'] ?? null, 'interface_id' => $end['interface_id'] ?? null,
                    'connector_id' => $end['connector_id'] ?? null,
                    'reported_ports' => [], 'wireless' => true, 'device_id' => $owner?->id, 'name' => $name,
                    'ips' => [], 'macs' => [], 'sources' => [],
                ];
                $row['macs'][] = $mac;
                $row['ips'] = array_values(array_unique([...$row['ips'], ...(count($ips) === 1 ? $ips : [])]));
                $row['name'] ??= $name;
                foreach ($sightings as [, $reported, $wireless, $evidence]) {
                    $row['wireless'] = $row['wireless'] && $wireless;
                    if ($reported !== null && ! in_array($reported, $row['reported_ports'], true)) {
                        $row['reported_ports'][] = $reported;
                    }
                    // One entry per source, with its latest sighting; stale only when every sighting is.
                    $previous = $row['sources'][$evidence['source']] ?? null;
                    $row['sources'][$evidence['source']] = [...$evidence,
                        'seen_at' => max($previous['seen_at'] ?? null, $evidence['seen_at']),
                        'stale' => ($previous['stale'] ?? true) && $evidence['stale']];
                }
                $rows[$key] = $row;
            }
            $rows = array_map(fn ($row) => [...$row, 'sources' => array_values($row['sources'])], array_values($rows));
            usort($rows, fn ($a, $b) => ($a['port'] === null) <=> ($b['port'] === null)
                ?: strnatcasecmp($a['port'] ?? '', $b['port'] ?? '') ?: strnatcasecmp($a['name'] ?? $a['macs'][0], $b['name'] ?? $b['macs'][0]));
            $result[$deviceId] = $rows;
        }

        return $result;
    }

    private function unifiPortEnd(Device $device, UnifiDevice $source, int $idx, array $ports): array
    {
        $port = collect($source->ports ?? [])->firstWhere('idx', $idx);
        $interface = $device->interfaces->firstWhere('id', $port['device_interface_id'] ?? null);

        return $interface ? $this->interfaceEnd($device, $interface, $ports) : ['device_id' => $device->id, 'interface_id' => null,
            'connector_id' => null, 'label' => 'Porta '.$idx, 'key' => $device->id.':unifi-port:'.$idx, 'stale' => false];
    }

    private function stale(MikrotikSource $source, Device $device): bool
    {
        $connection = $device->mikrotikConnection;

        return $source->state !== 'current' || ! $connection?->activated_at
            || $source->generation !== $connection->source_generation || $connection->sync_status !== 'ok';
    }

    private function portName(Device $device, string $connector, array $ports): string
    {
        return $device->name.' · '.$this->documentedEnd($device, $connector, $ports)['label'];
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

        return $this->interfaceEnd($device, $interfaces->first(), $ports);
    }

    private function interfaceEnd(Device $device, DeviceInterface $interface, array $ports): array
    {
        $connector = $interface->connector_id && $ports[$device->id]->has($interface->connector_id) ? $interface->connector_id : null;

        return $connector ? $this->documentedEnd($device, $connector, $ports) : ['device_id' => $device->id, 'interface_id' => $interface->id,
            'connector_id' => null, 'label' => $interface->name, 'key' => $device->id.':documented-interface:'.$interface->id, 'stale' => false];
    }
}
