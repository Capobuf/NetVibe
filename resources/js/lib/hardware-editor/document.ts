export type Face = 'front' | 'rear';
export type Point = { x: number; y: number };
export type PointerMotion = { pointer: Point; position: Point };

export function advancePointer(
    previous: PointerMotion,
    pointer: Point,
    precise: boolean,
): PointerMotion {
    const factor = precise ? 0.1 : 1;
    return {
        pointer,
        position: {
            x: previous.position.x + (pointer.x - previous.pointer.x) * factor,
            y: previous.position.y + (pointer.y - previous.pointer.y) * factor,
        },
    };
}

export const connectorCategories = {
    rj45: 'RJ45',
    sfp: 'SFP / SFP+',
    qsfp: 'QSFP',
    usbA: 'USB-A',
    usbC: 'USB-C',
    usbMiniB: 'USB Mini-B',
    usbMicroB: 'USB Micro-B',
    ac: 'Alimentazione AC',
    dc: 'Alimentazione DC',
} as const;
export type ConnectorCategory = keyof typeof connectorCategories;
export const connectorTypes = {
    rj45: {
        name: 'RJ45',
        width: 34,
        height: 28,
        category: 'rj45',
        color: '#3b82f6',
    },
    sfp: {
        name: 'SFP / SFP+',
        width: 26,
        height: 18,
        category: 'sfp',
        color: '#ef4444',
    },
    qsfp: {
        name: 'QSFP',
        width: 38,
        height: 20,
        category: 'qsfp',
        color: '#ef4444',
    },
    console: {
        name: 'RJ45 · Console seriale',
        width: 34,
        height: 28,
        category: 'rj45',
        color: '#facc15',
    },
    usbA: {
        name: 'USB-A',
        width: 30,
        height: 14,
        category: 'usbA',
        color: '#8998aa',
    },
    usbC: {
        name: 'USB-C',
        width: 22,
        height: 10,
        category: 'usbC',
        color: '#8998aa',
    },
    usbMiniB: {
        name: 'USB Mini-B',
        width: 24,
        height: 14,
        category: 'usbMiniB',
        color: '#8998aa',
    },
    usbMicroB: {
        name: 'USB Micro-B',
        width: 24,
        height: 10,
        category: 'usbMicroB',
        color: '#8998aa',
    },
    ac: {
        name: 'IEC C13',
        width: 40,
        height: 32,
        category: 'ac',
        color: '#8998aa',
    },
    acC19: {
        name: 'IEC C19',
        width: 46,
        height: 34,
        category: 'ac',
        color: '#8998aa',
    },
    acC7: {
        name: 'IEC C7',
        width: 32,
        height: 18,
        category: 'ac',
        color: '#8998aa',
    },
    acC5: {
        name: 'IEC C5',
        width: 30,
        height: 28,
        category: 'ac',
        color: '#8998aa',
    },
    dc: {
        name: 'Alimentazione DC',
        width: 22,
        height: 22,
        category: 'dc',
        color: '#8998aa',
    },
} as const;
export type ConnectorType = keyof typeof connectorTypes;
export const insertableConnectors = Object.entries(connectorTypes).filter(
    ([type]) => type !== 'console',
) as [ConnectorType, (typeof connectorTypes)[ConnectorType]][];
export const labelPositions = {
    center: 'Al centro',
    top: 'In alto',
    bottom: 'In basso',
    left: 'A sinistra',
    right: 'A destra',
    hidden: 'Nascosta',
} as const;
export const powerLabelPositions = {
    top: 'Sopra',
    bottom: 'Sotto',
    left: 'A sinistra',
    right: 'A destra',
    hidden: 'Nascosta',
} as const;
export function isPowerConnector(type: ConnectorType): boolean {
    return (
        connectorTypes[type].category === 'ac' ||
        connectorTypes[type].category === 'dc'
    );
}
export type LayerState = { hidden: boolean; locked: boolean };
export type Port = Point &
    LayerState & {
        id: string;
        type: ConnectorType;
        rotation: number;
        scale: number;
        label: string;
        labelPosition: keyof typeof labelPositions;
        poe: boolean;
        group: string | null;
        opacity: number;
        opacityCustomized: boolean;
        borderColor: string;
    };
export type PanelImage = Point &
    LayerState & {
        assetId: string;
        width: number;
        height: number;
        scale: number;
        opacity: number;
        clip: boolean;
        crop: Bounds;
    };
export type PanelDocument = {
    version: 3;
    name: string;
    shell: Point & {
        width: number;
        height: number;
        radius: number;
        border: number;
    };
    faces: Record<
        Face,
        {
            ports: Port[];
            image: PanelImage | null;
            order: string[];
            shellState: LayerState;
        }
    >;
    numbering: { next: number; prefix: string };
};
export type Bounds = Point & { width: number; height: number };
export type Numbering = {
    start: number;
    prefix: string;
    order: 'rows' | 'columns' | 'snake';
    reverseX: boolean;
    reverseY: boolean;
};

// getRandomValues also works on the development instance served over HTTP.
export function newId(): string {
    return Array.from(crypto.getRandomValues(new Uint32Array(4)), (n) =>
        n.toString(16).padStart(8, '0'),
    ).join('');
}
// Nominal millimetres keep model panels and the rack canvas on the same scale.
export const RACK_WIDTH = 482.6;
export const RACK_UNIT_HEIGHT = 44.45;

export function blankDocument(
    rackHeightU: number | null = null,
): PanelDocument {
    return {
        version: 3,
        name: 'Pannello senza titolo',
        shell: {
            x: 0,
            y: 0,
            width: rackHeightU ? RACK_WIDTH : 900,
            height: rackHeightU ? rackHeightU * RACK_UNIT_HEIGHT : 180,
            radius: 0,
            border: 2,
        },
        faces: {
            front: {
                ports: [],
                image: null,
                order: [],
                shellState: { hidden: false, locked: false },
            },
            rear: {
                ports: [],
                image: null,
                order: [],
                shellState: { hidden: false, locked: false },
            },
        },
        numbering: { next: 1, prefix: '' },
    };
}
export function makePort(type: ConnectorType, point: Point): Port {
    return {
        id: newId(),
        type,
        x: point.x,
        y: point.y,
        rotation: 0,
        scale: 1,
        label: '',
        labelPosition: isPowerConnector(type) ? 'bottom' : 'center',
        poe: false,
        group: null,
        opacity: 1,
        opacityCustomized: false,
        borderColor: connectorTypes[type].color,
        hidden: false,
        locked: false,
    };
}
export function portBounds(port: Port): Bounds {
    const type = connectorTypes[port.type];
    const sideways = port.rotation % 180 !== 0;
    const width = (sideways ? type.height : type.width) * port.scale;
    const height = (sideways ? type.width : type.height) * port.scale;
    return { x: port.x - width / 2, y: port.y - height / 2, width, height };
}
export function portBodyBounds(port: Port): Bounds {
    const bounds = portBounds(port);
    if (port.type !== 'rj45' && port.type !== 'console') return bounds;
    // Exclude the RJ45 notch from the area used by labels and badges.
    const sideways = port.rotation % 180 !== 0;
    const angle = (port.rotation * Math.PI) / 180;
    const width = bounds.width - (sideways ? 6 * port.scale : 0);
    const height = bounds.height - (sideways ? 0 : 6 * port.scale);
    return {
        x: port.x + 3 * Math.sin(angle) * port.scale - width / 2,
        y: port.y - 3 * Math.cos(angle) * port.scale - height / 2,
        width,
        height,
    };
}
export function portLabelPosition(port: Port): Port['labelPosition'] {
    return isPowerConnector(port.type) && port.labelPosition === 'center'
        ? 'bottom'
        : port.labelPosition;
}
export const portLabelFontFamily = 'ui-monospace, monospace';
let labelMeasureContext: CanvasRenderingContext2D | null = null;
function portLabelWidth(label: string, fontSize: number) {
    if (typeof document !== 'undefined') {
        labelMeasureContext ??= document
            .createElement('canvas')
            .getContext('2d');
        if (labelMeasureContext) {
            labelMeasureContext.font = `${fontSize}px ${portLabelFontFamily}`;
            return labelMeasureContext.measureText(label).width;
        }
    }
    // Geometry tests run without a browser canvas.
    return Array.from(label).length * fontSize * 0.6;
}
function portLabelText(label: string, width: number, fontSize: number) {
    const characters = Array.from(label);
    if (portLabelWidth(label, fontSize) <= width)
        return { text: label, truncated: false };
    let capacity = Math.min(
        characters.length - 1,
        Math.floor(width / portLabelWidth('M', fontSize)),
    );
    while (capacity >= 3) {
        const start = Math.floor((capacity - 1) / 2);
        const end = capacity - 1 - start;
        const text = `${characters.slice(0, start).join('')}…${characters.slice(-end).join('')}`;
        if (portLabelWidth(text, fontSize) <= width)
            return { text, truncated: true };
        capacity--;
    }
    return { text: '…', truncated: true };
}
export function portLabelLayout(port: Port, label: string) {
    const bounds = portBodyBounds(port);
    const centerX = bounds.x + bounds.width / 2 - port.x;
    const centerY = bounds.y + bounds.height / 2 - port.y;
    if (isPowerConnector(port.type)) {
        const position = portLabelPosition(port);
        const fontSize = 12 * port.scale;
        const fullWidth = portLabelWidth(label, fontSize) + 0.1 * port.scale;
        const width = Math.min(fullWidth, bounds.width * 2);
        const gap = 4 * port.scale;
        return {
            x:
                position === 'left'
                    ? -bounds.width / 2 - gap - width
                    : position === 'right'
                      ? bounds.width / 2 + gap
                      : -width / 2,
            y:
                position === 'top'
                    ? -bounds.height / 2 - gap - fontSize
                    : position === 'left' || position === 'right'
                      ? -fontSize / 2
                      : bounds.height / 2 + gap,
            width,
            height: fontSize,
            fontSize,
            ...portLabelText(label, width, fontSize),
        };
    }
    const width = bounds.width - 1.5 * port.scale;
    const availableHeight = bounds.height * 0.45;
    const fontSize = Math.min(
        12 * port.scale,
        availableHeight,
        Math.max(8 * port.scale, width / Math.max(1, portLabelWidth(label, 1))),
    );
    const textWidth = Math.min(
        width,
        portLabelWidth(label, fontSize) + 0.1 * port.scale,
    );
    return {
        x:
            centerX +
            (port.labelPosition === 'left'
                ? -width / 2
                : port.labelPosition === 'right'
                  ? width / 2 - textWidth
                  : -textWidth / 2),
        y:
            centerY +
            (port.labelPosition === 'top'
                ? -availableHeight / 2
                : port.labelPosition === 'bottom'
                  ? availableHeight / 2 - fontSize
                  : -fontSize / 2),
        width: textWidth,
        height: fontSize,
        fontSize,
        ...portLabelText(label, textWidth, fontSize),
    };
}
export function boundsOf(boxes: Bounds[]): Bounds {
    const x = Math.min(...boxes.map((p) => p.x));
    const y = Math.min(...boxes.map((p) => p.y));
    return {
        x,
        y,
        width: Math.max(...boxes.map((p) => p.x + p.width)) - x,
        height: Math.max(...boxes.map((p) => p.y + p.height)) - y,
    };
}
export function outsideShell(
    port: Port,
    shell: PanelDocument['shell'],
): boolean {
    const b = portBounds(port);
    return (
        b.x < shell.x ||
        b.y < shell.y ||
        b.x + b.width > shell.x + shell.width ||
        b.y + b.height > shell.y + shell.height
    );
}
export function updatePorts(
    doc: PanelDocument,
    face: Face,
    ports: Port[],
): PanelDocument {
    return {
        ...doc,
        faces: { ...doc.faces, [face]: { ...doc.faces[face], ports } },
    };
}
export function duplicatePorts(ports: Port[]): Port[] {
    const groups = new Map<string, string>();
    return ports.map((p) => {
        if (p.group && !groups.has(p.group)) groups.set(p.group, newId());
        return {
            ...p,
            id: newId(),
            label: '',
            group: p.group ? groups.get(p.group)! : null,
        };
    });
}
export function pastePorts(
    doc: PanelDocument,
    face: Face,
    source: Port[],
    point?: Point,
): Port[] {
    if (!source.length) return [];
    const usedLabels = new Set(
        Object.values(doc.faces).flatMap((side) =>
            side.ports.map((port) => port.label.trim()),
        ),
    );
    const copies = duplicatePorts(source).map((port, index) => ({
        ...port,
        label: usedLabels.has(source[index].label.trim())
            ? ''
            : source[index].label,
    }));
    const box = boundsOf(copies.map(portBounds));
    let offset = 24;
    if (!point) {
        const occupied = doc.faces[face].ports.map(portBounds);
        // Move beyond each collision along the diagonal, leaving room between symbols.
        let collision: Bounds | undefined;
        while (
            (collision = occupied.find(
                (other) =>
                    box.x + offset < other.x + other.width + 12 &&
                    box.x + offset + box.width + 12 > other.x &&
                    box.y + offset < other.y + other.height + 12 &&
                    box.y + offset + box.height + 12 > other.y,
            ))
        ) {
            offset = Math.max(
                offset + 24,
                Math.min(
                    collision.x + collision.width - box.x,
                    collision.y + collision.height - box.y,
                ) + 12,
            );
        }
    }
    const delta = point
        ? {
              x: point.x - box.x - box.width / 2,
              y: point.y - box.y - box.height / 2,
          }
        : { x: offset, y: offset };
    return copies.map((port) => ({
        ...port,
        x: port.x + delta.x,
        y: port.y + delta.y,
    }));
}
export function labelConflict(doc: PanelDocument): string | null {
    const seen = new Set<string>();
    for (const p of [...doc.faces.front.ports, ...doc.faces.rear.ports]) {
        const label = p.label.trim();
        if (label && seen.has(label)) return label;
        if (label) seen.add(label);
    }
    return null;
}
export function nextNumber(
    doc: PanelDocument,
    prefix: string,
    start: number,
): number {
    const used = new Set(
        [...doc.faces.front.ports, ...doc.faces.rear.ports].map((p) => p.label),
    );
    while (used.has(`${prefix}${start}`)) start++;
    return start;
}
function spatialLines<T extends Point>(
    ports: T[],
    axis: 'x' | 'y',
    size: (item: T) => number,
): T[][] {
    const lines: T[][] = [];
    for (const p of [...ports].sort((a, b) => a[axis] - b[axis])) {
        const line = lines.find(
            (row) =>
                Math.abs(row[0][axis] - p[axis]) <=
                Math.min(size(row[0]), size(p)) / 2,
        );
        if (line) line.push(p);
        else lines.push([p]);
    }
    return lines;
}
export function numberingLabels(
    ports: Port[],
    options: Numbering,
): Map<string, string> {
    const columns = options.order === 'columns';
    const lines = spatialLines(
        ports,
        columns ? 'x' : 'y',
        (p) => portBounds(p)[columns ? 'width' : 'height'],
    );
    if (columns ? options.reverseX : options.reverseY) lines.reverse();
    const ordered = lines.flatMap((line, index) => {
        const axis = columns ? 'y' : 'x';
        const reverse =
            (columns ? options.reverseY : options.reverseX) !==
            (options.order === 'snake' && index % 2 === 1);
        return line.sort((a, b) => (a[axis] - b[axis]) * (reverse ? -1 : 1));
    });
    return new Map(
        ordered.map((p, index) => [
            p.id,
            `${options.prefix}${options.start + index}`,
        ]),
    );
}
export function arrangementUnits(
    ports: Port[],
    enteredGroup: string | null = null,
) {
    const groups = new Map<string, Port[]>();
    for (const port of ports) {
        const key =
            port.group && port.group !== enteredGroup
                ? `group:${port.group}`
                : `port:${port.id}`;
        const members = groups.get(key);
        if (members) members.push(port);
        else groups.set(key, [port]);
    }
    return [...groups.values()].map((members) => {
        const box = boundsOf(members.map(portBounds));
        return {
            ports: members,
            x: box.x + box.width / 2,
            y: box.y + box.height / 2,
            width: box.width,
            height: box.height,
        };
    });
}
export function arrangePorts(
    ports: Port[],
    action: string,
    gap = 12,
    enteredGroup: string | null = null,
): Port[] {
    const units = arrangementUnits(ports, enteredGroup);
    if (units.length < 2) return ports;
    const result = units.map((unit) => ({ ...unit }));
    const box = boundsOf(ports.map(portBounds));
    if (action.startsWith('gap-')) {
        const horizontal = action === 'gap-x';
        const axis = horizontal ? 'x' : 'y';
        const size = horizontal ? 'width' : 'height';
        for (const line of spatialLines(
            result,
            horizontal ? 'y' : 'x',
            (unit) => unit[horizontal ? 'height' : 'width'],
        )) {
            line.sort((a, b) => a[axis] - b[axis]);
            for (let i = 1; i < line.length; i++)
                line[i][axis] =
                    line[i - 1][axis] +
                    line[i - 1][size] / 2 +
                    gap +
                    line[i][size] / 2;
        }
    } else if (action.startsWith('distribute-')) {
        const axis = action === 'distribute-x' ? 'x' : 'y';
        const size = axis === 'x' ? 'width' : 'height';
        const sorted = [...result].sort((a, b) => a[axis] - b[axis]);
        const first = sorted[0][axis] - sorted[0][size] / 2;
        const last = sorted.at(-1)!;
        const space =
            (last[axis] +
                last[size] / 2 -
                first -
                sorted.reduce((n, unit) => n + unit[size], 0)) /
            (sorted.length - 1);
        let cursor = first;
        for (const unit of sorted) {
            unit[axis] = cursor + unit[size] / 2;
            cursor += unit[size] + space;
        }
    } else {
        for (const unit of result) {
            if (action === 'left') unit.x = box.x + unit.width / 2;
            if (action === 'center-x') unit.x = box.x + box.width / 2;
            if (action === 'right') unit.x = box.x + box.width - unit.width / 2;
            if (action === 'top') unit.y = box.y + unit.height / 2;
            if (action === 'center-y') unit.y = box.y + box.height / 2;
            if (action === 'bottom')
                unit.y = box.y + box.height - unit.height / 2;
        }
    }
    const moved = new Map(
        result.flatMap((unit, index) =>
            unit.ports.map(
                (port) =>
                    [
                        port.id,
                        {
                            ...port,
                            x: port.x + unit.x - units[index].x,
                            y: port.y + unit.y - units[index].y,
                        },
                    ] as const,
            ),
        ),
    );
    return ports.map((port) => moved.get(port.id)!);
}
export function createBlock(
    type: ConnectorType,
    count: number,
    columns: number,
    gapX: number,
    gapY: number,
    rotation: number,
    origin: Point,
    columnFirst = false,
    scale = 1,
): Port[] {
    const prototype = { ...makePort(type, origin), rotation, scale };
    const b = portBounds(prototype);
    const rows = Math.ceil(count / columns);
    return Array.from({ length: count }, (_, index) => ({
        ...prototype,
        id: newId(),
        x:
            origin.x +
            (columnFirst ? Math.floor(index / rows) : index % columns) *
                (b.width + gapX),
        y:
            origin.y +
            (columnFirst ? index % rows : Math.floor(index / columns)) *
                (b.height + gapY),
    }));
}
export type BlockOptions = Point & {
    type: ConnectorType;
    count: number;
    columns: number;
    gapX: number;
    gapY: number;
    rotation: number;
    columnFirst: boolean;
    scale: number;
};
export type BlockEdge = 'left' | 'right' | 'top' | 'bottom';
export type BlockCorner =
    | 'top-left'
    | 'top-right'
    | 'bottom-left'
    | 'bottom-right';
export function resizePortGroup(
    ports: Port[],
    corner: BlockCorner,
    delta: Point,
): Port[] {
    if (!ports.length) return ports;
    const boxes = ports.map(portBounds);
    const bounds = boundsOf(boxes);
    const left = corner.endsWith('left');
    const top = corner.startsWith('top');
    const x = bounds.x + (left ? 0 : bounds.width) + delta.x;
    const y = bounds.y + (top ? 0 : bounds.height) + delta.y;
    // Fit the dragged edges while keeping every port centered on its original position.
    const scaleX = Math.min(
        ...ports.map(
            (p, i) => (left ? p.x - x : x - p.x) / (boxes[i].width / 2),
        ),
    );
    const scaleY = Math.min(
        ...ports.map(
            (p, i) => (top ? p.y - y : y - p.y) / (boxes[i].height / 2),
        ),
    );
    const factor = Math.max(
        0.2 / Math.min(...ports.map((p) => p.scale)),
        Math.min(
            8 / Math.max(...ports.map((p) => p.scale)),
            Math.abs(scaleX - 1) > Math.abs(scaleY - 1) ? scaleX : scaleY,
        ),
    );
    return ports.map((port) => ({ ...port, scale: port.scale * factor }));
}
export function reshapeBlock(
    options: BlockOptions,
    corner: BlockCorner,
    delta: Point,
): BlockOptions {
    if (options.count === 1) return options;
    const port = portBounds({
        ...makePort(options.type, options),
        rotation: options.rotation,
        scale: options.scale,
    });
    const size = (columns: number) => {
        const rows = Math.ceil(options.count / columns);
        columns = options.columnFirst
            ? Math.ceil(options.count / rows)
            : columns;
        return {
            columns,
            rows,
            width: columns * port.width + (columns - 1) * options.gapX,
            height: rows * port.height + (rows - 1) * options.gapY,
        };
    };
    const original = size(options.columns);
    const directionX = corner.endsWith('left') ? -1 : 1;
    const directionY = corner.startsWith('top') ? -1 : 1;
    const width = Math.max(port.width, original.width + directionX * delta.x);
    const height = Math.max(
        port.height,
        original.height + directionY * delta.y,
    );
    const ratio = width / height;
    let columns = options.columns;
    let best = Math.abs(Math.log(original.width / original.height / ratio));
    // Compare natural grid proportions before fitting spacing, keeping symbols at their original size.
    for (let candidate = 1; candidate <= options.count; candidate++) {
        const box = size(candidate);
        const score = Math.abs(Math.log(box.width / box.height / ratio));
        if (score < best - 1e-9) {
            columns = candidate;
            best = score;
        }
    }
    const grid = size(columns);
    const spacing = (
        length: number,
        count: number,
        portSize: number,
        current: number,
    ) =>
        count === 1
            ? current
            : Math.max(
                  0,
                  Math.min(
                      300,
                      Math.round(
                          ((length - count * portSize) / (count - 1)) * 10,
                      ) / 10,
                  ),
              );
    const gapX = spacing(width, grid.columns, port.width, options.gapX);
    const gapY = spacing(height, grid.rows, port.height, options.gapY);
    const actualWidth = grid.columns * port.width + (grid.columns - 1) * gapX;
    const actualHeight = grid.rows * port.height + (grid.rows - 1) * gapY;
    return {
        ...options,
        columns,
        gapX,
        gapY,
        x: options.x + (directionX * (actualWidth - original.width)) / 2,
        y: options.y + (directionY * (actualHeight - original.height)) / 2,
    };
}
export function resizeBlockSpacing(
    options: BlockOptions,
    edge: BlockEdge,
    delta: number,
): BlockOptions {
    const horizontal = edge === 'left' || edge === 'right';
    const rows = Math.ceil(options.count / options.columns);
    const columns = options.columnFirst
        ? Math.ceil(options.count / rows)
        : options.columns;
    const intervals = (horizontal ? columns : rows) - 1;
    if (intervals === 0) return options;
    const axis = horizontal ? 'x' : 'y';
    const key = horizontal ? 'gapX' : 'gapY';
    const direction = edge === 'left' || edge === 'top' ? -1 : 1;
    const gap = Math.max(
        0,
        Math.min(
            300,
            Math.round((options[key] + (direction * delta) / intervals) * 10) /
                10,
        ),
    );
    return {
        ...options,
        [key]: gap,
        [axis]:
            options[axis] + (direction * (gap - options[key]) * intervals) / 2,
    };
}
export function previewBlock(options: BlockOptions): Port[] {
    const ports = createBlock(
        options.type,
        options.count,
        options.columns,
        options.gapX,
        options.gapY,
        options.rotation,
        options,
        options.columnFirst,
        options.scale,
    );
    const box = boundsOf(ports.map(portBounds));
    const group = newId();
    return ports.map((port) => ({
        ...port,
        group,
        x: port.x + options.x - box.x - box.width / 2,
        y: port.y + options.y - box.y - box.height / 2,
    }));
}
export function snapDelta(
    moving: Port[],
    stationary: Port[],
    shell: PanelDocument['shell'],
    delta: Point,
    zoom: number,
): { delta: Point; guides: { axis: 'x' | 'y'; value: number }[] } {
    const box = boundsOf(moving.map(portBounds));
    const result = {
        delta: { ...delta },
        guides: [] as { axis: 'x' | 'y'; value: number }[],
    };
    for (const axis of ['x', 'y'] as const) {
        const size = axis === 'x' ? 'width' : 'height';
        const targets = [
            shell[axis],
            shell[axis] + shell[size] / 2,
            shell[axis] + shell[size],
            ...stationary.flatMap((p) => {
                const b = portBounds(p);
                return [b[axis], b[axis] + b[size] / 2, b[axis] + b[size]];
            }),
        ];
        const anchors = [
            box[axis],
            box[axis] + box[size] / 2,
            box[axis] + box[size],
        ];
        let distance = 6 / zoom;
        for (const target of targets)
            for (const anchor of anchors) {
                const offset = target - anchor - delta[axis];
                if (Math.abs(offset) < distance) {
                    distance = Math.abs(offset);
                    result.delta[axis] = delta[axis] + offset;
                    result.guides = result.guides
                        .filter((g) => g.axis !== axis)
                        .concat({ axis, value: target });
                }
            }
    }
    return result;
}

export type FaceLayer = { id: string; ids: string[] };
export type LayerOrderAction = 'forward' | 'backward' | 'front' | 'back';

// Store port IDs, including group members, so ungrouping keeps their position.
export function faceLayers(face: PanelDocument['faces'][Face]): FaceLayer[] {
    const ports = new Map(face.ports.map((p) => [p.id, p]));
    const available = [...(face.image ? ['image'] : []), ...ports.keys()];
    const order = [...new Set([...face.order, ...available])].filter((id) =>
        available.includes(id),
    );
    const layers: FaceLayer[] = [];
    for (const id of order) {
        const port = ports.get(id);
        const key = port?.group ? `group:${port.group}` : id;
        const existing = layers.findIndex((layer) => layer.id === key);
        const members = existing < 0 ? [] : layers.splice(existing, 1)[0].ids;
        layers.push({ id: key, ids: [...members, id] });
    }
    return layers;
}

export function categorizedLayers(face: PanelDocument['faces'][Face]) {
    const categories = new Map<string, { name: string; layers: FaceLayer[] }>();
    for (const layer of faceLayers(face).reverse()) {
        if (layer.id === 'image') continue;
        const types = new Set(
            layer.ids.map(
                (id) =>
                    connectorTypes[face.ports.find((p) => p.id === id)!.type]
                        .category,
            ),
        );
        const category = types.size === 1 ? [...types][0] : 'mixed';
        const section: { name: string; layers: FaceLayer[] } = categories.get(
            category,
        ) ?? {
            name:
                category === 'mixed'
                    ? 'Gruppi misti'
                    : connectorCategories[category],
            layers: [],
        };
        section.layers.push(layer);
        categories.set(category, section);
    }
    return [...categories].map(([id, section]) => ({ id, ...section }));
}

export function reorderLayers(
    layers: FaceLayer[],
    ids: string[],
    action: LayerOrderAction,
): string[] {
    const parent = layers.find(
        (layer) =>
            layer.ids.length > 1 &&
            ids.length > 0 &&
            ids.length < layer.ids.length &&
            ids.every((id) => layer.ids.includes(id)),
    );
    if (parent) {
        const children = reorderLayers(
            parent.ids.map((id) => ({ id, ids: [id] })),
            ids,
            action,
        );
        return layers.flatMap((layer) =>
            layer === parent ? children : layer.ids,
        );
    }
    const selected = (layer: FaceLayer) =>
        layer.ids.some((id) => ids.includes(id));
    const result = [...layers];
    if (action === 'front' || action === 'back') {
        const moving = result.filter(selected);
        const rest = result.filter((layer) => !selected(layer));
        return (
            action === 'front' ? [...rest, ...moving] : [...moving, ...rest]
        ).flatMap((layer) => layer.ids);
    }
    if (action === 'forward') {
        for (let i = result.length - 2; i >= 0; i--)
            if (selected(result[i]) && !selected(result[i + 1]))
                [result[i], result[i + 1]] = [result[i + 1], result[i]];
    } else {
        for (let i = 1; i < result.length; i++)
            if (selected(result[i]) && !selected(result[i - 1]))
                [result[i], result[i - 1]] = [result[i - 1], result[i]];
    }
    return result.flatMap((layer) => layer.ids);
}

export function imageBounds(image: PanelImage): Bounds {
    const crop = image.crop;
    return {
        x: image.x + crop.x * image.scale,
        y: image.y + crop.y * image.scale,
        width: crop.width * image.scale,
        height: crop.height * image.scale,
    };
}
export function cropImage(image: PanelImage, frame: Bounds): PanelImage {
    const x = Math.max(
        0,
        Math.min(image.width - 1, (frame.x - image.x) / image.scale),
    );
    const y = Math.max(
        0,
        Math.min(image.height - 1, (frame.y - image.y) / image.scale),
    );
    return {
        ...image,
        crop: {
            x,
            y,
            width: Math.max(
                1,
                Math.min(image.width - x, frame.width / image.scale),
            ),
            height: Math.max(
                1,
                Math.min(image.height - y, frame.height / image.scale),
            ),
        },
    };
}
