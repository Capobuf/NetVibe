import type Konva from 'konva';
import {
    useEffect,
    useImperativeHandle,
    useMemo,
    useRef,
    useState,
    type Ref,
} from 'react';
import { Group, Layer, Line, Rect, Stage, Text } from 'react-konva';
import { toast } from 'sonner';
import { TopologyRacks } from '@/components/topology/topology-racks';
import {
    topologyRackLayout,
    type TopologyRack,
} from '@/lib/topology-rack-layout';
import { TopologyDevice } from '@/components/topology/topology-device';
import type { PortAnchor } from '@/components/devices/panel-viewer';
import { PanelViewer } from '@/components/devices/panel-viewer';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTitle,
} from '@/components/ui/popover';
import { topologyRoutes } from '@/lib/topology-routing';
import { roundedCablePath } from '@/lib/rack-cable-routing';
import { topologyPortLabel, topologyPortMessage } from '@/types/topology';
import { useAppearance } from '@/hooks/use-appearance';
import {
    NODE_HEIGHT,
    NODE_WIDTH,
    topologyLayout,
    type Position,
    type TopologyOrientation,
} from '@/lib/topology-layout';
import type {
    TopologyEdge,
    TopologyNode,
    TopologySelection,
    TopologyModel,
} from '@/types/topology';

export type TopologyCanvasApi = {
    fit: () => void;
    zoom: (factor: number) => void;
    arrange: () => void;
    focus: (id: string) => void;
};

export function TopologyCanvas({
    nodes,
    models,
    orientation,
    racks,
    edges,
    selection,
    onSelect,
    api,
    onZoom,
}: {
    nodes: TopologyNode[];
    models: Record<number, TopologyModel>;
    orientation: TopologyOrientation;
    racks?: TopologyRack[];
    edges: TopologyEdge[];
    selection: TopologySelection;
    onSelect: (selection: TopologySelection, anchor?: PortAnchor) => void;
    api: Ref<TopologyCanvasApi>;
    onZoom: (zoom: number) => void;
}) {
    const container = useRef<HTMLDivElement>(null);
    const stage = useRef<Konva.Stage>(null);
    const { resolvedAppearance } = useAppearance();
    const dark = resolvedAppearance === 'dark';
    const [size, setSize] = useState({ width: 800, height: 560 });
    const rackLayout = useMemo(
        () => (racks ? topologyRackLayout(nodes, racks) : null),
        [nodes, racks],
    );
    const nodeSize = (id: string) =>
        rackLayout?.sizes[id] ?? { width: NODE_WIDTH, height: NODE_HEIGHT };
    const [hovered, setHovered] = useState<string | null>(null);
    const initial = useMemo(
        () =>
            rackLayout?.positions ?? topologyLayout(nodes, edges, orientation),
        [nodes, edges, orientation, rackLayout],
    );
    const [positions, setPositions] = useState(initial);
    const [view, setView] = useState({ x: 0, y: 0, scale: 1 });
    const [images, setImages] = useState<Record<string, HTMLImageElement>>({});
    const assetKey = JSON.stringify([
        ...new Set(
            Object.values(models).flatMap((model) =>
                Object.values(model.assetUrls),
            ),
        ),
    ]);
    useEffect(() => {
        let cancelled = false;
        const urls: string[] = JSON.parse(assetKey);
        Promise.allSettled(
            urls.map(async (url) => {
                const image = new Image();
                image.src = url;
                await image.decode();
                return [url, image] as const;
            }),
        ).then((results) => {
            if (cancelled) return;
            setImages(
                Object.fromEntries(
                    results.flatMap((result) =>
                        result.status === 'fulfilled' ? [result.value] : [],
                    ),
                ),
            );
            if (results.some((result) => result.status === 'rejected'))
                toast.error(
                    'Alcune immagini non sono disponibili. Ricarica la pagina per riprovare.',
                );
        });
        return () => {
            cancelled = true;
        };
    }, [assetKey]);
    const points = useMemo(
        () =>
            Object.fromEntries(
                nodes.map((node) => [
                    node.id,
                    rackLayout
                        ? initial[node.id]
                        : (positions[node.id] ?? initial[node.id]),
                ]),
            ),
        [nodes, positions, initial, rackLayout],
    );
    const routes = useMemo(
        () => topologyRoutes(points, edges, orientation, rackLayout?.sizes),
        [points, edges, orientation, rackLayout],
    );
    const selectedEdge =
        selection?.kind === 'edge'
            ? edges.find((edge) => edge.id === selection.id)
            : undefined;
    function selectAtPointer(next: TopologySelection, target: Konva.Node) {
        const element = container.current;
        const pointer = stage.current?.getPointerPosition();
        if (!element || !pointer) return;
        const local = target
            .getAbsoluteTransform()
            .copy()
            .invert()
            .point(pointer);
        element.focus({ preventScroll: true });
        onSelect(next, {
            contextElement: element,
            getBoundingClientRect: () => {
                const bounds = element.getBoundingClientRect();
                const point = target.getAbsoluteTransform().point(local);
                return new DOMRect(
                    bounds.left + point.x,
                    bounds.top + point.y,
                    0,
                    0,
                );
            },
        });
    }
    const colors = dark
        ? {
              surface: '#171717',
              text: '#fafafa',
              muted: '#a3a3a3',
              border: '#525252',
              accent: '#60a5fa',
              danger: '#f87171',
          }
        : {
              surface: '#ffffff',
              text: '#171717',
              muted: '#737373',
              border: '#d4d4d4',
              accent: '#2563eb',
              danger: '#b91c1c',
          };
    function applyView(next: typeof view) {
        setView(next);
        onZoom(next.scale);
    }
    function fit(layout = points, bounds = size) {
        const nodeBounds = Object.entries(layout).flatMap(([id, p]) => [
            p,
            { x: p.x + nodeSize(id).width, y: p.y + nodeSize(id).height },
        ]);
        const values = [
            ...nodeBounds,
            ...(rackLayout?.frames.flatMap((frame) => [
                { x: frame.x - 16, y: frame.y - 48 },
                { x: frame.x + frame.width, y: frame.y + frame.height + 14 },
            ]) ?? []),
            ...(rackLayout?.hasOutside
                ? [{ x: rackLayout.outsideX, y: 32 }]
                : []),
            ...Object.values(
                topologyRoutes(layout, edges, orientation, rackLayout?.sizes),
            ).flat(),
        ];
        if (!values.length) return;
        const left = Math.min(...values.map((p) => p.x));
        const top = Math.min(...values.map((p) => p.y));
        const width = Math.max(...values.map((p) => p.x)) - left;
        const height = Math.max(...values.map((p) => p.y)) - top;
        const scale = Math.max(
            0.08,
            Math.min(
                1.25,
                (bounds.width - 80) / width,
                (bounds.height - 80) / height,
            ),
        );
        applyView({
            x: (bounds.width - width * scale) / 2 - left * scale,
            y: (bounds.height - height * scale) / 2 - top * scale,
            scale,
        });
    }
    function zoom(
        factor: number,
        anchor = { x: size.width / 2, y: size.height / 2 },
    ) {
        const scale = Math.max(0.08, Math.min(2.5, view.scale * factor));
        applyView({
            x: anchor.x - ((anchor.x - view.x) * scale) / view.scale,
            y: anchor.y - ((anchor.y - view.y) * scale) / view.scale,
            scale,
        });
    }
    useEffect(() => {
        if (!container.current) return;
        let previous: typeof size | null = null;
        const observer = new ResizeObserver(([entry]) => {
            const bounds = {
                width: entry.contentRect.width,
                height: entry.contentRect.height,
            };
            setSize(bounds);
            if (!previous) {
                fit(points, bounds);
            } else {
                const dx = (bounds.width - previous.width) / 2;
                const dy = (bounds.height - previous.height) / 2;
                setView((current) => ({
                    ...current,
                    x: current.x + dx,
                    y: current.y + dy,
                }));
            }
            previous = bounds;
        });
        observer.observe(container.current);
        return () => observer.disconnect();
        // Initial fit belongs to this canvas mount, not every node movement.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    useImperativeHandle(api, () => ({
        fit: () => fit(),
        zoom,
        arrange: () => {
            const layout = initial;
            setPositions(layout);
            fit(layout);
        },
        focus: (id) => {
            const position = points[id];
            if (position)
                applyView({
                    x:
                        size.width / 2 -
                        (position.x + nodeSize(id).width / 2) * view.scale,
                    y:
                        size.height / 2 -
                        (position.y + nodeSize(id).height / 2) * view.scale,
                    scale: view.scale,
                });
        },
    }));
    const related = new Set<string>();
    if (selection?.kind === 'node') related.add(selection.id);
    for (const edge of edges) {
        if (
            (selection?.kind === 'edge' && edge.id === selection.id) ||
            (selection?.kind === 'node' &&
                (edge.source === selection.id || edge.target === selection.id))
        ) {
            related.add(edge.source);
            related.add(edge.target);
        }
    }
    return (
        <div
            ref={container}
            tabIndex={0}
            aria-label="Diagramma di rete. Seleziona dispositivi e collegamenti anche con Esplora la topologia da tastiera. Frecce per spostare la vista o il nodo selezionato, più e meno per lo zoom, zero per adattare."
            className="relative h-full min-h-96 w-full touch-none outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
            onKeyDown={(event) => {
                if (event.target !== event.currentTarget) return;
                if (event.key === 'Escape') {
                    onSelect(null);
                    return;
                }
                if (event.key === '+' || event.key === '=') {
                    event.preventDefault();
                    zoom(1.2);
                }
                if (event.key === '-') {
                    event.preventDefault();
                    zoom(1 / 1.2);
                }
                if (event.key === '0') {
                    event.preventDefault();
                    fit();
                }
                const delta: Record<string, Position> = {
                    ArrowLeft: { x: -24, y: 0 },
                    ArrowRight: { x: 24, y: 0 },
                    ArrowUp: { x: 0, y: -24 },
                    ArrowDown: { x: 0, y: 24 },
                };
                const direction = delta[event.key];
                if (!direction) return;
                event.preventDefault();
                if (
                    !rackLayout &&
                    selection?.kind === 'node' &&
                    points[selection.id]
                ) {
                    const point = points[selection.id];
                    setPositions({
                        ...positions,
                        [selection.id]: {
                            x: point.x + direction.x,
                            y: point.y + direction.y,
                        },
                    });
                } else
                    applyView({
                        ...view,
                        x: view.x + direction.x,
                        y: view.y + direction.y,
                    });
            }}
        >
            <Stage
                ref={stage}
                width={size.width}
                height={size.height}
                x={view.x}
                y={view.y}
                scaleX={view.scale}
                scaleY={view.scale}
                draggable
                onWheel={(event) => {
                    event.evt.preventDefault();
                    zoom(
                        event.evt.deltaY > 0 ? 1 / 1.12 : 1.12,
                        stage.current?.getPointerPosition() ?? undefined,
                    );
                }}
                onDragEnd={(event) => {
                    if (event.target === stage.current)
                        applyView({
                            ...view,
                            x: event.target.x(),
                            y: event.target.y(),
                        });
                }}
                onDragMove={(event) => {
                    if (event.target === stage.current)
                        setView((current) => ({
                            ...current,
                            x: event.target.x(),
                            y: event.target.y(),
                        }));
                }}
                onClick={(event) => {
                    container.current?.focus({ preventScroll: true });
                    if (event.target === event.target.getStage())
                        onSelect(null);
                }}
                onTap={(event) => {
                    if (event.target === event.target.getStage())
                        onSelect(null);
                }}
            >
                <Layer>
                    {rackLayout && (
                        <TopologyRacks layout={rackLayout} dark={dark} />
                    )}
                    {edges
                        .toSorted(
                            (a, b) =>
                                Number(a.id === selectedEdge?.id) -
                                Number(b.id === selectedEdge?.id),
                        )
                        .map((edge) => {
                            const path = routes[edge.id];
                            if (!path) return null;
                            const selected =
                                selection?.kind === 'edge' &&
                                selection.id === edge.id;
                            const highlighted =
                                selected ||
                                (selection?.kind === 'node' &&
                                    (edge.source === selection.id ||
                                        edge.target === selection.id));
                            return (
                                <Line
                                    key={edge.id}
                                    points={roundedCablePath(path, 6)}
                                    bezier
                                    stroke={
                                        selected
                                            ? colors.accent
                                            : edge.issues.length
                                              ? colors.danger
                                              : edge.kind === 'documented' ||
                                                  highlighted
                                                ? colors.accent
                                                : colors.muted
                                    }
                                    strokeWidth={
                                        (selected
                                            ? 4
                                            : highlighted
                                              ? 2.5
                                              : 1.5) / view.scale
                                    }
                                    hitStrokeWidth={18 / view.scale}
                                    dash={
                                        edge.kind === 'documented'
                                            ? []
                                            : edge.kind === 'discovered'
                                              ? [10, 6]
                                              : [2, 6]
                                    }
                                    opacity={
                                        (selected
                                            ? 1
                                            : edge.stale
                                              ? 0.45
                                              : 0.85) *
                                        (selection && !highlighted ? 0.25 : 1)
                                    }
                                    onClick={(event) => {
                                        event.cancelBubble = true;
                                        selectAtPointer(
                                            { kind: 'edge', id: edge.id },
                                            event.currentTarget,
                                        );
                                    }}
                                    onTap={(event) => {
                                        event.cancelBubble = true;
                                        selectAtPointer(
                                            { kind: 'edge', id: edge.id },
                                            event.currentTarget,
                                        );
                                    }}
                                    onMouseEnter={() => {
                                        if (container.current)
                                            container.current.style.cursor =
                                                'pointer';
                                    }}
                                    onMouseLeave={() => {
                                        if (container.current)
                                            container.current.style.cursor =
                                                'default';
                                    }}
                                />
                            );
                        })}
                    {nodes.map((node) => {
                        const point = points[node.id];
                        const physicalSize = rackLayout?.sizes[node.id];
                        const endpoint =
                            selectedEdge?.source === node.id
                                ? selectedEdge.a
                                : selectedEdge?.target === node.id
                                  ? selectedEdge.b
                                  : null;
                        const connected =
                            selectedEdge &&
                            (selectedEdge.source === node.id ||
                                selectedEdge.target === node.id);
                        const selected =
                            selection?.kind === 'node' &&
                            selection.id === node.id;
                        return (
                            <Group
                                key={node.id}
                                x={point.x}
                                y={point.y}
                                opacity={
                                    selection && !related.has(node.id) ? 0.4 : 1
                                }
                                draggable={!rackLayout}
                                onDragStart={(event) => {
                                    event.cancelBubble = true;
                                    selectAtPointer(
                                        { kind: 'node', id: node.id },
                                        event.currentTarget,
                                    );
                                }}
                                onDragMove={(event) => {
                                    event.cancelBubble = true;
                                    const position = event.target.position();
                                    setPositions((current) => ({
                                        ...current,
                                        [node.id]: position,
                                    }));
                                }}
                                onDragEnd={(event) => {
                                    event.cancelBubble = true;
                                }}
                                onClick={(event) => {
                                    event.cancelBubble = true;
                                    selectAtPointer(
                                        { kind: 'node', id: node.id },
                                        event.currentTarget,
                                    );
                                }}
                                onTap={(event) => {
                                    event.cancelBubble = true;
                                    selectAtPointer(
                                        { kind: 'node', id: node.id },
                                        event.currentTarget,
                                    );
                                }}
                                onMouseEnter={() => {
                                    if (container.current)
                                        container.current.style.cursor =
                                            rackLayout ? 'pointer' : 'grab';
                                    setHovered(node.id);
                                }}
                                onMouseLeave={() => {
                                    setHovered(null);
                                    if (container.current)
                                        container.current.style.cursor =
                                            'default';
                                }}
                            >
                                <Rect
                                    width={nodeSize(node.id).width}
                                    height={nodeSize(node.id).height}
                                    cornerRadius={physicalSize ? 2 : 16}
                                    fill={
                                        selected
                                            ? colors.surface
                                            : 'transparent'
                                    }
                                    stroke={
                                        selected || connected
                                            ? colors.accent
                                            : undefined
                                    }
                                    strokeWidth={1.5}
                                />
                                <TopologyDevice
                                    model={
                                        node.model_id
                                            ? models[node.model_id]
                                            : undefined
                                    }
                                    images={images}
                                    dark={dark}
                                    zoom={view.scale}
                                    physicalSize={physicalSize}
                                    known={node.device_id !== null}
                                    selectedPortId={
                                        endpoint?.connector_id ?? undefined
                                    }
                                />
                                {physicalSize ? (
                                    (hovered === node.id ||
                                        selected ||
                                        connected) && (
                                        <Group listening={false}>
                                            <Rect
                                                y={-26}
                                                width={physicalSize.width}
                                                height={24}
                                                fill={colors.surface}
                                                cornerRadius={3}
                                            />
                                            <Text
                                                x={6}
                                                y={-22}
                                                width={physicalSize.width - 12}
                                                height={20}
                                                text={node.label}
                                                fontSize={16}
                                                fontFamily="Instrument Sans, sans-serif"
                                                fill={colors.text}
                                                ellipsis
                                                wrap="none"
                                            />
                                        </Group>
                                    )
                                ) : (
                                    <>
                                        <Text
                                            x={8}
                                            y={86}
                                            width={NODE_WIDTH - 16}
                                            height={22}
                                            align="center"
                                            text={node.label}
                                            fontSize={14}
                                            fontFamily="Instrument Sans, sans-serif"
                                            fontStyle="bold"
                                            fill={colors.text}
                                            wrap="none"
                                            ellipsis
                                            listening={false}
                                        />
                                        <Text
                                            x={8}
                                            y={111}
                                            width={NODE_WIDTH - 16}
                                            align="center"
                                            height={20}
                                            text={
                                                connected
                                                    ? (topologyPortLabel(
                                                          selectedEdge,
                                                          node,
                                                      ) ??
                                                      topologyPortMessage(
                                                          selectedEdge,
                                                          node,
                                                      ))
                                                    : node.device_id
                                                      ? (node.rack?.name ??
                                                        node.model ??
                                                        'Dispositivo')
                                                      : 'Non associato'
                                            }
                                            fontSize={12}
                                            fontFamily="Instrument Sans, sans-serif"
                                            fill={colors.muted}
                                            wrap="none"
                                            ellipsis
                                            listening={false}
                                        />
                                    </>
                                )}
                            </Group>
                        );
                    })}
                </Layer>
            </Stage>
            {selectedEdge &&
                (['source', 'target'] as const).map((side) => {
                    const node = nodes.find(
                        (item) => item.id === selectedEdge[side],
                    );
                    if (!node) return null;
                    const point = points[node.id];
                    const end =
                        side === 'source' ? selectedEdge.a : selectedEdge.b;
                    const model = node.model_id
                        ? models[node.model_id]
                        : undefined;
                    const port =
                        model &&
                        Object.values(model.panel.faces)
                            .flatMap((face) => face.ports)
                            .find((item) => item.id === end?.connector_id);
                    const portLabel = topologyPortLabel(
                        selectedEdge,
                        node,
                        false,
                    );
                    const title = portLabel
                        ? `${node.label}@${portLabel}`
                        : node.label;
                    const remoteEvidence = selectedEdge.evidence.filter(
                        (item) => item.device_id !== node.device_id,
                    );
                    const description = [
                        ...new Set(
                            remoteEvidence
                                .map((item) => item.remote_port_description)
                                .filter(Boolean),
                        ),
                    ].join(' / ');
                    const mac = [
                        ...new Set(
                            remoteEvidence
                                .map((item) => item.remote_port_mac)
                                .filter(Boolean),
                        ),
                    ].join(' / ');
                    return (
                        <Popover
                            key={`${selectedEdge.id}-${side}`}
                            open
                            modal={false}
                            onOpenChange={(open) => {
                                if (!open) onSelect(null);
                            }}
                        >
                            <PopoverAnchor asChild>
                                <span
                                    aria-hidden
                                    className="pointer-events-none absolute size-px"
                                    style={{
                                        left:
                                            view.x +
                                            (point.x +
                                                nodeSize(node.id).width / 2) *
                                                view.scale,
                                        top:
                                            view.y +
                                            (point.y +
                                                (side === 'target'
                                                    ? nodeSize(node.id).height
                                                    : 0)) *
                                                view.scale,
                                    }}
                                />
                            </PopoverAnchor>
                            <PopoverContent
                                data-topology-endpoint=""
                                role="region"
                                aria-label={title}
                                aria-describedby={undefined}
                                side={side === 'source' ? 'top' : 'bottom'}
                                sideOffset={12}
                                collisionPadding={12}
                                updatePositionStrategy="always"
                                className="w-64 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl p-0"
                                onOpenAutoFocus={(event) =>
                                    event.preventDefault()
                                }
                                onCloseAutoFocus={(event) =>
                                    event.preventDefault()
                                }
                                onInteractOutside={(event) =>
                                    event.preventDefault()
                                }
                            >
                                <PopoverTitle className="border-b px-3 py-2 text-sm break-words">
                                    {title}
                                </PopoverTitle>
                                {model && port ? (
                                    <PanelViewer
                                        panel={model.panel}
                                        assetUrls={model.assetUrls}
                                        highlightPortId={port.id}
                                        zoomToPort
                                        compact
                                    />
                                ) : (
                                    <div className="flex flex-col gap-3 px-3 py-4 text-xs">
                                        <p className="text-muted-foreground">
                                            {topologyPortMessage(
                                                selectedEdge,
                                                node,
                                            )}
                                        </p>
                                        {description && (
                                            <p className="break-words">
                                                Descrizione ricevuta:{' '}
                                                {description}
                                            </p>
                                        )}
                                        {mac && <p>MAC porta: {mac}</p>}
                                    </div>
                                )}
                            </PopoverContent>
                        </Popover>
                    );
                })}
            {Object.keys(routes).length < edges.length && (
                <p
                    role="status"
                    className="absolute right-3 bottom-3 left-3 rounded-md border bg-background p-2 text-xs"
                >
                    {rackLayout
                        ? 'Alcuni collegamenti non hanno spazio nella vista rack. Passa alla vista ad albero per consultarli.'
                        : 'Alcuni collegamenti non hanno spazio. Allontana i dispositivi sovrapposti o riordina il diagramma.'}
                </p>
            )}
        </div>
    );
}
