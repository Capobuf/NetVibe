<?php

declare(strict_types=1);

namespace RemoteAgent\Support;

/**
 * Costruisce il payload normalizzato `device_result` a partire
 * dalle risposte Mikrotik REST, in accordo con il contratto PDL.
 *
 * Questo builder è stateless a parte il limite max_records_per_dataset.
 */
class DeviceResultBuilder
{
    private int $maxRecordsPerDataset;

    public function __construct(int $maxRecordsPerDataset = 5000)
    {
        $this->maxRecordsPerDataset = $maxRecordsPerDataset;
    }

    public function setMaxRecordsPerDataset(int $maxRecordsPerDataset): void
    {
        $this->maxRecordsPerDataset = $maxRecordsPerDataset > 0 ? $maxRecordsPerDataset : 5000;
    }

    /**
     * Costruisce un device_result completo partendo dai blocchi Mikrotik REST.
     *
     * @param string $targetIp IP/host usato per raggiungere il device (management IP).
     */
    public function fromMikrotik(
        string $targetIp,
        array $resource,
        array $identity,
        array $interfaces,
        array $bridgePorts,
        array $bridgeVlan,
        array $bridges,
        array $ipAddresses,
        array $arp,
        array $dhcp,
        array $neighbors,
        array $bridgeHosts
    ): array {
        $errors = [];

        // Normalizza blocchi: se contengono 'error', segnaliamo e azzeriamo gli items.
        [$resource, $errors] = $this->extractErrorAware($resource, $errors, 'mikrotik_rest', '/rest/system/resource');
        [$identity, $errors] = $this->extractErrorAware($identity, $errors, 'mikrotik_rest', '/rest/system/identity');
        [$interfaces, $errors] = $this->extractListErrorAware($interfaces, $errors, 'mikrotik_rest', '/rest/interface');
        [$bridgePorts, $errors] = $this->extractListErrorAware($bridgePorts, $errors, 'mikrotik_rest', '/rest/interface/bridge/port');
        [$bridgeVlan, $errors] = $this->extractListErrorAware($bridgeVlan, $errors, 'mikrotik_rest', '/rest/interface/bridge/vlan');
        [$bridges, $errors] = $this->extractListErrorAware($bridges, $errors, 'mikrotik_rest', '/rest/interface/bridge');
        [$ipAddresses, $errors] = $this->extractListErrorAware($ipAddresses, $errors, 'mikrotik_rest', '/rest/ip/address');
        [$arp, $errors] = $this->extractListErrorAware($arp, $errors, 'mikrotik_rest', '/rest/ip/arp');
        [$dhcp, $errors] = $this->extractListErrorAware($dhcp, $errors, 'mikrotik_rest', '/rest/ip/dhcp-server/lease');
        [$neighbors, $errors] = $this->extractListErrorAware($neighbors, $errors, 'mikrotik_rest', '/rest/ip/neighbor');
        [$bridgeHosts, $errors] = $this->extractListErrorAware($bridgeHosts, $errors, 'mikrotik_rest', '/rest/interface/bridge/host');

        $device = $this->buildDeviceSection($targetIp, $resource, $identity);
        $interfacesSection = $this->buildInterfacesSection($interfaces);
        $vlansSection = $this->buildVlansSection($bridgeVlan);
        $bridgePortsSection = $this->buildBridgePortsSection($bridgePorts);
        $bridgesSection = $this->buildBridgesSection($bridges, $bridgePorts, $bridgeVlan);
        $ipAddressesSection = $this->buildIpAddressesSection($ipAddresses);
        $arpSection = $this->buildArpSection($arp);
        $dhcpSection = $this->buildDhcpSection($dhcp);
        $neighborsSection = $this->buildNeighborsSection($neighbors);
        $bridgeHostsSection = $this->buildBridgeHostsSection($bridgeHosts);

        return [
            'schema_version' => '1.0.0',
            'device' => $device,
            'interfaces' => $interfacesSection,
            'vlans' => $vlansSection,
            'bridge_ports' => $bridgePortsSection,
            'bridges' => $bridgesSection,
            'ip_addresses' => $ipAddressesSection,
            'arp' => $arpSection,
            'dhcp_leases' => $dhcpSection,
            'neighbors' => $neighborsSection,
            'bridge_hosts' => $bridgeHostsSection,
            'errors' => $errors,
        ];
    }

    private function buildDeviceSection(string $ip, array $resource, array $identity): array
    {
        $uptimeSeconds = null;
        if (! empty($resource['uptime']) && is_string($resource['uptime'])) {
            $uptimeSeconds = $this->parseDurationToSeconds($resource['uptime']);
        }

        return [
            'ip' => $ip,
            'identity' => $identity['name'] ?? '',
            'model' => $resource['board-name'] ?? ($resource['model'] ?? ''),
            'serial' => $resource['serial-number'] ?? ($resource['serial'] ?? null),
            'os_version' => $resource['version'] ?? ($resource['firmware'] ?? ''),
            'board' => $resource['board-name'] ?? null,
            'platform' => 'mikrotik',
            'uptime_seconds' => $uptimeSeconds ?? 0,
            'architecture' => $resource['architecture-name'] ?? null,
        ];
    }

    private function buildInterfacesSection(array $interfaces): array
    {
        $items = [];

        foreach ($interfaces as $idx => $iface) {
            if (! is_array($iface)) {
                continue;
            }

            $name = $iface['name'] ?? $iface['default-name'] ?? null;
            if ($name === null) {
                continue;
            }

            $type = $this->mapInterfaceType((string) ($iface['type'] ?? ''));
            $mac = $iface['mac-address'] ?? null;

            $speedBps = null;
            if (isset($iface['speed'])) {
                $speedBps = $this->parseSpeedToBps((string) $iface['speed']);
            } elseif (isset($iface['actual-speed'])) {
                $speedBps = $this->parseSpeedToBps((string) $iface['actual-speed']);
            }

            $adminStatus = ! empty($iface['disabled']) && $iface['disabled'] !== 'false' ? 'down' : 'up';
            $operStatus = ! empty($iface['running']) && $iface['running'] !== 'false' ? 'up' : 'down';

            $items[] = [
                'index' => $this->mapInterfaceIndex($iface, $idx),
                'name' => $name,
                'type' => $type,
                'mac' => $mac,
                'speed_bps' => $speedBps,
                'admin_status' => $adminStatus,
                'oper_status' => $operStatus,
                'description' => $iface['comment'] ?? null,
            ];
        }

        return [
            'items' => $items,
            'partial' => false,
        ];
    }

    private function buildVlansSection(array $bridgeVlan): array
    {
        $seen = [];
        $items = [];

        foreach ($bridgeVlan as $row) {
            if (! is_array($row) || empty($row['vlan-ids'])) {
                continue;
            }

            $ids = $this->parseVlanIds((string) $row['vlan-ids']);
            foreach ($ids as $id) {
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $items[] = [
                    'vlan_id' => $id,
                    'name' => null,
                ];
            }
        }

        return [
            'items' => $items,
            'partial' => false,
        ];
    }

    private function buildBridgePortsSection(array $bridgePorts): array
    {
        $items = [];
        foreach ($bridgePorts as $row) {
            if (! is_array($row)) {
                continue;
            }

            $items[] = [
                'interface' => $row['interface'] ?? ($row['name'] ?? ''),
                'bridge' => $row['bridge'] ?? null,
                'pvid' => isset($row['pvid']) ? (int) $row['pvid'] : null,
                'frame_types' => $row['frame-types'] ?? ($row['frame_types'] ?? null),
                'ingress_filtering' => isset($row['ingress-filtering']) ? $row['ingress-filtering'] : null,
            ];
        }

        return [
            'items' => $items,
            'partial' => false,
        ];
    }

    private function buildBridgesSection(array $bridges, array $bridgePorts, array $bridgeVlan): array
    {
        $bridgeMap = [];

        foreach ($bridges as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $row['name'] ?? 'default';
            $bridgeMap[$name] = [
                'name' => $name,
                'vlan_mode' => '802.1q',
                'vlan_filtering' => isset($row['vlan-filtering']) ? filter_var($row['vlan-filtering'], FILTER_VALIDATE_BOOLEAN) : null,
                'pvid' => isset($row['pvid']) ? (int) $row['pvid'] : null,
                'ports' => [],
                'vlans' => [],
            ];
        }

        foreach ($bridgePorts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $bridgeName = $row['bridge'] ?? 'default';
            $portName = $row['interface'] ?? ($row['name'] ?? null);
            if (! $portName) {
                continue;
            }
            if (! isset($bridgeMap[$bridgeName])) {
                $bridgeMap[$bridgeName] = [
                    'name' => $bridgeName,
                    'vlan_mode' => '802.1q',
                    'vlan_filtering' => null,
                    'pvid' => null,
                    'ports' => [],
                    'vlans' => [],
                ];
            }

            $bridgeMap[$bridgeName]['ports'][$portName] = [
                'name' => $portName,
                'pvid' => isset($row['pvid']) ? (int) $row['pvid'] : null,
                'frame_types' => $row['frame-types'] ?? ($row['frame_types'] ?? null),
                'tagged' => [],
                'untagged' => false,
            ];
        }

        foreach ($bridgeVlan as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['disabled']) && filter_var($row['disabled'], FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $bridgeName = $row['bridge'] ?? 'default';
            $vlanIds = $this->parseVlanIds((string) ($row['vlan-ids'] ?? ''));
            $taggedPorts = $this->parseListToArray($row['tagged'] ?? ($row['tagged-ports'] ?? ($row['tagged_ports'] ?? ($row['ports'] ?? []))));
            $untaggedPorts = $this->parseListToArray($row['untagged'] ?? ($row['untagged-ports'] ?? ($row['untagged_ports'] ?? [])));

            if (! isset($bridgeMap[$bridgeName])) {
                $bridgeMap[$bridgeName] = [
                    'name' => $bridgeName,
                    'vlan_mode' => '802.1q',
                    'vlan_filtering' => null,
                    'pvid' => null,
                    'ports' => [],
                    'vlans' => [],
                ];
            }

            foreach ($vlanIds as $vid) {
                $bridgeMap[$bridgeName]['vlans'][] = [
                    'vlan_id' => $vid,
                    'tagged_ports' => $taggedPorts,
                    'untagged_ports' => $untaggedPorts,
                ];

                foreach ($taggedPorts as $p) {
                    if (! isset($bridgeMap[$bridgeName]['ports'][$p])) {
                        $bridgeMap[$bridgeName]['ports'][$p] = [
                            'name' => $p,
                            'pvid' => null,
                            'frame_types' => null,
                            'tagged' => [],
                            'untagged' => false,
                        ];
                    }
                    $bridgeMap[$bridgeName]['ports'][$p]['tagged'][] = $vid;
                }

                foreach ($untaggedPorts as $p) {
                    if (! isset($bridgeMap[$bridgeName]['ports'][$p])) {
                        $bridgeMap[$bridgeName]['ports'][$p] = [
                            'name' => $p,
                            'pvid' => null,
                            'frame_types' => null,
                            'tagged' => [],
                            'untagged' => false,
                        ];
                    }
                    $bridgeMap[$bridgeName]['ports'][$p]['untagged'] = true;
                }
            }
        }

        $items = [];
        foreach ($bridgeMap as $bridge) {
            // normalize port map to array values
            $bridge['ports'] = array_values($bridge['ports']);
            $items[] = $bridge;
        }

        return [
            'items' => $items,
            'partial' => false,
        ];
    }

    private function buildIpAddressesSection(array $ipAddresses): array
    {
        $items = [];
        foreach ($ipAddresses as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['disabled']) && filter_var($row['disabled'], FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $items[] = [
                'address' => $row['address'] ?? null,
                'interface' => $row['interface'] ?? ($row['actual-interface'] ?? null),
                'network' => $row['network'] ?? null,
                'dynamic' => isset($row['dynamic']) ? filter_var($row['dynamic'], FILTER_VALIDATE_BOOLEAN) : null,
            ];
        }

        return [
            'items' => $items,
            'partial' => false,
        ];
    }

    private function buildArpSection(array $arp): array
    {
        $entries = [];
        foreach ($arp as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ip = $row['address'] ?? $row['ip'] ?? null;
            $mac = $row['mac-address'] ?? $row['mac'] ?? null;
            if (! $ip || ! $mac) {
                continue;
            }

            $lastSeen = null;
            if (! empty($row['last-seen']) && is_string($row['last-seen'])) {
                $lastSeen = $this->parseDurationToSeconds($row['last-seen']);
            }

            $entries[] = [
                'ip' => $ip,
                'mac' => $mac,
                'interface' => $row['interface'] ?? '',
                'last_seen_seconds' => $lastSeen,
            ];
        }

        [$items, $partial, $limit] = $this->truncateWithLimit($entries);

        return [
            'items' => $items,
            'partial' => $partial,
            'limit' => $limit,
        ];
    }

    private function buildDhcpSection(array $dhcp): array
    {
        $entries = [];
        foreach ($dhcp as $row) {
            if (! is_array($row)) {
                continue;
            }

            $ip = $row['address'] ?? $row['ip'] ?? null;
            $mac = $row['mac-address'] ?? $row['mac'] ?? null;
            if (! $ip || ! $mac) {
                continue;
            }

            $expiresAt = $row['expires-at'] ?? $row['expires-after'] ?? null;

            $entries[] = [
                'ip' => $ip,
                'mac' => $mac,
                'hostname' => $row['host-name'] ?? $row['hostname'] ?? null,
                'expires_at' => $expiresAt,
            ];
        }

        // Fase 1: non applichiamo limit a DHCP; se necessario verrà introdotto
        return [
            'items' => $entries,
            'partial' => false,
            'limit' => null,
        ];
    }

    private function buildNeighborsSection(array $neighbors): array
    {
        $items = [];
        foreach ($neighbors as $row) {
            if (! is_array($row)) {
                continue;
            }

            $items[] = [
                'local_interface' => $row['interface'] ?? '',
                'remote_name' => $row['identity'] ?? ($row['mac-address'] ?? null),
                'remote_port' => $row['port'] ?? null,
                'remote_mac' => $row['mac-address'] ?? null,
                'remote_ip' => $row['address'] ?? null,
            ];
        }

        return [
            'items' => $items,
            'partial' => false,
        ];
    }

    private function buildBridgeHostsSection(array $bridgeHosts): array
    {
        $items = [];
        foreach ($bridgeHosts as $row) {
            if (! is_array($row)) {
                continue;
            }

            $items[] = [
                'mac' => $row['mac-address'] ?? '',
                'interface' => $row['interface'] ?? '',
                'vlan_id' => isset($row['vlan-id']) ? (int) $row['vlan-id'] : null,
                'is_static' => ! empty($row['static']) && $row['static'] !== 'false',
            ];
        }

        return [
            'items' => $items,
            'partial' => false,
        ];
    }

    private function extractErrorAware(array $data, array $errors, string $source, string $endpoint): array
    {
        if (isset($data['error'])) {
            $errors[] = [
                'code' => $this->mapErrorCode((string) $data['error']),
                'source' => $source,
                'message' => $data['message'] ?? '',
                'details' => [
                    'endpoint' => $endpoint,
                    'status' => $data['status'] ?? null,
                ],
            ];
            return [[], $errors];
        }

        return [$data, $errors];
    }

    private function extractListErrorAware(array $data, array $errors, string $source, string $endpoint): array
    {
        if (isset($data['error'])) {
            $errors[] = [
                'code' => $this->mapErrorCode((string) $data['error']),
                'source' => $source,
                'message' => $data['message'] ?? '',
                'details' => [
                    'endpoint' => $endpoint,
                    'status' => $data['status'] ?? null,
                ],
            ];
            return [[], $errors];
        }

        // Se la risposta è un oggetto singolo, non una lista, normalizza a lista vuota
        if (! $this->isList($data)) {
            return [[], $errors];
        }

        return [$data, $errors];
    }

    private function truncateWithLimit(array $items): array
    {
        $limit = $this->maxRecordsPerDataset;

        if (count($items) <= $limit) {
            return [$items, false, null];
        }

        return [array_slice($items, 0, $limit), true, $limit];
    }

    private function parseVlanIds(string $value): array
    {
        $ids = [];
        $parts = preg_split('/\s*,\s*/', $value);
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (str_contains($part, '-')) {
                [$start, $end] = array_map('intval', explode('-', $part, 2));
                for ($i = $start; $i <= $end; $i++) {
                    $ids[] = $i;
                }
            } else {
                $ids[] = (int) $part;
            }
        }
        $ids = array_values(array_filter($ids, fn ($v) => $v >= 1 && $v <= 4094));
        return array_unique($ids);
    }

    private function parseListToArray($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), fn ($v) => $v !== '' && $v !== null));
        }
        if (is_string($value)) {
            $parts = array_map('trim', preg_split('/[,; ]+/', $value));
            return array_values(array_filter($parts, fn ($v) => $v !== ''));
        }
        return [];
    }

    private function mapInterfaceType(string $type): string
    {
        $type = strtolower($type);

        if ($type === 'ether' || str_contains($type, 'ethernet')) {
            return 'physical';
        }

        if (str_contains($type, 'vlan')) {
            return 'vlan';
        }

        if ($type === 'bridge') {
            return 'bridge';
        }

        if ($type === 'loopback') {
            return 'loopback';
        }

        if (str_contains($type, 'virtual')) {
            return 'virtual';
        }

        return 'other';
    }

    private function mapInterfaceIndex(array $iface, int $fallbackIndex): int
    {
        if (isset($iface['.id'])) {
            $raw = (string) $iface['.id'];
            $raw = ltrim($raw, '*');
            $id = (int) $raw;
            if ($id > 0) {
                return $id;
            }
        }

        return $fallbackIndex + 1;
    }

    private function parseSpeedToBps(string $speed): ?int
    {
        $speed = trim(strtolower($speed));
        if ($speed === '') {
            return null;
        }

        if (preg_match('/^(\d+)\s*(g|gbps|gbit)/', $speed, $m)) {
            return (int) $m[1] * 1000_000_000;
        }

        if (preg_match('/^(\d+)\s*(m|mbps|mbit)/', $speed, $m)) {
            return (int) $m[1] * 1_000_000;
        }

        if (preg_match('/^(\d+)\s*(k|kbps|kbit)/', $speed, $m)) {
            return (int) $m[1] * 1000;
        }

        if (is_numeric($speed)) {
            return (int) $speed;
        }

        return null;
    }

    private function parseDurationToSeconds(string $duration): ?int
    {
        $duration = trim($duration);
        if ($duration === '') {
            return null;
        }

        $pattern = '/(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?/i';
        if (! preg_match($pattern, $duration, $matches)) {
            return null;
        }

        $weeks = (int) ($matches[1] ?? 0);
        $days = (int) ($matches[2] ?? 0);
        $hours = (int) ($matches[3] ?? 0);
        $minutes = (int) ($matches[4] ?? 0);
        $seconds = (int) ($matches[5] ?? 0);

        $total = $seconds;
        $total += $minutes * 60;
        $total += $hours * 3600;
        $total += $days * 86400;
        $total += $weeks * 7 * 86400;

        return $total;
    }

    private function mapErrorCode(string $code): string
    {
        $code = strtoupper($code);

        if ($code === 'HTTP_ERROR') {
            return 'API_UNAVAILABLE';
        }

        return $code;
    }

    private function isList(array $data): bool
    {
        if ($data === []) {
            return true;
        }

        return array_keys($data) === range(0, count($data) - 1);
    }
}

