import type { Rack, RackDevice, RackItem } from '@/types/rack';

export function isRackMounted(device: Pick<RackDevice, 'model'>) {
    return !!device.model?.rack_height_u && !device.model.without_rack_ears;
}

export function itemName(item: RackItem, devices: RackDevice[]) {
    return item.kind === 'device'
        ? (devices.find((device) => device.id === item.device_id)?.name ??
              'Dispositivo')
        : item.kind === 'shelf'
          ? 'Ripiano'
          : 'Cassetto';
}

export function supportChain(
    item: RackItem,
    items: RackItem[],
): RackItem[] | null {
    const chain: RackItem[] = [];
    const seen = new Set([item.key]);
    while (item.shelf_key) {
        const support = items.find(
            (candidate) => candidate.key === item.shelf_key,
        );
        if (!support || seen.has(support.key)) return null;
        chain.push(support);
        seen.add(support.key);
        item = support;
    }
    return chain;
}

export function itemBounds(item: RackItem, items: RackItem[]) {
    const chain = supportChain(item, items);
    const bottom = chain
        ? [...chain]
              .reverse()
              .reduce(
                  (bottom, support) =>
                      bottom + (support.kind === 'shelf' ? 0 : support.height),
                  (chain.at(-1) ?? item).position,
              )
        : NaN;
    return {
        bottom,
        top: bottom + (item.kind === 'shelf' ? 0 : item.height),
        left: item.x,
        right: item.x + item.width,
    };
}

export function layoutError(
    items: RackItem[],
    height: number,
    devices: RackDevice[],
): string | null {
    for (const [index, item] of items.entries()) {
        const box = itemBounds(item, items);
        const name = itemName(item, devices);
        const support = items.find(
            (candidate) => candidate.key === item.shelf_key,
        );
        if (
            !Number.isFinite(box.bottom) ||
            (item.shelf_key &&
                (!support ||
                    !['shelf', 'device'].includes(support.kind) ||
                    item.position !== support.position ||
                    box.right <= support.x ||
                    box.left >= support.x + support.width))
        ) {
            return `Scegli un appoggio valido sotto «${name}».`;
        }
        if (
            ![item.position, item.height, item.width, item.x].every(
                Number.isFinite,
            ) ||
            !Number.isInteger(item.position) ||
            item.position < 0 ||
            item.height < 0.25 ||
            item.width < 5 ||
            item.x < 0 ||
            box.top > height ||
            (item.kind === 'shelf' && item.position + 1 > height) ||
            box.right > 100
        ) {
            return `«${name}» supera il perimetro del rack o ha dimensioni non valide.`;
        }
        for (const other of items.slice(0, index)) {
            const b = itemBounds(other, items);
            if (
                (item.kind === 'shelf' &&
                    other.kind === 'shelf' &&
                    item.position === other.position) ||
                (box.bottom < b.top &&
                    box.top > b.bottom &&
                    box.left < b.right &&
                    box.right > b.left)
            ) {
                return `«${name}» si sovrappone a «${itemName(other, devices)}».`;
            }
        }
    }
    return null;
}

export function unitLabel(
    position: number,
    rack: Pick<Rack, 'height_u' | 'numbering'>,
) {
    return rack.numbering === 'bottom-up'
        ? position + 1
        : rack.height_u - position;
}

export function moveItem(
    items: RackItem[],
    key: string,
    position: number,
    x?: number,
) {
    const target = items.find((item) => item.key === key);
    const deltaX = target && x !== undefined ? x - target.x : 0;
    return items.map((item) =>
        item.key === key
            ? { ...item, position, x: x ?? item.x }
            : supportChain(item, items)?.some((support) => support.key === key)
              ? { ...item, position, x: item.x + deltaX }
              : item,
    );
}

export function clampDeviceDrag(
    item: RackItem,
    items: RackItem[],
    x: number,
    bottom: number,
    height: number,
) {
    const moving = items.filter(
        (candidate) =>
            candidate.key === item.key ||
            supportChain(candidate, items)?.some(
                (support) => support.key === item.key,
            ),
    );
    const boxes = moving.map((candidate) => itemBounds(candidate, items));
    const minX = item.x - Math.min(...boxes.map((box) => box.left));
    const maxX = 100 - (Math.max(...boxes.map((box) => box.right)) - item.x);
    const stackHeight =
        Math.max(...boxes.map((box) => box.top)) -
        itemBounds(item, items).bottom;
    return {
        x: Math.max(minX, Math.min(maxX, x)),
        bottom: Math.max(0, Math.min(height - stackHeight, bottom)),
    };
}

export function dropDevice(
    item: RackItem,
    items: RackItem[],
    x: number,
    bottom: number,
    height: number,
    devices: RackDevice[],
): RackItem[] | null {
    const candidates = items
        .filter(
            (support) =>
                ['shelf', 'device'].includes(support.kind) &&
                support.key !== item.key &&
                !supportChain(support, items)?.some(
                    (parent) => parent.key === item.key,
                ),
        )
        .map((support) => ({
            support,
            distance: Math.abs(itemBounds(support, items).top - bottom),
            overlap:
                Math.min(x + item.width, support.x + support.width) -
                Math.max(x, support.x),
        }))
        .filter(({ distance, overlap }) => distance <= 0.5 && overlap > 0)
        .sort((a, b) => a.distance - b.distance || b.overlap - a.overlap);
    for (const { support } of candidates) {
        const placed = moveItem(items, item.key, support.position, x).map(
            (candidate) =>
                candidate.key === item.key
                    ? { ...candidate, shelf_key: support.key }
                    : candidate,
        );
        if (!layoutError(placed, height, devices)) return placed;
    }
    return null;
}
