import type Konva from 'konva';
import { useEffect, useRef, useState } from 'react';
import { Circle, Group, Label, Line, Rect, Tag, Text } from 'react-konva';
import {
    RACK_WIDTH,
    RACK_UNIT_HEIGHT,
    type Face,
    type Point,
} from '@/lib/hardware-editor/document';
import { itemBounds } from '@/lib/rack-layout';
import { cableLead, roundedCablePath } from '@/lib/rack-cable-routing';
import {
    cableBundles,
    cableCode,
    cableCurve,
    cableTypes,
    connectionTypes,
    devicePorts,
    endKey,
    endLabel,
    portAnchors,
    FACE_WIDTH,
    RACK_LEFT,
    RACK_TOP,
    type Cable,
    type CableEnd,
    type PortAnchor,
} from '@/lib/rack-cabling';
import type { RackDevice, RackItem } from '@/types/rack';

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
    onBundle: (key: string | null) => void;
    onCable: (cable: Cable) => void;
    onReconnect: (id: number, side: 'a' | 'b', end: CableEnd) => void;
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
    onBundle,
    onCable,
    onReconnect,
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
    const [hover, setHover] = useState<{ point: Point; text: string } | null>(
        null,
    );
    const [pointer, setPointer] = useState<Point | null>(null);
    const [dragging, setDragging] = useState<'a' | 'b' | null>(null);
    const [connection, setConnection] = useState<{
        source: PortAnchor;
        point: Point;
    } | null>(null);
    const draggedPort = useRef<Konva.Node | null>(null);
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
        };
        window.addEventListener('keydown', cancel);
        return () => window.removeEventListener('keydown', cancel);
    }, []);
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

    function edge(anchor: PortAnchor, side: 'left' | 'right' = 'right'): Point {
        const item = items.find((i) => i.device_id === anchor.device_id)!;
        const left =
            anchor.face === 'rear' ? 100 - item.x - item.width : item.x;
        return {
            x:
                faces.indexOf(anchor.face) * FACE_WIDTH +
                RACK_LEFT +
                (RACK_WIDTH * (left + (side === 'right' ? item.width : 0))) /
                    100 +
                (side === 'right' ? 8 : -8),
            y:
                RACK_TOP +
                (height - (itemBounds(item, items).bottom + item.height / 2)) *
                    RACK_UNIT_HEIGHT,
        };
    }
    function continuation(end: CableEnd, face: Face) {
        const device = devices.find((d) => d.id === end.device_id);
        const port = devicePorts(device).find((p) => p.id === end.connector_id);
        if (items.some((i) => i.device_id === end.device_id))
            return `${port?.hidden ? 'Porta nascosta' : port && port.face !== face ? (port.face === 'front' ? 'Fronte' : 'Retro') : 'Porta fuori vista'} · ${device?.name ?? ''}`;
        return `${device?.rack_item?.rack.name ?? 'Fuori rack'} · ${device?.name ?? ''}`;
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
    function tag(
        point: Point,
        text: string,
        activate: () => void,
        key: string,
        color = ink,
        truncate = false,
    ) {
        return (
            <Label
                key={key}
                x={point.x}
                y={point.y}
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
                    width={truncate ? 100 : undefined}
                    ellipsis={truncate}
                    wrap="none"
                    fontSize={Math.min(18, 11 / scale)}
                    fill={color}
                />
            </Label>
        );
    }
    function portInfo(anchor: PortAnchor) {
        const cable = occupied.get(endKey(anchor));
        return cable
            ? `${endLabel(anchor, devices)}\n${cableCode(cable)} → ${endLabel(endKey(cable.a) === endKey(anchor) ? cable.b : cable.a, devices)}\n${cableTypes[cable.type]} · ${cable.color}${cable.purpose ? ` · ${cable.purpose}` : ''}`
            : `${endLabel(anchor, devices)}\nNessun cavo documentato`;
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
    function individualPath(
        a: PortAnchor,
        b: PortAnchor | Point,
        lane: number,
        index: number,
        count: number,
    ) {
        const gap = 6 * cableUnit;
        const start = cableLead(a, lane, anchors, count - 1 - index, gap);
        const end =
            'connector_id' in b
                ? cableLead(
                      b,
                      lane,
                      anchors,
                      b.x > lane ? index : count - 1 - index,
                      gap,
                  )
                : [b, { x: lane, y: b.y }];
        if (!start || !end) return null;
        return roundedCablePath([...start, ...end.toReversed()], gap / 3);
    }
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
    return (
        <Group>
            {groups.map(([key, members], groupIndex) => {
                const opening = bundleProgress[key] ?? 0;
                return (
                    <Group
                        key={key}
                        opacity={
                            focusedBundle && focusedBundle !== key
                                ? 0.18 + 0.82 * opening
                                : 1
                        }
                    >
                        {routes.map((route) => {
                            const segments = members
                                .map((cable) => ({
                                    cable,
                                    segment: segment(cable, route),
                                }))
                                .filter((entry) => entry.segment !== null);
                            if (!segments.length) return null;
                            const sample = segments[0].segment!;
                            const face = sample.a.face;
                            const leftSide = route === 'rear';
                            const lane =
                                (route === 'cross'
                                    ? FACE_WIDTH
                                    : faces.indexOf(face) * FACE_WIDTH +
                                      RACK_LEFT +
                                      (leftSide ? -30 : RACK_WIDTH + 30)) +
                                (leftSide ? -1 : 1) * (groupIndex % 6) * 7;
                            const summaryA = edge(
                                sample.a,
                                leftSide ? 'left' : 'right',
                            );
                            const summaryB = sample.b
                                ? edge(
                                      sample.b,
                                      route === 'cross' || leftSide
                                          ? 'left'
                                          : 'right',
                                  )
                                : { x: lane, y: summaryA.y + 36 };
                            const colors = new Set(
                                segments.map(({ cable }) => cable.color),
                            );
                            const color =
                                colors.size === 1
                                    ? segments[0].cable.color
                                    : ink;
                            const label = sample.remote
                                ? `${segments.length}× ↪ ${continuation(sample.remote, face)}`
                                : `${segments.length}×`;
                            const summary = opening < 1 && (
                                <Group key={route} opacity={1 - opening}>
                                    <Line
                                        points={cableCurve(
                                            summaryA,
                                            summaryB,
                                            lane,
                                        )}
                                        bezier
                                        stroke={color}
                                        strokeWidth={3.5 * cableUnit}
                                        lineCap="round"
                                        hitStrokeWidth={16 / scale}
                                        dash={
                                            colors.size > 1
                                                ? [4 * cableUnit, 3 * cableUnit]
                                                : undefined
                                        }
                                        onClick={() => onBundle(key)}
                                        onTap={() => onBundle(key)}
                                    />
                                    {[summaryA, summaryB].map(
                                        (point, index) => (
                                            <Circle
                                                key={index}
                                                x={point.x}
                                                y={point.y}
                                                radius={4.5 * cableUnit}
                                                fill={color}
                                                stroke={background}
                                                strokeWidth={1 / scale}
                                                hitStrokeWidth={8 / scale}
                                                onClick={() => onBundle(key)}
                                                onTap={() => onBundle(key)}
                                            />
                                        ),
                                    )}
                                    {tag(
                                        {
                                            x: sample.remote
                                                ? lane + 10 * cableUnit
                                                : lane - 10,
                                            y: (summaryA.y + summaryB.y) / 2,
                                        },
                                        label,
                                        () => onBundle(key),
                                        `tag-${face}`,
                                        color,
                                        !!sample.remote,
                                    )}
                                </Group>
                            );
                            if (!opening) return summary;
                            const sorted = segments.sort(
                                (a, b) =>
                                    a.segment!.a.y - b.segment!.a.y ||
                                    a.segment!.a.x - b.segment!.a.x ||
                                    a.cable.id - b.cable.id,
                            );
                            return (
                                <Group key={route}>
                                    {summary}
                                    {sorted.map(
                                        ({ cable, segment: part }, index) => {
                                            const a = part!.a;
                                            const b = part!.b ?? {
                                                x: lane,
                                                y: edge(a).y + 26 + index * 22,
                                            };
                                            const cableLane =
                                                lane +
                                                (index -
                                                    (sorted.length - 1) / 2) *
                                                    Math.min(
                                                        6 * cableUnit,
                                                        50 / sorted.length,
                                                    );
                                            const points = individualPath(
                                                a,
                                                b,
                                                cableLane,
                                                index,
                                                sorted.length,
                                            );
                                            // shortcut: overlapping ports can block every local exit; show endpoint references instead of crossing ports.
                                            if (!points)
                                                return (
                                                    <Group
                                                        key={cable.id}
                                                        opacity={opening}
                                                    >
                                                        {tag(
                                                            a,
                                                            `${cableCode(cable)} ↪ ${endLabel(endKey(a) === endKey(cable.a) ? cable.b : cable.a, devices)}`,
                                                            () =>
                                                                onCable(cable),
                                                            'a',
                                                            cable.color,
                                                            true,
                                                        )}
                                                        {part!.b &&
                                                            tag(
                                                                part!.b,
                                                                `${cableCode(cable)} ↪ ${endLabel(a, devices)}`,
                                                                () =>
                                                                    onCable(
                                                                        cable,
                                                                    ),
                                                                'b',
                                                                cable.color,
                                                                true,
                                                            )}
                                                    </Group>
                                                );
                                            const xs = points.filter(
                                                (_, i) => i % 2 === 0,
                                            );
                                            const ys = points.filter(
                                                (_, i) => i % 2 === 1,
                                            );
                                            const padding = 8 / scale;
                                            const left =
                                                Math.min(...xs) - padding;
                                            const right =
                                                Math.max(...xs) + padding;
                                            return (
                                                <Group
                                                    key={cable.id}
                                                    opacity={
                                                        opening *
                                                        (selectedCable &&
                                                        selectedCable !==
                                                            cable.id
                                                            ? 0.25
                                                            : 1)
                                                    }
                                                >
                                                    <Group
                                                        clipX={
                                                            cableLane +
                                                            (left - cableLane) *
                                                                opening
                                                        }
                                                        clipY={
                                                            Math.min(...ys) -
                                                            padding
                                                        }
                                                        clipWidth={
                                                            (right - left) *
                                                            opening
                                                        }
                                                        clipHeight={
                                                            Math.max(...ys) -
                                                            Math.min(...ys) +
                                                            2 * padding
                                                        }
                                                    >
                                                        <Line
                                                            points={points}
                                                            bezier
                                                            stroke={cable.color}
                                                            strokeWidth={
                                                                (selectedCable ===
                                                                cable.id
                                                                    ? 4
                                                                    : 3) *
                                                                cableUnit
                                                            }
                                                            lineCap="round"
                                                            hitStrokeWidth={
                                                                8 / scale
                                                            }
                                                            onClick={() =>
                                                                onCable(cable)
                                                            }
                                                            onTap={() =>
                                                                onCable(cable)
                                                            }
                                                        />
                                                    </Group>
                                                    {part!.remote &&
                                                        tag(
                                                            {
                                                                x: b.x - 10,
                                                                y: b.y,
                                                            },
                                                            `${cableCode(cable)} ↪ ${continuation(part!.remote, face)}`,
                                                            () =>
                                                                onCable(cable),
                                                            `continuation-${cable.id}`,
                                                            cable.color,
                                                            true,
                                                        )}
                                                    {!part!.remote &&
                                                        selectedCable ===
                                                            cable.id &&
                                                        tag(
                                                            {
                                                                x:
                                                                    cableLane -
                                                                    10,
                                                                y:
                                                                    (a.y +
                                                                        b.y) /
                                                                    2,
                                                            },
                                                            cableCode(cable),
                                                            () =>
                                                                onCable(cable),
                                                            `code-${cable.id}`,
                                                            cable.color,
                                                        )}
                                                </Group>
                                            );
                                        },
                                    )}
                                </Group>
                            );
                        })}
                    </Group>
                );
            })}
            {(pendingCable || connection) &&
                (() => {
                    const a =
                        connection?.source ??
                        (pendingCable && byEnd.get(endKey(pendingCable.a)));
                    const b =
                        connection?.point ??
                        (pendingCable && byEnd.get(endKey(pendingCable.b))) ??
                        pointer;
                    if (!a || !b) return null;
                    const lane =
                        faces.length === 2 &&
                        a.x < FACE_WIDTH !== b.x < FACE_WIDTH
                            ? FACE_WIDTH
                            : faces.indexOf(a.face) * FACE_WIDTH +
                              RACK_LEFT +
                              (a.face === 'rear' ? -30 : RACK_WIDTH + 30);
                    const points = individualPath(a, b, lane, 0, 1);
                    if (!points) return null;
                    return (
                        <Line
                            points={points}
                            bezier
                            stroke={accent}
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
                const highlighted =
                    (connection && endKey(connection.source) === key) ||
                    (selected &&
                        [selected.a, selected.b].some(
                            (e) => endKey(e) === key,
                        )) ||
                    (pendingCable &&
                        [pendingCable.a, pendingCable.b].some(
                            (e) => endKey(e) === key,
                        ));
                const selectable =
                    !!activeEnd &&
                    endKey(activeEnd) !== key &&
                    (!cable || (!!dragging && cable.id === selectedCable)) &&
                    !!activeType &&
                    connectionTypes(anchor.type).includes(activeType);
                return (
                    <Group key={key}>
                        <Rect
                            x={anchor.x - anchor.width / 2}
                            y={anchor.y - anchor.height / 2}
                            width={anchor.width}
                            height={anchor.height}
                            fill={
                                cable
                                    ? dark
                                        ? '#22c55e66'
                                        : '#16a34a66'
                                    : selectable
                                      ? accent
                                      : 'transparent'
                            }
                            opacity={!cable && selectable ? 0.16 : 1}
                            stroke={
                                highlighted
                                    ? accent
                                    : selectable
                                      ? accent
                                      : undefined
                            }
                            strokeWidth={2 / scale}
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
                                if (target && event.evt?.type !== 'touchcancel')
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
                                setDragging(side);
                                setHover(null);
                            }}
                            onDragMove={(e) => {
                                e.cancelBubble = true;
                            }}
                            onDragEnd={(event) => {
                                event.cancelBubble = true;
                                const position = event.target.position();
                                const nearest = portAt(position);
                                event.target.position(anchor);
                                setDragging(null);
                                if (nearest)
                                    onReconnect(selected.id, side, {
                                        device_id: nearest.device_id,
                                        connector_id: nearest.connector_id,
                                    });
                            }}
                        />
                    );
                })}
            {hover && !dragging && !connection && (
                <Label
                    x={hover.point.x + 12}
                    y={hover.point.y + 16}
                    listening={false}
                >
                    <Tag fill={background} stroke={ink} cornerRadius={5} />
                    <Text
                        text={hover.text}
                        fill={ink}
                        padding={8}
                        width={300 / scale}
                        fontSize={12 / scale}
                        lineHeight={1.4}
                    />
                </Label>
            )}
        </Group>
    );
}
