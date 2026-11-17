import type { FloorLinks } from '@/types/floor';
import { topologyPortLabel, type TopologyEdge } from '@/types/topology';

export type FloorLinkSource = {
    source: string;
    seen_at: string | null;
    stale: boolean;
};

/** One relation of the selected element with something outside it. */
export type FloorLinkRow = {
    id: string;
    /** Topology kind; a MAC seen on a port counts as partial, since it names no remote port. */
    kind: TopologyEdge['kind'];
    /** What the relation rests on: cable codes, protocols or the kind of sighting. */
    basis: string;
    local: { device_id: number; port: string | null };
    peer: { device_id: number | null; label: string; port: string | null };
    sources: FloorLinkSource[];
    stale: boolean;
    issues: string[];
};

/** MACs seen on a port that no inventory device owns: an observation to consult, never a device. */
export type FloorHosts = {
    device_id: number;
    port: string | null;
    wireless: boolean;
    count: number;
};

const deviceId = (node: string) =>
    node.startsWith('device:') ? Number(node.slice(7)) : null;

function basis(edge: TopologyEdge): string {
    if (edge.kind === 'documented')
        return edge.cable_ids.map((id) => `C-${id}`).join(', ');
    if (edge.kind === 'logical') return 'Uplink UniFi';
    const protocols = edge.protocols.map((item) => item.toUpperCase());
    return protocols.length ? protocols.join(', ') : 'Neighbor';
}

/**
 * Relations between the given devices and everything else: documented cables, LLDP/CDP adjacencies,
 * UniFi uplinks and inventory devices seen directly on a port. Relations among the given devices
 * (inside the same rack) are left out. A sighting that confirms a documented cable joins it.
 */
export function floorLinks(
    links: FloorLinks,
    ids: Set<number>,
): { rows: FloorLinkRow[]; hosts: FloorHosts[] } {
    const rows: FloorLinkRow[] = [];
    const documented = new Map<string, FloorLinkRow>();
    for (const edge of links.edges) {
        const source = deviceId(edge.source);
        const target = deviceId(edge.target);
        const outgoing = source !== null && ids.has(source);
        const incoming = target !== null && ids.has(target);
        if (outgoing === incoming) continue;
        const [local, peer, localNode, peerNode] = outgoing
            ? [source!, target, edge.source, edge.target]
            : [target!, source, edge.target, edge.source];
        const row: FloorLinkRow = {
            id: edge.id,
            kind: edge.kind,
            basis: basis(edge),
            local: {
                device_id: local,
                port: topologyPortLabel(edge, {
                    id: localNode,
                    device_id: local,
                }),
            },
            peer: {
                device_id: peer,
                label: links.neighbors[peerNode] ?? '',
                port: topologyPortLabel(edge, {
                    id: peerNode,
                    device_id: peer,
                }),
            },
            sources: edge.evidence.map(({ source, seen_at, stale }) => ({
                source,
                seen_at,
                stale,
            })),
            stale: edge.stale,
            issues: edge.issues,
        };
        rows.push(row);
        if (edge.kind === 'documented' && edge.a && edge.b) {
            documented.set(
                `${edge.a.device_id}:${edge.a.connector_id}|${edge.b.device_id}`,
                row,
            );
            documented.set(
                `${edge.b.device_id}:${edge.b.connector_id}|${edge.a.device_id}`,
                row,
            );
        }
    }
    const hosts: FloorHosts[] = [];
    for (const [key, observations] of Object.entries(links.observed)) {
        const seenBy = Number(key);
        for (const observation of observations) {
            const sources = observation.sources.map(
                ({ source, seen_at, stale }) => ({ source, seen_at, stale }),
            );
            const owner = observation.device_id;
            if (owner === null) {
                if (!ids.has(seenBy)) continue;
                const { port, wireless } = observation;
                const group = hosts.find(
                    (item) =>
                        item.device_id === seenBy &&
                        item.port === port &&
                        item.wireless === wireless,
                );
                if (group) group.count += observation.macs.length;
                else
                    hosts.push({
                        device_id: seenBy,
                        port,
                        wireless,
                        count: observation.macs.length,
                    });
                continue;
            }
            if (ids.has(seenBy) === ids.has(owner)) continue;
            const confirmed = documented.get(
                `${seenBy}:${observation.connector_id}|${owner}`,
            );
            if (confirmed) {
                confirmed.sources.push(...sources);
                continue;
            }
            const port = observation.port;
            const [local, peer] = ids.has(seenBy)
                ? [
                      { device_id: seenBy, port },
                      { device_id: owner, label: '', port: null },
                  ]
                : [
                      { device_id: owner, port: null },
                      { device_id: seenBy, label: '', port },
                  ];
            rows.push({
                id: `observed:${seenBy}:${observation.key}`,
                kind: 'partial',
                basis: observation.wireless ? 'Client Wi-Fi' : 'MAC sulla porta',
                local,
                peer,
                sources,
                stale: sources.every((item) => item.stale),
                issues: [],
            });
        }
    }
    // Documented first, then what integrations report; by local port within each group.
    rows.sort(
        (a, b) =>
            Number(b.kind === 'documented') - Number(a.kind === 'documented') ||
            (a.local.port ?? '').localeCompare(b.local.port ?? '', 'it', {
                numeric: true,
            }),
    );
    return { rows, hosts };
}
