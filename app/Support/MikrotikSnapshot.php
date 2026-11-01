<?php

namespace App\Support;

use App\Exceptions\MikrotikException;

class MikrotikSnapshot
{
    private array $interfaces = [];

    private array $vlans = [];

    private array $bridges = [];

    private array $ports = [];

    public function normalize(array $tables): array
    {
        foreach (MikrotikClient::MENUS as $menu => $_) {
            if (! isset($tables[$menu]) || ! is_array($tables[$menu])) {
                throw new MikrotikException('partial');
            }
            foreach ($tables[$menu] as $row) {
                foreach (['name', 'interface', 'actual-interface', 'bridge', 'server', 'board-name', 'address', 'comment', 'mac-address'] as $field) {
                    if (isset($row[$field]) && ! is_string($row[$field])) {
                        throw new MikrotikException('format');
                    }
                }
                foreach (['dynamic', 'disabled', 'invalid', 'use-service-tag', 'vlan-filtering'] as $flag) {
                    if (isset($row[$flag]) && self::flag($row[$flag]) === null) {
                        throw new MikrotikException('format');
                    }
                }
                foreach (['mac-address', 'active-mac-address'] as $field) {
                    if (! empty($row[$field]) && $row[$field] !== '00:00:00:00:00:00' && self::mac($row[$field]) === null) {
                        throw new MikrotikException('format');
                    }
                }
                $address = in_array($menu, ['ip/arp', 'ip/neighbor'], true) ? ($row['address'] ?? null) : ($row['active-address'] ?? null);
                if ($address !== null && $address !== '' && (! is_string($address) || ! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))) {
                    throw new MikrotikException('format');
                }
                if ($menu === 'ip/dhcp-server/network' && ! Ipv4Cidr::parse($row['address'])) {
                    throw new MikrotikException('format');
                }
            }
        }
        $this->interfaces = $this->byName($tables['interface']);
        $this->vlans = $this->byName($tables['interface/vlan']);
        $this->bridges = $this->byName($tables['interface/bridge']);
        $this->ports = $tables['interface/bridge/port'];
        $objects = [];
        foreach ($tables['interface/ethernet'] as $row) {
            if (empty($row['name']) || mb_strlen($row['name']) > 255
                || (isset($row['default-name']) && (! is_string($row['default-name']) || mb_strlen($row['default-name']) > 255))) {
                throw new MikrotikException('format');
            }
            $objects[] = $this->object('interface', 'interface/ethernet', $row,
                ['default_name' => $row['default-name'] ?? null], [
                    'name' => $row['name'], 'default_name' => $row['default-name'] ?? null,
                    'mac_address' => self::mac($row['mac-address'] ?? null),
                ], empty($row['.id']) || empty($row['default-name']) ? 'Identità della porta MikroTik incompleta.' : null);
        }
        foreach ($this->vlans as $name => $row) {
            $tag = $this->tag($row['vlan-id']);
            $context = $this->context($row['interface']);
            $problem = $context['problem'];
            if (isset($this->vlans[$row['interface']]) || self::flag($row['use-service-tag'] ?? null) !== false) {
                $problem = 'Parentela VLAN o service tag non determinabile. Verifica la configurazione alla fonte.';
            }
            if (self::flag($row['disabled'] ?? null) !== false) {
                $problem = self::flag($row['disabled'] ?? null) === true
                    ? 'VLAN disabilitata: non importata.'
                    : 'RouterOS non indica se la VLAN è disabilitata (campo disabled assente).';
            }
            $objects[] = $this->object('vlan', 'interface/vlan', $row, ['tag' => $tag, 'context_key' => $context['context_key']], [
                'name' => $name, 'tag' => $tag, ...$context,
            ], $problem);
        }
        foreach ($tables['interface/bridge/vlan'] as $row) {
            $context = $this->context($row['bridge']);
            $tags = $this->tags((string) $row['vlan-ids']);
            if (count($objects) + count($tags) > 20000) {
                throw new MikrotikException('limit');
            }
            $problem = $context['problem'];
            if (self::flag($row['dynamic'] ?? null) !== false) {
                // A dynamic PVID row never becomes a documented VLAN.
                $objects[] = $this->object('context', 'interface/bridge/vlan', $row, [], $context);

                continue;
            }
            if (self::flag($this->bridges[$row['bridge']]['vlan-filtering'] ?? null) !== true) {
                $problem = 'Filtering VLAN del bridge disattivato o non determinabile.';
            }
            if (self::flag($row['disabled'] ?? null) !== false) {
                $problem = self::flag($row['disabled'] ?? null) === true
                    ? 'Appartenenza VLAN disabilitata: non importata.'
                    : 'RouterOS non indica se l’appartenenza VLAN è disabilitata (campo disabled assente).';
            }
            if (count($tags) > 1 && (trim((string) ($row['untagged'] ?? '')) !== '' || trim((string) ($row['current-untagged'] ?? '')) !== '')) {
                $problem = 'Più VLAN con egress untagged: verifica puntuale del contesto richiesta.';
            }
            foreach ($tags as $tag) {
                $objects[] = $this->object('vlan', 'interface/bridge/vlan', $row, ['tag' => $tag, 'context_key' => $context['context_key']], [
                    'tag' => $tag, ...$context,
                    ...array_intersect_key($row, array_flip(['tagged', 'untagged', 'current-tagged', 'current-untagged'])),
                ], $problem, ':'.$tag);
            }
        }
        $networks = [];
        foreach ($tables['ip/address'] as $row) {
            $range = Ipv4Cidr::parse((string) $row['address']);
            if (! $range) {
                throw new MikrotikException('format');
            }
            $address = self::address(explode('/', $row['address'])[0]);
            $interface = $row['actual-interface'] ?? $row['interface'];
            $context = $this->context($interface);
            $tag = isset($this->vlans[$interface]) ? $this->tag($this->vlans[$interface]['vlan-id']) : null;
            $problem = $context['problem'];
            $active = self::flag($row['dynamic'] ?? null) === false
                && self::flag($row['disabled'] ?? null) === false && self::flag($row['invalid'] ?? null) === false;
            if (! $active) {
                $problem = match (true) {
                    self::flag($row['disabled'] ?? null) === true => 'Indirizzo disabilitato: non importato nell’IPAM.',
                    self::flag($row['dynamic'] ?? null) === true => 'Indirizzo dinamico: non importato nell’IPAM.',
                    self::flag($row['invalid'] ?? null) === true => 'RouterOS segnala l’indirizzo come non valido (invalid=true). Verifica l’indirizzo e la sua interfaccia sul router.',
                    default => 'Stato dell’indirizzo incompleto: RouterOS non riporta '.implode(', ', array_filter(['dynamic', 'disabled', 'invalid'], fn ($flag) => self::flag($row[$flag] ?? null) === null)).'. Importazione sospesa.',
                };
            }
            if ($active && $tag !== null) {
                foreach ($objects as $vlan) {
                    if ($vlan['kind'] === 'vlan' && ($vlan['data']['name'] ?? null) === $interface && $vlan['problem']) {
                        $problem = $vlan['problem'];
                    }
                }
            }
            $data = [...$range, 'tag' => $tag, ...$context, 'interface' => $interface, 'active' => $active];
            $subnet = $this->object('subnet', 'ip/address', $row, ['cidr' => $range['cidr'], 'tag' => $tag, 'context_key' => $context['context_key']], $data, $problem);
            $objects[] = $subnet;
            $networks[] = $subnet;
            $objects[] = $this->object('address', 'ip/address', $row, ['address' => $address, 'cidr' => $range['cidr'], 'context_key' => $context['context_key']], [
                ...$data, 'ip' => $address === null ? null : long2ip($address), 'network_key' => $subnet['source_key'],
            ], $problem, address: $address);
        }
        $servers = $this->byName($tables['ip/dhcp-server']);
        foreach ($tables['ip/dhcp-server/lease'] as $row) {
            $fixed = self::flag($row['dynamic'] ?? null) === false;
            $address = self::address($row['address'] ?? null);
            $serverMissing = empty($row['server']);
            $server = $servers[$row['server'] ?? ''] ?? null;
            $interface = $server['interface'] ?? null;
            $matches = array_values(array_filter($networks, fn ($network) => $address !== null
                && ($serverMissing || $network['data']['interface'] === $interface) && $network['data']['network_address'] <= $address
                && $network['data']['broadcast_address'] >= $address && ! $network['problem']));
            $matches = collect($matches)->unique(fn ($network) => $network['data']['cidr'].':'.($network['data']['tag'] ?? 'none').':'.$network['data']['context_key'])->values()->all();
            $problem = null;
            if ($fixed) {
                $problem = match (true) {
                    self::flag($row['disabled'] ?? null) === true => 'Lease disabilitata: non importata nell’IPAM.',
                    self::flag($row['disabled'] ?? null) === null => 'RouterOS non indica se il lease è disabilitato (campo disabled assente). Importazione sospesa.',
                    empty($row['mac-address']) => 'MAC assente nel lease DHCP. Verifica il lease sul router.',
                    self::mac($row['mac-address']) === null => 'MAC nullo nel lease DHCP (00:00:00:00:00:00). Verifica il lease sul router.',
                    $address === null => 'Lease senza un indirizzo IPv4 fisso utilizzabile. Associazione subnet non eseguita.',
                    $serverMissing => match (count($matches)) {
                        0 => 'Server DHCP non specificato: nessuna subnet importabile del dispositivo contiene questo IP.',
                        1 => null,
                        default => 'Server DHCP non specificato: più subnet del dispositivo contengono questo IP. Associazione sospesa.',
                    },
                    $row['server'] === 'all' => 'Lease valido per tutti i server DHCP. Associazione a una singola subnet non determinabile.',
                    $server === null => 'Il server DHCP «'.$row['server'].'» indicato nel lease non è presente nella lettura.',
                    self::flag($server['disabled'] ?? null) === true => 'Server DHCP disabilitato: lease non importata nell’IPAM.',
                    self::flag($server['disabled'] ?? null) === null => 'RouterOS non indica se il server DHCP «'.$row['server'].'» è disabilitato (campo disabled assente).',
                    self::flag($server['invalid'] ?? null) === true => 'RouterOS segnala il server DHCP «'.$row['server'].'» come non valido (invalid=true). Verifica la sua interfaccia sul router.',
                    self::flag($server['invalid'] ?? null) === null => 'RouterOS non riporta il campo invalid del server DHCP «'.$row['server'].'». Validità non determinabile.',
                    empty($interface) => 'Il server DHCP «'.$row['server'].'» non riporta l’interfaccia. Associazione subnet non determinabile.',
                    count($matches) === 0 => 'Nessuna subnet importabile contiene questo IP sull’interfaccia «'.$interface.'» del server DHCP «'.$row['server'].'». Verifica prima le subnet di questa interfaccia.',
                    count($matches) > 1 => 'Più subnet compatibili sull’interfaccia «'.$interface.'» del server DHCP «'.$row['server'].'». Associazione sospesa.',
                    default => null,
                };
            }
            $objects[] = $this->object($fixed ? 'lease' : 'observation', 'ip/dhcp-server/lease', $row,
                ['address' => $address, 'server' => $server['.id'] ?? null], [
                    'ip' => $address === null ? null : long2ip($address), 'fixed' => $fixed && $address !== null,
                    'mac' => self::mac($row['mac-address'] ?? null), 'server' => $row['server'] ?? null,
                    'network_key' => count($matches) === 1 ? $matches[0]['source_key'] : null,
                    'hostname_observed' => $row['host-name'] ?? null,
                    'status' => $row['status'] ?? null,
                ], $problem, address: $address);
            $active = self::address($row['active-address'] ?? null);
            if ($active !== null && ($row['status'] ?? null) === 'bound') {
                $objects[] = $this->object('observation', 'ip/dhcp-server/lease', $row, [], [
                    'ip' => long2ip($active), 'mac' => self::mac($row['active-mac-address'] ?? null),
                    'server' => $row['active-server'] ?? null, 'status' => 'bound',
                ], suffix: ':active', address: $active);
            }
        }
        foreach (['ip/arp', 'interface/bridge/host', 'ip/neighbor'] as $menu) {
            foreach ($tables[$menu] as $row) {
                $address = self::address($row['address'] ?? null);
                $neighbor = [];
                if ($menu === 'ip/neighbor') {
                    // Join only an unambiguous observation on the same receiving interfaces and sender MAC.
                    $lldp = array_values(array_filter($tables[MikrotikClient::LLDP_MENU] ?? [], fn ($item) => self::mac($row['mac-address'] ?? null) !== null && self::mac($item['mac-address'] ?? null) === self::mac($row['mac-address'])
                        && ($item['interface'] ?? null) === ($row['interface'] ?? null)));
                    $neighbor = [
                        ...array_intersect_key($row, array_flip(['discovered-by', 'interface-name', 'age', 'platform', 'board', 'version'])),
                        'lldp_read' => isset($tables[MikrotikClient::LLDP_MENU]),
                        'lldp' => count($lldp) === 1 ? array_intersect_key($lldp[0], array_flip([
                            'lldp-chassis-id', 'lldp-chassis-id-subtype', 'lldp-port-id', 'lldp-port-id-subtype',
                            'lldp-system-name', 'lldp-port-description',
                        ])) : null,
                    ];
                }
                $objects[] = $this->object('observation', $menu, $row, [], [
                    'ip' => $address === null ? null : long2ip($address),
                    'mac' => self::mac($row['mac-address'] ?? null),
                    ...array_intersect_key($row, array_flip(['interface', 'on-interface', 'bridge', 'vid', 'status', 'identity', 'local', 'external', 'complete'])),
                    ...$neighbor,
                ], address: $address);
            }
        }
        foreach (['interface', 'interface/ethernet', 'interface/bridge', 'interface/bridge/port', 'interface/list', 'interface/list/member', 'ip/dhcp-server', 'ip/dhcp-server/network', 'ip/pool'] as $menu) {
            foreach ($tables[$menu] as $row) {
                if (! empty($row['comment'])) {
                    $objects[] = $this->object('context', $menu, $row, [], array_intersect_key($row, array_flip(['name', 'interface', 'bridge', 'address', 'list'])));
                }
            }
        }
        $contexts = $cidrs = [];
        foreach ($objects as $object) {
            if ($object['kind'] === 'vlan' && self::flag($object['data']['disabled'] ?? null) !== true) {
                $contexts[$object['data']['tag']][$object['data']['context_key'] ?? '?'] = true;
            }
            if ($object['kind'] === 'subnet' && $object['data']['active']) {
                $cidrs[$object['data']['cidr']][($object['data']['tag'] ?? 'none').':'.($object['data']['context_key'] ?? '?')] = true;
            }
        }
        foreach ($objects as &$object) {
            if (self::flag($object['data']['disabled'] ?? null) === true || self::flag($object['data']['dynamic'] ?? null) === true) {
                continue;
            }
            $tag = $object['data']['tag'] ?? null;
            if ($tag !== null && count($contexts[$tag] ?? []) > 1) {
                $object['problem'] = 'Stesso ID VLAN in contesti L2 non dimostrati equivalenti.';
            }
            if (isset($object['data']['cidr']) && count($cidrs[$object['data']['cidr']] ?? []) > 1) {
                $object['problem'] = 'La stessa subnet compare in contesti o VLAN differenti.';
            }
        }
        if (count($objects) > 20000) {
            throw new MikrotikException('limit');
        }
        $keys = array_map(fn ($object) => $object['kind'].':'.$object['source_key'], $objects);
        if (count($keys) !== count(array_unique($keys))) {
            throw new MikrotikException('format');
        }

        return $objects;
    }

    private function object(string $kind, string $menu, array $row, array $anchor, array $data, ?string $problem = null, string $suffix = '', ?int $address = null): array
    {
        $id = $row['.id'] ?? null;
        if ($id !== null && (! is_string($id) || ! preg_match('/\A\*[0-9a-f]+\z/i', $id) || strlen($menu.':'.$id.$suffix) > 191)) {
            throw new MikrotikException('format');
        }
        ksort($row);
        if ($id === null && in_array($kind, ['vlan', 'subnet', 'address', 'lease'], true)) {
            $problem = 'Identificativo sorgente assente: associazione automatica sospesa.';
        }

        return [
            'kind' => $kind, 'source_key' => $menu.':'.($id ?? hash('sha256', json_encode($row))).$suffix,
            'anchor' => $anchor, 'data' => [...$data, 'menu' => '/'.$menu, 'external_id' => $id,
                'comment' => $row['comment'] ?? null,
                ...array_intersect_key($row, array_flip(['dynamic', 'disabled', 'invalid'])),
            ], 'problem' => $problem, 'address' => $address,
        ];
    }

    private function context(string $name): array
    {
        $interface = $this->interfaces[$name] ?? null;
        if (isset($this->vlans[$name])) {
            $name = $this->vlans[$name]['interface'];
            $interface = $this->interfaces[$name] ?? null;
        }
        $parents = array_values(array_filter($this->ports, fn ($port) => $port['interface'] === $name));
        if (count($parents) === 1 && self::flag($parents[0]['disabled'] ?? null) === false) {
            $name = $parents[0]['bridge'];
            $interface = $this->interfaces[$name] ?? null;
        }
        $known = $interface && isset($interface['.id']) && ! isset($this->vlans[$name]) && count($parents) <= 1;

        return ['context' => $name, 'context_key' => $known ? $interface['.id'] : null,
            'problem' => $known ? null : 'Interfaccia o contesto L2 non determinabile.'];
    }

    private function byName(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (! is_string($row['name'] ?? null) || isset($result[$row['name']])) {
                throw new MikrotikException('format');
            }
            $result[$row['name']] = $row;
        }

        return $result;
    }

    private function tag(mixed $value): int
    {
        if (! preg_match('/\A[0-9]{1,4}\z/', (string) $value) || (int) $value < 1 || (int) $value > 4094) {
            throw new MikrotikException('format');
        }

        return (int) $value;
    }

    private function tags(string $value): array
    {
        $tags = [];
        foreach (explode(',', $value) as $part) {
            $ends = explode('-', trim($part));
            $first = $this->tag($ends[0]);
            $last = count($ends) === 2 ? $this->tag($ends[1]) : $first;
            if (count($ends) > 2 || $first > $last) {
                throw new MikrotikException('format');
            }
            $tags = [...$tags, ...range($first, $last)];
            if (count($tags) > 4094) {
                throw new MikrotikException('limit');
            }
        }

        return array_values(array_unique($tags));
    }

    public static function flag(mixed $value): ?bool
    {
        return match ($value) {
            true, 'true', 'yes', 1, '1' => true, false, 'false', 'no', 0, '0' => false, default => null
        };
    }

    public static function address(mixed $value): ?int
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && ip2long($value) > 0 && ip2long($value) < ip2long('224.0.0.0') ? ip2long($value) : null;
    }

    public static function mac(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\z/i', $value)
            && $value !== '00:00:00:00:00:00' ? strtoupper($value) : null;
    }
}
