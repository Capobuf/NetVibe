import { itemBounds, unitLabel } from './rack-layout';
import type {
    DocumentCable,
    DocumentDevice,
    DocumentRack,
} from '@/types/rack-document';
import type { RackItem } from '@/types/rack';

export function unitRange(
    bottom: number,
    top: number,
    rack: Pick<DocumentRack, 'height_u' | 'numbering'>,
) {
    const first = unitLabel(Math.ceil(top) - 1, rack);
    const last = unitLabel(Math.floor(bottom), rack);
    return first === last ? `${first}` : `${first}–${last}`;
}

export function devicePosition(device: DocumentDevice) {
    if (!device.placement || !device.rack) return '—';
    const bounds = itemBounds(device.placement, [
        device.placement,
        ...device.supports,
    ]);
    return unitRange(bounds.bottom, bounds.top, device.rack);
}

export function rackOccupancy(rack: DocumentRack, devices: DocumentDevice[]) {
    const name = (item: RackItem) =>
        item.kind === 'device'
            ? (devices.find((device) => device.id === item.device_id)?.name ??
              '—')
            : item.kind === 'shelf'
              ? 'Ripiano'
              : 'Cassetto';
    const occupied = new Set<number>();
    const rows = rack.items.map((item) => {
        const bounds = itemBounds(item, rack.items);
        const top = item.kind === 'shelf' ? bounds.bottom + 1 : bounds.top;
        for (let u = Math.floor(bounds.bottom); u < Math.ceil(top); u++)
            occupied.add(u);
        const support = rack.items.find(
            (candidate) => candidate.key === item.shelf_key,
        );
        return {
            top,
            bottom: bounds.bottom,
            cells: [
                unitRange(bounds.bottom, top, rack),
                name(item),
                item.kind === 'device'
                    ? support
                        ? `Apparato · appoggiato su ${name(support)}`
                        : `Apparato · ${item.height}U`
                    : item.kind === 'shelf'
                      ? 'Ripiano · staffe 1U'
                      : `Cassetto · ${item.height}U`,
            ],
        };
    });
    for (let u = 0; u < rack.height_u; u++) {
        if (occupied.has(u)) continue;
        const bottom = u;
        while (u + 1 < rack.height_u && !occupied.has(u + 1)) u++;
        rows.push({
            top: u + 1,
            bottom,
            cells: [unitRange(bottom, u + 1, rack), 'Spazio libero', '—'],
        });
    }
    return rows
        .sort((a, b) => b.top - a.top || b.bottom - a.bottom)
        .map((row) => row.cells);
}

export function rackSections(rack: DocumentRack) {
    const sections: { bottom: number; top: number }[] = [];
    let top = rack.height_u;
    while (top > 0) {
        let bottom = Math.max(0, top - 42);
        // Keep each apparatus whole when a tall rack needs more than one plate.
        let crossing: RackItem | undefined;
        while (
            (crossing = rack.items.find((item) => {
                const bounds = itemBounds(item, rack.items);
                return bounds.bottom < bottom && bounds.top > bottom;
            }))
        )
            bottom = Math.floor(itemBounds(crossing, rack.items).bottom);
        sections.push({ bottom, top });
        top = bottom;
    }
    return sections;
}

export type DocumentLink = {
    reference: string;
    a: number;
    b: number;
    type: DocumentCable['type'];
    cables: DocumentCable[];
};

export function documentLinks(cables: DocumentCable[]) {
    const links = new Map<string, DocumentLink>();
    for (const cable of cables) {
        if (!cable.a?.device_id || !cable.b?.device_id) continue;
        const [a, b] = [cable.a.device_id, cable.b.device_id].sort(
            (x, y) => x - y,
        );
        const key = `${a}:${b}:${cable.type}`;
        const existing = links.get(key);
        if (existing) existing.cables.push(cable);
        else
            links.set(key, {
                reference: `T${links.size + 1}`,
                a,
                b,
                type: cable.type,
                cables: [cable],
            });
    }
    return [...links.values()];
}
