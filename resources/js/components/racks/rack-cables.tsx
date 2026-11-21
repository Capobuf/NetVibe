import type Konva from 'konva';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import {
    Arrow,
    Circle,
    Group,
    Label,
    Line,
    Rect,
    Tag,
    Text,
} from 'react-konva';
import {
    RACK_UNIT_HEIGHT,
    type Face,
    type Point,
} from '@/lib/hardware-editor/document';
import {
    cableLead,
    roundedCablePath,
    trunkLanes,
} from '@/lib/rack-cable-routing';
import {
    cableBundles,
    cableCode,
    cableTypes,
    connectionTypes,
    devicePorts,
    endKey,
    endLabel,
    itemBox,
    portAnchors,
    CABLE_MARGIN,
    FACE_PITCH,
    FACE_WIDTH,
    RACK_TOP,
    type Cable,
    type CableEnd,
    type PortAnchor,
} from '@/lib/rack-cabling';
import type { RackDevice, RackItem } from '@/types/rack';

// Rack frame drawn above and below the units.
const FRAME = 14;

let ruler: CanvasRenderingContext2D | null = null;
// Konva sizes a box only around a single text: measure to lay out several.
function textWidth(text: string, size: number, bold = false) {
    ruler ??= document.createElement('canvas').getContext('2d');
    if (!ruler) return text.length * size * 0.6;
    ruler.font = `${bold ? 'bold ' : ''}100px Arial`;
    return (ruler.measureText(text).width * size) / 100;
}
// Eases between 0 and 1 as the flag flips, e.g. to open and close the tunnel.
function useProgress(open: boolean) {
    const [value, setValue] = useState(open ? 1 : 0);
    const current = useRef(value);
    useEffect(() => {
        const target = open ? 1 : 0;
        const from = current.current;
        if (from === target) return;
        const instant = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        const start = performance.now();
        let frame = requestAnimationFrame(function step(time) {
            const progress = instant ? 1 : Math.min(1, (time - start) / 320);
            const eased = progress * progress * (3 - 2 * progress);
            current.current = from + (target - from) * eased;
            setValue(current.current);
            if (progress < 1) frame = requestAnimationFrame(step);
        });
        return () => cancelAnimationFrame(frame);
    }, [open]);
    return value;
}
type PortInfo = {
    title: string;
    target?: string;
    detail?: string;
    color?: string;
};

type Box = { x: number; y: number; width: number; height: number };

type Props = {
    items: RackItem[];
    devices: RackDevice[];
    height: number;
    faces: Face[];
    scale: number;
    zoom: number;
    dark: boolean;
    cables: Cable[];
    allCables: Cable[];
    focusedBundle: string | null;
    selectedCable: number | null;
    pendingCable: Cable | null;
    editing: boolean;
    onPort: (end: CableEnd) => void;
    onConnect: (a: CableEnd, b: CableEnd) => void;
    onConnectionChange: (deviceId: number | null) => void;
    onBundle: (key: string | null) => void;
    onCable: (cable: Cable) => void;
    onReconnect: (id: number, side: 'a' | 'b', end: CableEnd) => void;
    // Drawn above the cables and below the ports, e.g. the unit numbers.
    overlay?: ReactNode;
};

export function RackCables({
    items,
    devices,
    height,
    faces,
    scale,
    zoom,
    dark,
    cables,
    allCables,
    focusedBundle,
    selectedCable,
    pendingCable,
    editing,
    onPort,
    onConnect,
    onConnectionChange,
    onBundle,
    onCable,
    onReconnect,
    overlay,
}: Props) {
    const anchors = portAnchors(items, devices, height, faces);
    const byEnd = new Map(anchors.map((a) => [endKey(a), a]));
    const occupied = new Map(
        allCables.flatMap(
            (c) =>
                [
                    [endKey(c.a), c],
                    [endKey(c.b), c],
                ] as const,
        ),
    );
    const groups = cableBundles(cables);
    const routes: (Face | 'cross')[] =
        faces.length === 2 ? [...faces, 'cross'] : faces;
    const selected = cables.find((c) => c.id === selectedCable);
    const [hover, setHover] = useState<{ point: Point; text: PortInfo } | null>(
        null,
    );
    const [pointer, setPointer] = useState<Point | null>(null);
    const [dragging, setDragging] = useState<'a' | 'b' | null>(null);
    const [connection, setConnection] = useState<{
        source: PortAnchor;
        point: Point;
    } | null>(null);
    const draggedPort = useRef<Konva.Node | null>(null);
    const root = useRef<Konva.Group>(null);
    // The bundles between the faces start collapsed into one tunnel.
    const [tunnelOpen, setTunnelOpen] = useState(false);
    if (focusedBundle && !tunnelOpen) setTunnelOpen(true);
    useEffect(() => {
        const stage = root.current?.getStage();
        if (!stage) return;
        // An empty click first leaves the open bundle, then closes the tunnel.
        const collapse = (event: Konva.KonvaEventObject<Event>) => {
            if (event.target === stage && !focusedBundle) setTunnelOpen(false);
        };
        stage.on('click.tunnel tap.tunnel', collapse);
        return () => {
            stage.off('click.tunnel tap.tunnel');
        };
    }, [focusedBundle]);
    const [bundleProgress, setBundleProgress] = useState<
        Record<string, number>
    >({});
    const animationProgress = useRef(bundleProgress);
    const ink = dark ? '#cbd5e1' : '#475569';
    const accent = dark ? '#93c5fd' : '#1d4ed8';
    const background = dark ? '#0f172a' : '#ffffff';
    const cableUnit = Math.min(2, Math.max(0.85, Math.sqrt(zoom))) / scale;
    useEffect(() => {
        const cancel = (event: KeyboardEvent) => {
            if (event.key !== 'Escape' || !draggedPort.current) return;
            const node = draggedPort.current;
            draggedPort.current = null;
            node.stopDrag();
            setConnection(null);
            setDragging(null);
            setPointer(null);
            onConnectionChange(null);
        };
        window.addEventListener('keydown', cancel);
        return () => window.removeEventListener('keydown', cancel);
    }, [onConnectionChange]);
    useEffect(() => {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            const next = focusedBundle ? { [focusedBundle]: 1 } : {};
            animationProgress.current = next;
            setBundleProgress(next);
            return;
        }
        const from = { ...animationProgress.current };
        if (focusedBundle) from[focusedBundle] ??= 0;
        let frame = 0;
        const start = performance.now();
        const animate = (time: number) => {
            const progress = Math.min(1, (time - start) / 320);
            const eased = progress * progress * (3 - 2 * progress);
            const next: Record<string, number> = {};
            for (const [key, value] of Object.entries(from)) {
                const target = key === focusedBundle ? 1 : 0;
                if (progress < 1 || target)
                    next[key] = value + (target - value) * eased;
            }
            animationProgress.current = next;
            setBundleProgress(next);
            if (progress < 1) frame = requestAnimationFrame(animate);
        };
        frame = requestAnimationFrame(animate);
        return () => cancelAnimationFrame(frame);
    }, [focusedBundle]);

    function continuation(end: CableEnd, face: Face) {
        const device = devices.find((d) => d.id === end.device_id);
        const port = devicePorts(device).find((p) => p.id === end.connector_id);
        return {
            name: device?.name ?? 'Dispositivo rimosso',
            place: !items.some((i) => i.device_id === end.device_id)
                ? (device?.rack_item?.rack.name ?? 'Fuori rack')
                : port?.hidden
                  ? 'Porta nascosta'
                  : port && port.face !== face
                    ? port.face === 'front'
                        ? 'Fronte'
                        : 'Retro'
                    : 'Porta fuori vista',
        };
    }
    function segment(cable: Cable, route: Face | 'cross') {
        const ends = [cable.a, cable.b].sort(
            (a, b) =>
                a.device_id - b.device_id ||
                a.connector_id.localeCompare(b.connector_id),
        );
        const a = byEnd.get(endKey(ends[0]));
        const b = byEnd.get(endKey(ends[1]));
        if (a && b && a.face !== b.face) {
            if (route !== 'cross') return null;
            return a.face === 'front'
                ? { a, b, remote: null }
                : { a: b, b: a, remote: null };
        }
        if (route === 'cross') return null;
        const first = a?.face === route ? a : b?.face === route ? b : null;
        if (!first) return null;
        const second = a?.face === route && b?.face === route ? b : null;
        return {
            a: first,
            b: second,
            remote: second ? null : first === a ? ends[1] : ends[0],
        };
    }
    const font = Math.min(30, 11 / scale);
    const tagHeight = font + 10;
    const tagWidth = (text: string) => textWidth(text, font) + 12;
    const strong = dark ? '#f1f5f9' : '#0f172a';
    const destinationHeight = font * 2.1;
    // Where a link leaving the view ends up: an arrow with the device and its location.
    function destination(
        point: Point,
        right: boolean,
        title: string,
        place: string,
        activate: () => void,
    ) {
        const width = Math.min(
            Math.max(
                textWidth(title, font, true),
                textWidth(place, font * 0.85),
            ) + 2,
            // Two faces fill the canvas, so stay inside the margin; a single face leaves room beside it.
            faces.length === 2 ? CABLE_MARGIN - 18 * cableUnit : font * 12,
        );
        const text = {
            width,
            align: right ? 'left' : 'right',
            ellipsis: true,
            wrap: 'none',
        };
        const gap = 5 * cableUnit;
        return (
            <Group
                x={right ? point.x + gap : point.x - gap - width}
                y={point.y - destinationHeight / 2}
                onClick={activate}
                onTap={activate}
            >
                <Text
                    {...text}
                    text={title}
                    fontSize={font}
                    fontStyle="bold"
                    fill={strong}
                />
                <Text
                    {...text}
                    y={font * 1.2}
                    text={place}
                    fontSize={font * 0.85}
                    fill={ink}
                />
            </Group>
        );
    }
    const arrowHead = (from: Point, to: Point, color: string) => (
        <Arrow
            points={[from.x, from.y, to.x, to.y]}
            pointerLength={7 * cableUnit}
            pointerWidth={7 * cableUnit}
            fill={color}
            strokeEnabled={false}
            listening={false}
        />
    );
    function tag(
        key: string,
        point: Point,
        text: string,
        activate: () => void,
        color = ink,
        align: 'left' | 'center' | 'right' = 'left',
        max = Infinity,
    ) {
        const width = Math.min(tagWidth(text), max);
        return (
            <Label
                key={key}
                x={
                    point.x -
                    (align === 'left'
                        ? 0
                        : align === 'center'
                          ? width / 2
                          : width)
                }
                y={point.y - tagHeight / 2}
                onClick={activate}
                onTap={activate}
            >
                <Tag
                    fill={background}
                    stroke={color}
                    strokeWidth={1 / scale}
                    cornerRadius={4}
                />
                <Text
                    text={text}
                    padding={5}
                    width={width < tagWidth(text) ? width : undefined}
                    ellipsis
                    wrap="none"
                    fontSize={font}
                    fill={color}
                />
            </Label>
        );
    }
    function portInfo(anchor: PortAnchor): PortInfo {
        const cable = occupied.get(endKey(anchor));
        const title = endLabel(anchor, devices);
        if (!cable) return { title };
        return {
            title,
            target: endLabel(
                endKey(cable.a) === endKey(anchor) ? cable.b : cable.a,
                devices,
            ),
            detail: [cableCode(cable), cableTypes[cable.type], cable.purpose]
                .filter(Boolean)
                .join(' · '),
            color: cable.color,
        };
    }
    function portAt(point: Point) {
        return anchors
            .filter(
                (a) =>
                    Math.abs(a.x - point.x) <= a.width / 2 + 5 / scale &&
                    Math.abs(a.y - point.y) <= a.height / 2 + 5 / scale,
            )
            .sort(
                (a, b) =>
                    Math.hypot(a.x - point.x, a.y - point.y) -
                    Math.hypot(b.x - point.x, b.y - point.y),
            )[0];
    }
    const rackHeight = height * RACK_UNIT_HEIGHT;
    // Each face with its unit numbers is one block: bundles run in the free space around it.
    const blocks: Box[] = faces.map((_, index) => ({
        x: index * FACE_PITCH + CABLE_MARGIN,
        y: RACK_TOP - FRAME,
        width: FACE_WIDTH - 2 * CABLE_MARGIN,
        height: rackHeight + 2 * FRAME,
    }));
    const deviceBox = (anchor: PortAnchor) =>
        itemBox(
            items.find((item) => item.device_id === anchor.device_id)!,
            items,
            height,
            anchor.face,
            faces.indexOf(anchor.face),
        );
    // Cables inside the view meet between the faces; those leaving it exit on the outer side.
    function terminal(anchor: PortAnchor, outer = false) {
        const index = faces.indexOf(anchor.face);
        const box = deviceBox(anchor);
        const right = (anchor.face === 'rear') === outer;
        const y = box.y + box.height / 2;
        const device = { x: right ? box.x + box.width : box.x, y };
        return {
            index,
            right,
            y,
            device,
            rack: {
                x: right
                    ? blocks[index].x + blocks[index].width
                    : blocks[index].x,
                y,
            },
        };
    }
    const trunks = groups.flatMap(([key, members]) =>
        routes.flatMap((route) => {
            const segments = members.flatMap((cable) => {
                const part = segment(cable, route);
                return part ? [{ cable, part }] : [];
            });
            if (!segments.length) return [];
            const colors = new Set(segments.map(({ cable }) => cable.color));
            return [
                {
                    key,
                    segments,
                    ...segments[0].part,
                    color: colors.size === 1 ? segments[0].cable.color : ink,
                    mixed: colors.size > 1,
                },
            ];
        }),
    );
    const localTrunks = trunks.filter((trunk) => trunk.b);
    const corridor =
        faces.length === 2
            ? { left: blocks[0].x + blocks[0].width, right: blocks[1].x }
            : faces[0] === 'rear'
              ? { left: 0, right: blocks[0].x }
              : { left: blocks[0].x + blocks[0].width, right: FACE_WIDTH };
    const lanes = trunkLanes(
        localTrunks.map((trunk) => {
            const ys = [terminal(trunk.a).y, terminal(trunk.b!).y];
            return {
                top: Math.min(...ys),
                bottom: Math.max(...ys),
                zone:
                    trunk.a.face !== trunk.b!.face
                        ? 0
                        : trunk.a.face === 'rear'
                          ? 1
                          : -1,
            };
        }),
        corridor.left + 10,
        corridor.right - 10,
        14 * cableUnit,
    );
    // Bundles leaving the same device are spread along its edge in the order that keeps them from crossing:
    // those going up from the nearest lane outwards, then those going down from the farthest lane inwards.
    const exits = new Map<string, number>();
    const sharing = new Map<
        string,
        { id: string; y: number; up: boolean; reach: number }[]
    >();
    localTrunks.forEach((trunk, index) => {
        const ends = [terminal(trunk.a), terminal(trunk.b!)];
        ends.forEach((end, side) => {
            const key = `${end.index}:${(side ? trunk.b! : trunk.a).device_id}`;
            const list = sharing.get(key) ?? [];
            sharing.set(key, list);
            list.push({
                id: `${index}:${side}`,
                y: end.y,
                up: ends[1 - side].y < end.y,
                reach: Math.abs(lanes[index] - end.device.x),
            });
        });
    });
    for (const list of sharing.values()) {
        list.sort((a, b) =>
            a.up !== b.up
                ? a.up
                    ? -1
                    : 1
                : a.up
                  ? a.reach - b.reach
                  : b.reach - a.reach,
        );
        const step = Math.min(
            9 * cableUnit,
            (RACK_UNIT_HEIGHT * 0.7) / list.length,
        );
        list.forEach((entry, index) =>
            exits.set(
                entry.id,
                entry.y + (index - (list.length - 1) / 2) * step,
            ),
        );
    }

    // The open bundle is drawn cable by cable: each one leaves its port vertically, runs just outside
    // the device edge and reaches its own lane. Ports far from the lanes run deeper and take the
    // nearest lane, so cables of the same device do not cross.
    const gap = 6 * cableUnit;
    const clearance = Math.min(gap, 9);
    const openLinks = trunks
        .filter((trunk) => trunk.key === focusedBundle)
        .flatMap((trunk) => {
            const count = trunk.segments.length;
            const depth = Math.min(gap, 11 / count);
            const start = terminal(trunk.a, !trunk.b);
            const ranking = (ports: PortAnchor[], right: boolean) => {
                const sorted = ports.toSorted(
                    (a, b) => (right ? a.x - b.x : b.x - a.x) || a.y - b.y,
                );
                return (port: PortAnchor) => sorted.indexOf(port);
            };
            const lead = (
                port: PortAnchor,
                lane: number,
                rank: number,
                down: boolean,
            ) => {
                const box = deviceBox(port);
                const offset = clearance + (count - 1 - rank) * depth;
                const levels = [box.y + box.height + offset, box.y - offset];
                return cableLead(
                    port,
                    lane,
                    anchors,
                    0,
                    gap,
                    down ? levels : levels.toReversed(),
                )!;
            };
            const rankA = ranking(
                trunk.segments.map(({ part }) => part.a),
                start.right,
            );
            if (!trunk.b) {
                const side = start.right ? 1 : -1;
                const step = Math.min(gap, 40 / count);
                const box = deviceBox(trunk.a);
                return trunk.segments.map(({ cable, part }) => {
                    const rank = rankA(part.a);
                    const lane = start.rack.x + side * (8 + rank * step);
                    const exit: (Point & { right: boolean }) | null = {
                        x: start.rack.x + side * (12 + count * step),
                        y:
                            box.y +
                            box.height +
                            clearance +
                            11 +
                            (count - rank - 0.5) * (destinationHeight + 6),
                        right: start.right,
                    };
                    return {
                        cable,
                        part,
                        exit,
                        points: [
                            ...lead(part.a, lane, rank, true),
                            { x: lane, y: exit.y },
                            exit,
                        ],
                    };
                });
            }
            const rankB = ranking(
                trunk.segments.map(({ part }) => part.b!),
                terminal(trunk.b).right,
            );
            const step = Math.min(
                gap,
                (corridor.right - corridor.left - 20) / count,
            );
            return trunk.segments.map(({ cable, part }) => {
                const rank = rankA(part.a);
                const lane =
                    (corridor.left + corridor.right) / 2 +
                    (start.right ? 1 : -1) * (rank - (count - 1) / 2) * step;
                const down = part.b!.y >= part.a.y;
                return {
                    cable,
                    part,
                    exit: null as (Point & { right: boolean }) | null,
                    points: [
                        ...lead(part.a, lane, rank, down),
                        ...lead(
                            part.b!,
                            lane,
                            rankB(part.b!),
                            !down,
                        ).toReversed(),
                    ],
                };
            });
        });
    const placed: Box[] = [];
    // Put a tag on the longest stretch of a route, sliding it away from the tags already there.
    function tagSpot(route: Point[], width: number): Point {
        const length = (i: number) =>
            Math.hypot(
                route[i + 1].x - route[i].x,
                route[i + 1].y - route[i].y,
            );
        let longest = 0;
        for (let i = 1; i < route.length - 1; i++)
            if (length(i) > length(longest)) longest = i;
        const [from, to] = [route[longest], route[longest + 1]];
        const box = (t: number) => ({
            x: from.x + (to.x - from.x) * t - width / 2,
            y: from.y + (to.y - from.y) * t - tagHeight / 2,
            width,
            height: tagHeight,
        });
        const free =
            [0.5, 0.35, 0.65, 0.2, 0.8]
                .map(box)
                .find(
                    (a) =>
                        !placed.some(
                            (b) =>
                                a.x < b.x + b.width &&
                                b.x < a.x + a.width &&
                                a.y < b.y + b.height &&
                                b.y < a.y + a.height,
                        ),
                ) ?? box(0.5);
        placed.push(free);
        return { x: free.x + width / 2, y: free.y + tagHeight / 2 };
    }
    const flat = (points: Point[]) =>
        roundedCablePath(
            points.map(({ x, y }) => ({ x, y })),
            6,
        );
    const activeEnd = connection
        ? connection.source
        : dragging && selected
          ? selected[dragging === 'a' ? 'b' : 'a']
          : pendingCable?.a.connector_id
            ? pendingCable.a
            : null;
    const activeType = connection
        ? connectionTypes(connection.source.type)[0]
        : dragging && selected
          ? selected.type
          : pendingCable?.type;
    const targetPoint = connection?.point ?? (dragging ? pointer : null);
    const target = targetPoint ? portAt(targetPoint) : null;
    function canConnect(anchor: PortAnchor) {
        const cable = occupied.get(endKey(anchor));
        return (
            !!activeEnd &&
            endKey(activeEnd) !== endKey(anchor) &&
            (!cable || (!!dragging && cable.id === selectedCable)) &&
            !!activeType &&
            connectionTypes(anchor.type).includes(activeType)
        );
    }
    const portTooltip =
        target && activeEnd && endKey(target) !== endKey(activeEnd)
            ? { point: target, text: portInfo(target) }
            : !dragging && !connection
              ? hover
              : null;
    const grouped = localTrunks.length > 1 && !tunnelOpen && !focusedBundle;
    // Opening the tunnel fans the bundles out of the shared line while it fades away.
    const spread = useProgress(!grouped);
    const tunnel = (corridor.left + corridor.right) / 2;
    return (
        <Group ref={root}>
            {spread < 1 &&
                (() => {
                    const ends = [
                        ...new Map(
                            localTrunks
                                .flatMap((trunk) => [trunk.a, trunk.b!])
                                .map((port) => [
                                    `${port.face}:${port.device_id}`,
                                    terminal(port),
                                ]),
                        ).values(),
                    ];
                    const x = (corridor.left + corridor.right) / 2;
                    const ys = ends.map((end) => end.y);
                    const middle = (Math.min(...ys) + Math.max(...ys)) / 2;
                    const label = `${localTrunks.reduce(
                        (sum, trunk) => sum + trunk.segments.length,
                        0,
                    )} cavi`;
                    const labelWidth = textWidth(label, font, true) + font;
                    const open = () => setTunnelOpen(true);
                    return (
                        <Group
                            opacity={1 - spread}
                            listening={grouped}
                            onClick={open}
                            onTap={open}
                        >
                            {/* Every device joins the shared line with a wide bend, like branches of one route. */}
                            {ends.map((end, index) => (
                                <Line
                                    key={index}
                                    points={roundedCablePath(
                                        [
                                            end.device,
                                            { x, y: end.y },
                                            { x, y: middle },
                                        ],
                                        14 * cableUnit,
                                    )}
                                    bezier
                                    stroke={ink}
                                    strokeWidth={2.75 * cableUnit}
                                    lineCap="round"
                                    hitStrokeWidth={12 / scale}
                                />
                            ))}
                            {ends.map((end, index) => (
                                <Circle
                                    key={index}
                                    x={end.device.x}
                                    y={end.y}
                                    radius={3 * cableUnit}
                                    fill={background}
                                    stroke={ink}
                                    strokeWidth={1.5 * cableUnit}
                                />
                            ))}
                            <Group
                                x={x - labelWidth / 2}
                                y={middle - tagHeight / 2}
                            >
                                <Rect
                                    width={labelWidth}
                                    height={tagHeight}
                                    cornerRadius={tagHeight / 2}
                                    fill={ink}
                                />
                                <Text
                                    text={label}
                                    width={labelWidth}
                                    height={tagHeight}
                                    align="center"
                                    verticalAlign="middle"
                                    fontSize={font}
                                    fontStyle="bold"
                                    fill={background}
                                />
                            </Group>
                        </Group>
                    );
                })()}
            {trunks.map((trunk) => {
                const opening = bundleProgress[trunk.key] ?? 0;
                if (opening >= 1 || (!spread && trunk.b)) return null;
                const count = trunk.segments.length;
                const open = () => onBundle(trunk.key);
                const start = terminal(trunk.a, !trunk.b);
                const index = localTrunks.indexOf(trunk);
                let from = exits.get(`${index}:0`) ?? 0;
                let to = exits.get(`${index}:1`) ?? 0;
                // Devices side by side would otherwise draw the way out and back on the same line.
                if (Math.abs(from - to) < 1) {
                    from -= 4;
                    to += 4;
                }
                const finish = trunk.b ? terminal(trunk.b) : start;
                from = start.y + (from - start.y) * spread;
                to = finish.y + (to - finish.y) * spread;
                const lane = tunnel + (lanes[index] - tunnel) * spread;
                const points = trunk.b
                    ? [
                          { x: start.device.x, y: from },
                          { x: lane, y: from },
                          { x: lane, y: to },
                          { x: finish.device.x, y: to },
                      ]
                    : [];
                if (!trunk.b) {
                    // Links leaving the same device fan out to their stacked destinations.
                    const siblings = trunks.filter(
                        (other) =>
                            !other.b &&
                            other.a.face === trunk.a.face &&
                            other.a.device_id === trunk.a.device_id,
                    );
                    const row =
                        siblings.indexOf(trunk) - (siblings.length - 1) / 2;
                    const reach = (start.right ? 6 : -6) * cableUnit;
                    const end = start.y + row * (destinationHeight + 6);
                    points.push(
                        start.device,
                        { x: start.rack.x + reach, y: start.y },
                        { x: start.rack.x + reach, y: end },
                        { x: start.rack.x + 2 * reach, y: end },
                    );
                }
                const target = trunk.remote
                    ? continuation(trunk.remote, trunk.a.face)
                    : null;
                return (
                    <Group
                        key={`${trunk.key}-${trunk.a.face}-${trunk.b?.face}`}
                        opacity={
                            (1 - opening) *
                            (trunk.b ? spread : 1) *
                            (focusedBundle && focusedBundle !== trunk.key
                                ? 0.18
                                : 1)
                        }
                    >
                        <Line
                            points={flat(points)}
                            bezier
                            stroke={trunk.color}
                            strokeWidth={
                                (3 + Math.min(3, Math.log2(count))) * cableUnit
                            }
                            lineCap="round"
                            hitStrokeWidth={12 / scale}
                            dash={
                                trunk.mixed
                                    ? [4 * cableUnit, 3 * cableUnit]
                                    : undefined
                            }
                            onClick={open}
                            onTap={open}
                        />
                        {[points[0], ...(trunk.b ? [points.at(-1)!] : [])].map(
                            (point, index) => (
                                <Circle
                                    key={index}
                                    x={point.x}
                                    y={point.y}
                                    radius={3.5 * cableUnit}
                                    fill={trunk.color}
                                    stroke={background}
                                    strokeWidth={1 / scale}
                                    hitStrokeWidth={8 / scale}
                                    onClick={open}
                                    onTap={open}
                                />
                            ),
                        )}
                        {target ? (
                            <Group>
                                {arrowHead(
                                    points.at(-2)!,
                                    points.at(-1)!,
                                    trunk.color,
                                )}
                                {destination(
                                    points.at(-1)!,
                                    start.right,
                                    count > 1
                                        ? `${count}× ${target.name}`
                                        : target.name,
                                    target.place,
                                    open,
                                )}
                            </Group>
                        ) : (
                            count > 1 &&
                            tag(
                                'tag',
                                tagSpot(points, tagWidth(`${count}×`)),
                                `${count}×`,
                                open,
                                trunk.color,
                                'center',
                            )
                        )}
                    </Group>
                );
            })}
            {openLinks.map(({ cable, part, exit, points }) => {
                const pick = () => onCable(cable);
                return (
                    <Group
                        key={cable.id}
                        opacity={
                            (bundleProgress[focusedBundle ?? ''] ?? 0) *
                            (selectedCable && selectedCable !== cable.id
                                ? 0.25
                                : 1)
                        }
                    >
                        <Line
                            points={flat(points)}
                            bezier
                            stroke={cable.color}
                            strokeWidth={
                                (selectedCable === cable.id ? 4 : 3) * cableUnit
                            }
                            lineCap="round"
                            hitStrokeWidth={8 / scale}
                            onClick={pick}
                            onTap={pick}
                        />
                        {exit && arrowHead(points.at(-2)!, exit, cable.color)}
                        {exit &&
                            destination(
                                exit,
                                exit.right,
                                continuation(part.remote!, part.a.face).name,
                                `${cableCode(cable)} · ${continuation(part.remote!, part.a.face).place}`,
                                pick,
                            )}
                        {!exit &&
                            selectedCable === cable.id &&
                            tag(
                                'code',
                                tagSpot(points, tagWidth(cableCode(cable))),
                                cableCode(cable),
                                pick,
                                cable.color,
                                'center',
                            )}
                    </Group>
                );
            })}
            {overlay}
            {(pendingCable || connection) &&
                (() => {
                    const a =
                        connection?.source ??
                        (pendingCable && byEnd.get(endKey(pendingCable.a)));
                    const b =
                        (connection && target && canConnect(target)
                            ? target
                            : connection?.point) ??
                        (pendingCable && byEnd.get(endKey(pendingCable.b))) ??
                        pointer;
                    if (!a || !b) return null;
                    return (
                        <Line
                            points={[a.x, a.y, b.x, b.y]}
                            stroke={
                                connection &&
                                target &&
                                !canConnect(target) &&
                                endKey(target) !== endKey(connection.source)
                                    ? '#dc2626'
                                    : accent
                            }
                            dash={[5 * cableUnit, 5 * cableUnit]}
                            strokeWidth={2 * cableUnit}
                            lineCap="round"
                            listening={false}
                        />
                    );
                })()}
            {anchors.map((anchor) => {
                const key = endKey(anchor);
                const cable = occupied.get(key);
                const hoveredTarget =
                    !!target &&
                    !!activeEnd &&
                    endKey(target) === key &&
                    endKey(activeEnd) !== key;
                const highlighted =
                    (activeEnd && endKey(activeEnd) === key) ||
                    (selected &&
                        [selected.a, selected.b].some(
                            (e) => endKey(e) === key,
                        )) ||
                    (pendingCable &&
                        [pendingCable.a, pendingCable.b].some(
                            (e) => endKey(e) === key,
                        ));
                const selectable = canConnect(anchor);
                return (
                    <Group key={key}>
                        <Rect
                            x={anchor.x - anchor.width / 2}
                            y={anchor.y - anchor.height / 2}
                            width={anchor.width}
                            height={anchor.height}
                            fill={
                                hoveredTarget
                                    ? selectable
                                        ? '#22c55e66'
                                        : '#ef444466'
                                    : highlighted
                                      ? `${accent}55`
                                      : cable
                                        ? dark
                                            ? '#22c55e66'
                                            : '#16a34a66'
                                        : selectable
                                          ? `${accent}29`
                                          : 'transparent'
                            }
                            stroke={
                                hoveredTarget
                                    ? selectable
                                        ? '#16a34a'
                                        : '#dc2626'
                                    : highlighted
                                      ? accent
                                      : selectable
                                        ? accent
                                        : undefined
                            }
                            strokeWidth={
                                (highlighted || hoveredTarget ? 3 : 1) / scale
                            }
                            draggable={
                                editing &&
                                !cable &&
                                !pendingCable?.a.connector_id
                            }
                            dragDistance={4}
                            dragBoundFunc={function (this: Konva.Node) {
                                // Drag the cable while keeping the port in its physical position.
                                return this.getAbsolutePosition();
                            }}
                            onDragStart={(event) => {
                                event.cancelBubble = true;
                                draggedPort.current = event.target;
                                onConnectionChange(anchor.device_id);
                                setConnection({
                                    source: anchor,
                                    point: anchor,
                                });
                                setHover(null);
                            }}
                            onDragMove={(event) => {
                                event.cancelBubble = true;
                                const point = event.target
                                    .getParent()
                                    ?.getRelativePointerPosition();
                                if (point)
                                    setConnection({ source: anchor, point });
                            }}
                            onDragEnd={(event) => {
                                event.cancelBubble = true;
                                if (draggedPort.current !== event.target)
                                    return;
                                draggedPort.current = null;
                                const point = event.target
                                    .getParent()
                                    ?.getRelativePointerPosition();
                                const target = point && portAt(point);
                                setConnection(null);
                                onConnectionChange(null);
                                if (
                                    target &&
                                    !['touchcancel', 'pointercancel'].includes(
                                        event.evt?.type,
                                    )
                                )
                                    onConnect(
                                        {
                                            device_id: anchor.device_id,
                                            connector_id: anchor.connector_id,
                                        },
                                        {
                                            device_id: target.device_id,
                                            connector_id: target.connector_id,
                                        },
                                    );
                            }}
                            onMouseDown={(event) => {
                                event.cancelBubble = true;
                            }}
                            onTouchStart={(event) => {
                                event.cancelBubble = true;
                            }}
                            onClick={() =>
                                onPort({
                                    device_id: anchor.device_id,
                                    connector_id: anchor.connector_id,
                                })
                            }
                            onTap={() =>
                                onPort({
                                    device_id: anchor.device_id,
                                    connector_id: anchor.connector_id,
                                })
                            }
                            onMouseEnter={() => {
                                setHover({
                                    point: anchor,
                                    text: portInfo(anchor),
                                });
                                setPointer(anchor);
                            }}
                            onMouseLeave={() => setHover(null)}
                        />
                    </Group>
                );
            })}
            {selected &&
                (['a', 'b'] as const).map((side) => {
                    const anchor = byEnd.get(endKey(selected[side]));
                    if (!anchor) return null;
                    return (
                        <Rect
                            key={`${selected.id}-${side}`}
                            x={anchor.x}
                            y={anchor.y}
                            width={anchor.width}
                            height={anchor.height}
                            offsetX={anchor.width / 2}
                            offsetY={anchor.height / 2}
                            stroke={selected.color}
                            fill="transparent"
                            strokeWidth={2 / scale}
                            draggable={editing && !pendingCable}
                            hitStrokeWidth={12 / scale}
                            onMouseDown={(e) => {
                                e.cancelBubble = true;
                            }}
                            onTouchStart={(e) => {
                                e.cancelBubble = true;
                            }}
                            onDragStart={(e) => {
                                e.cancelBubble = true;
                                draggedPort.current = e.target;
                                setDragging(side);
                                setPointer(anchor);
                                onConnectionChange(
                                    selected[side === 'a' ? 'b' : 'a']
                                        .device_id,
                                );
                                setHover(null);
                            }}
                            onDragMove={(e) => {
                                e.cancelBubble = true;
                                setPointer(e.target.position());
                            }}
                            onDragEnd={(event) => {
                                event.cancelBubble = true;
                                const position = event.target.position();
                                const nearest = portAt(position);
                                event.target.position(anchor);
                                setDragging(null);
                                setPointer(null);
                                onConnectionChange(null);
                                if (draggedPort.current !== event.target)
                                    return;
                                draggedPort.current = null;
                                if (
                                    nearest &&
                                    !['touchcancel', 'pointercancel'].includes(
                                        event.evt?.type,
                                    )
                                )
                                    onReconnect(selected.id, side, {
                                        device_id: nearest.device_id,
                                        connector_id: nearest.connector_id,
                                    });
                            }}
                        />
                    );
                })}
            {portTooltip &&
                (() => {
                    const { title, target, detail, color } = portTooltip.text;
                    const unit = 1 / scale;
                    const size = 12 * unit;
                    const lines = [
                        { text: title, bold: true, fill: strong },
                        target
                            ? { text: `→ ${target}`, fill: strong }
                            : { text: 'Nessun cavo documentato', fill: ink },
                        ...(detail ? [{ text: detail, fill: ink, color }] : []),
                    ];
                    const indent = (line: (typeof lines)[number]) =>
                        'color' in line && line.color ? 14 * unit : 0;
                    const width =
                        Math.max(
                            ...lines.map(
                                (line) =>
                                    textWidth(line.text, size, 'bold' in line) +
                                    indent(line),
                            ),
                        ) +
                        20 * unit;
                    return (
                        <Group
                            x={portTooltip.point.x + 12 * unit}
                            y={portTooltip.point.y + 16 * unit}
                            listening={false}
                        >
                            <Rect
                                width={width}
                                height={(lines.length * 18 + 14) * unit}
                                fill={background}
                                stroke={dark ? '#334155' : '#cbd5e1'}
                                strokeWidth={unit}
                                cornerRadius={8 * unit}
                                shadowColor="#000000"
                                shadowOpacity={0.25}
                                shadowBlur={12}
                                shadowOffsetY={4}
                            />
                            {lines.map((line, index) => (
                                <Group
                                    key={index}
                                    x={10 * unit}
                                    y={(10 + index * 18) * unit}
                                >
                                    {'color' in line && line.color && (
                                        <Circle
                                            x={4 * unit}
                                            y={size / 2}
                                            radius={4 * unit}
                                            fill={line.color}
                                        />
                                    )}
                                    <Text
                                        x={indent(line)}
                                        text={line.text}
                                        fontSize={size}
                                        fontStyle={
                                            'bold' in line ? 'bold' : 'normal'
                                        }
                                        fill={line.fill}
                                    />
                                </Group>
                            ))}
                        </Group>
                    );
                })()}
        </Group>
    );
}
