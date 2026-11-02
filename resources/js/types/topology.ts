import type { CableEnd } from '@/lib/rack-cabling';
import type { PanelDocument } from '@/lib/hardware-editor/document';

export type TopologyModel = {
    panel: PanelDocument;
    assetUrls: Record<string, string>;
};

export type TopologyNode = {
    id: string;
    device_id: number | null;
    label: string;
    model: string | null;
    model_id: number | null;
    rack: { id: number; name: string } | null;
    identity: string | null;
    mac: string | null;
    indirect: { menu: string; count: number; seen_at: string | null }[];
};
export type TopologyEnd = {
    device_id: number;
    interface_id: number | null;
    connector_id: string | null;
    label: string;
    stale: boolean;
};
export type TopologyEvidence = {
    inventory_match: 'name_mac' | 'name_model' | 'external_id' | null;
    device_id: number;
    source: string;
    seen_at: string | null;
    state: string;
    stale: boolean;
    protocols: string[];
    local_port: string | null;
    remote_port: string | null;
    remote_port_description: string | null;
    remote_port_mac: string | null;
    remote_identity: string | null;
    remote_mac: string | null;
    chassis_id: string | null;
    lldp_seen_at: string | null;
    menu: string;
};
export type TopologyEdge = {
    id: string;
    kind: 'documented' | 'discovered' | 'partial' | 'logical';
    source: string;
    target: string;
    a: TopologyEnd | null;
    b: TopologyEnd | null;
    protocols: string[];
    evidence: TopologyEvidence[];
    reciprocal: boolean;
    stale: boolean;
    issues: string[];
    cable_ids: number[];
    suggestion: { rack_id: number; a: CableEnd; b: CableEnd } | null;
    purpose: string | null;
};
export type TopologyData = {
    models: Record<number, TopologyModel>;
    nodes: TopologyNode[];
    edges: TopologyEdge[];
    integrations: {
        vendor?: 'mikrotik' | 'unifi';
        device_id: number;
        name: string;
        seen_at: string | null;
        stale: boolean;
        status: string | null;
    }[];
};
export type TopologySelection = { kind: 'node' | 'edge'; id: string } | null;
export const linkKinds = {
    documented: 'Documentati',
    discovered: 'Porte riconosciute',
    partial: 'Parziali',
    logical: 'Logici UniFi',
} as const;

export function topologyPortLabel(
    edge: TopologyEdge,
    node: TopologyNode,
    markReported = true,
): string | null {
    const end = edge.source === node.id ? edge.a : edge.b;
    if (end?.label) return end.label;
    const reported = [
        ...new Set(
            edge.evidence
                .map((item) =>
                    item.device_id === node.device_id
                        ? item.local_port
                        : item.remote_port,
                )
                .filter(Boolean),
        ),
    ];
    return reported.length
        ? `${markReported ? 'Rilevata: ' : ''}${reported.join(' / ')}`
        : null;
}

export function topologyPortMessage(
    edge: TopologyEdge,
    node: TopologyNode,
): string {
    if (edge.kind === 'logical') return 'Uplink logico';
    if (node.device_id === null)
        return 'Dispositivo rilevato, non associato all’inventario';
    if (!topologyPortLabel(edge, node)) {
        return edge.evidence.some((item) => item.device_id === node.device_id)
            ? 'La rilevazione non riporta la porta locale'
            : 'La rilevazione non riporta la porta remota';
    }
    if (!node.model_id) return 'Dispositivo senza modello associato';
    return 'Porta rilevata, non associata al disegno';
}
