import type { Subnet, Vlan } from '../types/network';

export function groupSubnetsByVlan<T extends Subnet>(
    rows: T[],
    vlans: Vlan[],
): T[] {
    const tags = new Map(vlans.map((vlan) => [vlan.id, vlan.tag]));
    // Stable grouping keeps the selected row order inside each VLAN before pagination.
    return rows.toSorted(
        (a, b) =>
            (tags.get(a.vlan_id ?? -1) ?? Infinity) -
            (tags.get(b.vlan_id ?? -1) ?? Infinity),
    );
}
