<?php

namespace App\Support;

class MikrotikAnalysis
{
    public static function cases(array $tables): array
    {
        $bridges = $tables['interface/bridge'] ?? null;
        $vlans = $tables['interface/vlan'] ?? null;
        $ports = $tables['interface/ethernet'] ?? null;
        $memberships = $tables['interface/bridge/vlan'] ?? null;
        $lists = $tables['interface/list'] ?? null;
        $onBridge = $bridges !== null && $vlans !== null
            ? count(array_filter($vlans, fn ($row) => in_array($row['interface'] ?? null, array_column($bridges, 'name'), true))) : null;
        $onPhysical = $ports !== null && $vlans !== null
            ? count(array_filter($vlans, fn ($row) => in_array($row['interface'] ?? null, array_column($ports, 'name'), true))) : null;
        $listMemberships = null;
        if ($memberships !== null && $lists !== null) {
            $listMemberships = 0;
            foreach ($memberships as $row) {
                if (! isset($row['tagged'], $row['untagged'])) {
                    $listMemberships = null;
                    break;
                }
                $names = array_map('trim', explode(',', $row['tagged'].','.$row['untagged']));
                $listMemberships += count(array_intersect($names, array_column($lists, 'name')));
            }
        }
        $cases = [
            ['Bridge assenti', $bridges === null ? null : (int) ($bridges === [])],
            ['Un solo bridge', $bridges === null ? null : (int) (count($bridges) === 1)],
            ['Più bridge', $bridges === null ? null : (int) (count($bridges) > 1)],
            ['VLAN su bridge', $onBridge],
            ['VLAN su interfaccia Ethernet', $onPhysical],
            ['Liste in tagged/untagged (RouterOS 7.17+)', $listMemberships],
        ];

        return array_map(fn ($case) => [
            'label' => $case[0],
            'status' => $case[1] === null ? 'Non determinabile' : ($case[1] > 0 ? 'Riscontrata' : 'Non riscontrata nella lettura'),
        ], $cases);
    }

    public static function summarize(array $tables): array
    {
        $findings = [];
        $bridges = $tables['interface/bridge'] ?? null;
        if ($bridges !== null) {
            $enabled = count(array_filter($bridges, fn ($row) => self::flag($row['vlan-filtering'] ?? null) === true));
            $disabled = count(array_filter($bridges, fn ($row) => self::flag($row['vlan-filtering'] ?? null) === false));
            $unknown = count($bridges) - $enabled - $disabled;
            $findings[] = ['label' => 'Bridge nel dispositivo', 'value' => count($bridges), 'detail' => "VLAN filtering: {$enabled} attivi, {$disabled} disattivi, {$unknown} non determinabili."];
        }
        $leases = $tables['ip/dhcp-server/lease'] ?? null;
        if ($leases !== null) {
            $static = count(array_filter($leases, fn ($row) => self::flag($row['dynamic'] ?? null) === false));
            $dynamic = count(array_filter($leases, fn ($row) => self::flag($row['dynamic'] ?? null) === true));
            $unknown = count($leases) - $static - $dynamic;
            $findings[] = ['label' => 'Lease DHCP', 'value' => count($leases), 'detail' => "{$static} statiche, {$dynamic} dinamiche, {$unknown} non classificabili."];
        }
        $addresses = $tables['ip/address'] ?? null;
        if ($addresses !== null) {
            $interfaces = array_count_values(array_filter(array_column($addresses, 'interface'), 'is_string'));
            $multiple = count(array_filter($interfaces, fn ($count) => $count > 1));
            $findings[] = ['label' => 'IPv4 configurati sul dispositivo', 'value' => count($addresses), 'detail' => "{$multiple} interfacce con più indirizzi."];
        }
        $vlans = $tables['interface/vlan'] ?? null;
        if ($vlans !== null) {
            $findings[] = ['label' => 'Interfacce VLAN L3', 'value' => count($vlans), 'detail' => 'Conteggio distinto dalle appartenenze VLAN L2.'];
        }
        if (isset($tables['interface/bridge/vlan'])) {
            $dynamic = count(array_filter($tables['interface/bridge/vlan'], fn ($row) => self::flag($row['dynamic'] ?? null) === true));
            $findings[] = ['label' => 'Righe di appartenenza VLAN L2', 'value' => count($tables['interface/bridge/vlan']), 'detail' => "{$dynamic} righe dinamiche; le righe possono contenere più VLAN."];
        }
        if ($bridges !== null && $vlans !== null && isset($tables['interface/bridge/vlan'])) {
            $collisions = self::vlanCollisions($tables);
            $findings[] = ['label' => 'ID VLAN in più contesti', 'value' => $collisions, 'detail' => $collisions === null
                ? 'Non determinabile con i dati letti.'
                : ($collisions > 0 ? 'Possibile collisione con l’unicità VLAN per sede: serve una decisione prima dell’importazione.' : 'Nessuna ripetizione nei contesti letti; switch chip e collegamenti esterni non valutati.')];
        }
        $comments = 0;
        foreach ($tables as $rows) {
            $comments += count(array_filter($rows, fn ($row) => isset($row['comment']) && $row['comment'] !== ''));
        }
        $findings[] = ['label' => 'Commenti negli oggetti letti', 'value' => $comments, 'detail' => 'Destinazione in NetVibe da decidere prima dell’importazione.'];
        $findings[] = ['label' => 'Switch chip e VLAN hardware', 'value' => null, 'detail' => 'Non supportati. Le VLAN vengono lette dalle interfacce VLAN e dalle tabelle dei bridge.'];

        return $findings;
    }

    private static function flag(mixed $value): ?bool
    {
        return match ($value) {
            true, 'true', 'yes' => true,
            false, 'false', 'no' => false,
            default => null,
        };
    }

    private static function vlanCollisions(array $tables): ?int
    {
        $contexts = [];
        foreach ($tables['interface/bridge/vlan'] as $row) {
            if (empty($row['bridge']) || ! isset($row['vlan-ids'])) {
                return null;
            }
            $contexts[] = [$row['bridge'], (string) $row['vlan-ids']];
        }
        foreach ($tables['interface/vlan'] as $row) {
            if (empty($row['interface']) || ! isset($row['vlan-id'])) {
                return null;
            }
            // Parent interface names preserve contexts; do not infer L2 equivalence from cabling or PVID.
            $contexts[] = [$row['interface'], (string) $row['vlan-id']];
        }
        $seen = $collisions = [];
        $expanded = 0;
        foreach ($contexts as [$context, $ids]) {
            foreach (explode(',', $ids) as $part) {
                if (! preg_match('/\A([0-9]{1,4})(?:-([0-9]{1,4}))?\z/', trim($part), $match)) {
                    return null;
                }
                $start = (int) $match[1];
                $end = (int) ($match[2] ?? $match[1]);
                $expanded += $end - $start + 1;
                if ($start < 1 || $end > 4094 || $end < $start || $expanded > 20000) {
                    return null;
                }
                for ($id = $start; $id <= $end; $id++) {
                    if (isset($seen[$id]) && $seen[$id] !== $context) {
                        $collisions[$id] = true;
                    }
                    $seen[$id] = $context;
                }
            }
        }

        return count($collisions);
    }
}
