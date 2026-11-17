import type { LayerOrderAction } from '@/lib/hardware-editor/document';
import { itemKey, type FloorItem } from '@/types/floor';

/** Moves one item in the stacking order: the list order is the drawing order, later items above. */
export function restack(
    items: FloorItem[],
    key: string,
    action: LayerOrderAction,
): FloorItem[] {
    const index = items.findIndex((item) => itemKey(item) === key);
    if (index < 0) return items;
    const target = {
        forward: Math.min(items.length - 1, index + 1),
        backward: Math.max(0, index - 1),
        front: items.length - 1,
        back: 0,
    }[action];
    if (target === index) return items;
    const next = items.filter((_, position) => position !== index);
    next.splice(target, 0, items[index]);
    return next;
}

export function canRestack(
    items: FloorItem[],
    key: string,
    action: LayerOrderAction,
): boolean {
    const index = items.findIndex((item) => itemKey(item) === key);
    return (
        index >= 0 &&
        (action === 'forward' || action === 'front'
            ? index < items.length - 1
            : index > 0)
    );
}

/** The next free name of a series: AP-01 → AP-02, Presa 1.12 → Presa 1.13, Stampante → Stampante 2. */
export function nextName(name: string, taken: Set<string>): string {
    const match = name.match(/^(.*?)(\d+)(\D*)$/);
    const [prefix, digits, suffix] = match
        ? [match[1], match[2], match[3]]
        : [`${name} `, '1', ''];
    for (let number = Number(digits) + 1; ; number++) {
        const candidate = `${prefix}${String(number).padStart(digits.length, '0')}${suffix}`;
        if (!taken.has(candidate.toLocaleLowerCase())) return candidate;
    }
}

/** A round length, in metres, that is drawn between 60 and 160 pixels at the given pixels per metre. */
export function scaleBarLength(pixelsPerMetre: number): number {
    for (const metres of [
        0.5, 1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000,
    ]) {
        if (metres * pixelsPerMetre >= 60) return metres;
    }
    return 5000;
}
