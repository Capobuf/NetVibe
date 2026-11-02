import {
    boundsOf,
    imageBounds,
    portBounds,
    RACK_UNIT_HEIGHT,
    RACK_WIDTH,
    type ConnectorType,
    type Face,
    type Point,
} from '@/lib/hardware-editor/document';
import { isRackMounted, itemBounds } from '@/lib/rack-layout';
import type { RackDevice, RackItem } from '@/types/rack';

export const cableTypes = {
    copper: 'Rame / rete',
    fiber: 'Fibra',
    usb: 'USB',
    power: 'Alimentazione',
} as const;
export type CableEnd = { device_id: number; connector_id: string };
export type Cable = {
    id: number;
    a: CableEnd;
    b: CableEnd;
    type: keyof typeof cableTypes;
    color: string;
    purpose: string | null;
    name: string | null;
};
export const RACK_LEFT = 104;
export const RACK_TOP = 48;
export const FACE_WIDTH = RACK_WIDTH + RACK_LEFT * 2;
export type PortAnchor = CableEnd &
    Point & { face: Face; type: ConnectorType; width: number; height: number };
export const endKey = (end: CableEnd) => `${end.device_id}:${end.connector_id}`;
export const cableCode = (cable: Cable) =>
    cable.id > 0 ? `C-${cable.id}` : 'Nuovo cavo';
export const bundleKey = (cable: Cable) =>
    [cable.a.device_id, cable.b.device_id].sort((a, b) => a - b).join(':');
export function devicePorts(device?: RackDevice) {
    return (['front', 'rear'] as const).flatMap((face) =>
        (device?.model?.panel?.faces[face].ports ?? []).map((port) => ({
            ...port,
            label:
                device?.interfaces?.find(
                    (item) => item.connector_id === port.id,
                )?.name || port.label,
            face,
        })),
    );
}
export function endLabel(
    end: CableEnd,
    devices: RackDevice[],
    separator = ' · ',
) {
    const device = devices.find((d) => d.id === end.device_id);
    const port = devicePorts(device).find((p) => p.id === end.connector_id);
    return `${device?.name ?? 'Dispositivo rimosso'}${separator}${port?.label || end.connector_id.slice(0, 8)}`;
}
export function connectionTypes(type: ConnectorType): Cable['type'][] {
    if (type === 'rj45' || type === 'console') return ['copper'];
    if (type === 'sfp' || type === 'qsfp') return ['copper', 'fiber'];
    if (type.startsWith('usb')) return ['usb'];
    if (['ac', 'acC19', 'acC7', 'acC5', 'dc'].includes(type)) return ['power'];
    return [];
}
export function compatibleCableTypes(cable: Cable, devices: RackDevice[]) {
    const ports = [cable.a, cable.b]
        .map((end) =>
            devicePorts(devices.find((d) => d.id === end.device_id)).find(
                (port) => port.id === end.connector_id,
            ),
        )
        .filter((port) => port !== undefined);
    if (!ports.length) return [];
    return connectionTypes(ports[0].type).filter((type) =>
        ports.every((port) => connectionTypes(port.type).includes(type)),
    );
}
export function normalizeCableType(cable: Cable, devices: RackDevice[]): Cable {
    const types = compatibleCableTypes(cable, devices);
    return types.length && !types.includes(cable.type)
        ? { ...cable, type: types[0] }
        : cable;
}
export function cableError(
    cable: Cable,
    cables: Cable[],
    devices: RackDevice[],
) {
    if (endKey(cable.a) === endKey(cable.b))
        return 'Scegli due porte differenti.';
    for (const end of [cable.a, cable.b]) {
        const device = devices.find((d) => d.id === end.device_id);
        const port = devicePorts(device).find((p) => p.id === end.connector_id);
        if (!port)
            return 'Scegli un dispositivo e una porta per entrambe le terminazioni.';
        if (!connectionTypes(port.type).includes(cable.type))
            return `Tipo di cavo incompatibile con ${endLabel(end, devices)}.`;
        const occupied = cables.find(
            (c) =>
                c.id !== cable.id &&
                [c.a, c.b].some((e) => endKey(e) === endKey(end)),
        );
        if (occupied)
            return `${endLabel(end, devices)} è occupata da ${cableCode(occupied)}. Scollega prima il cavo.`;
    }
    return null;
}
export function cableBundles(cables: Cable[]) {
    const groups = new Map<string, Cable[]>();
    for (const cable of cables) {
        const key = bundleKey(cable);
        const group = groups.get(key);
        if (group) group.push(cable);
        else groups.set(key, [cable]);
    }
    return [...groups].sort(([a], [b]) => a.localeCompare(b));
}

// The drawing and the anchors must use exactly the same transform, including protruding ports/images.
export function panelTransform(
    device: Pick<RackDevice, 'model'>,
    face: Face,
    width: number,
    height: number,
) {
    const panel = device.model?.panel;
    if (!panel) return { x: 0, y: 0, scale: 1 };
    const side = panel.faces[face];
    const box = boundsOf([
        panel.shell,
        ...side.ports.filter((p) => !p.hidden).map(portBounds),
        ...(side.image && !side.image.hidden && !side.image.clip
            ? [imageBounds(side.image)]
            : []),
    ]);
    const padding = isRackMounted(device) ? 0 : 4;
    const scale = Math.min(
        (width - padding * 2) / Math.max(1, box.width),
        (height - padding * 2) / Math.max(1, box.height),
    );
    return {
        x: (width - box.width * scale) / 2 - box.x * scale,
        y: height - (box.y + box.height) * scale,
        scale,
    };
}
export function portAnchors(
    items: RackItem[],
    devices: RackDevice[],
    height: number,
    faces: Face[],
): PortAnchor[] {
    const byDevice = new Map(devices.map((device) => [device.id, device]));
    return faces.flatMap((face, index) =>
        items.flatMap((item) => {
            const device = item.device_id
                ? byDevice.get(item.device_id)
                : undefined;
            if (!device) return [];
            const width = (RACK_WIDTH * item.width) / 100;
            const itemHeight = RACK_UNIT_HEIGHT * item.height;
            const transform = panelTransform(device, face, width, itemHeight);
            const left =
                RACK_LEFT +
                index * FACE_WIDTH +
                (RACK_WIDTH *
                    (face === 'rear' ? 100 - item.x - item.width : item.x)) /
                    100;
            const top =
                RACK_TOP +
                (height - itemBounds(item, items).top) * RACK_UNIT_HEIGHT;
            return devicePorts(device)
                .filter((p) => p.face === face && !p.hidden)
                .flatMap((p) => {
                    const x = transform.x + p.x * transform.scale;
                    const y = transform.y + p.y * transform.scale;
                    if (x < 0 || x > width || y < 0 || y > itemHeight)
                        return [];
                    const box = portBounds(p);
                    return [
                        {
                            device_id: device.id,
                            connector_id: p.id,
                            face,
                            type: p.type,
                            x: left + x,
                            y: top + y,
                            width: box.width * transform.scale,
                            height: box.height * transform.scale,
                        },
                    ];
                });
        }),
    );
}

export function cableCurve(a: Point, b: Point, lane: number): number[] {
    if (Math.abs(b.y - a.y) < 20) {
        const top = Math.min(a.y, b.y) - 24;
        return [
            a.x,
            a.y,
            lane,
            a.y,
            lane,
            top,
            lane,
            top,
            lane,
            top,
            lane,
            b.y,
            b.x,
            b.y,
        ];
    }
    const bend = Math.min(22, Math.abs(b.y - a.y) / 3);
    const direction = b.y >= a.y ? 1 : -1;
    return [
        a.x,
        a.y,
        lane,
        a.y,
        lane,
        a.y,
        lane,
        a.y + direction * bend,
        lane,
        b.y - direction * bend,
        lane,
        b.y,
        b.x,
        b.y,
    ];
}
