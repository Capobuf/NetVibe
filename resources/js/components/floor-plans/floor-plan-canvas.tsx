import type Konva from 'konva';
import {
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
    type Ref,
} from 'react';
import {
    Circle,
    Group,
    Image as CanvasImage,
    Label,
    Layer,
    Line,
    Rect,
    Stage,
    Tag,
    Text,
    Transformer,
} from 'react-konva';
import { Skeleton } from '@/components/ui/skeleton';
import { useAppearance } from '@/hooks/use-appearance';
import { scaleBarLength } from '@/lib/floor-plan';
import type { FloorCrop, FloorMarker } from '@/types/floor';

export const FLOOR_PLAN_DRAG_TYPE = 'application/x-netvibe-floor-plan';

/** A relation drawn between two markers: solid when documented, dashed when only reported by integrations. */
export type FloorPlanLine = {
    key: string;
    from: string;
    to: string;
    documented: boolean;
    stale: boolean;
};

/** Normal editing, choosing the visible part of the plan, or measuring a known distance. */
export type FloorPlanMode = 'normal' | 'crop' | 'measure';

type Point = { x: number; y: number };

export type FloorPlanCanvasApi = {
    fit: () => void;
    zoom: (factor: number) => void;
    focus: (key: string) => void;
    /** Centre of the visible area, as image fractions. */
    center: () => Point;
    /** Last pointer position over the plan, or the centre of the view, as image fractions. */
    pointer: () => Point;
};

const clamp = (value: number, min = 0, max = 1) =>
    Math.min(max, Math.max(min, value));
const MIN_CROP = 0.05;

export function FloorPlanCanvas({
    imageUrl,
    width,
    height,
    crop,
    scale,
    markers,
    selected,
    highlighted,
    lines = [],
    labels,
    editing,
    mode = 'normal',
    cropDraft,
    onCropDraft,
    onMeasure,
    onSelect,
    onMove,
    onDrop,
    onRemove,
    onResize,
    onContext,
    onZoom,
    onError,
    api,
}: {
    imageUrl: string;
    width: number;
    height: number;
    crop: FloorCrop | null;
    /** Image pixels per metre, for the scale bar. */
    scale: number | null;
    /** Visible items, in stacking order. */
    markers: FloorMarker[];
    selected: string | null;
    /** Markers matching the search; null when nothing is searched. */
    highlighted: Set<string> | null;
    lines?: FloorPlanLine[];
    labels: boolean;
    editing: boolean;
    mode?: FloorPlanMode;
    cropDraft?: FloorCrop | null;
    onCropDraft?: (crop: FloorCrop) => void;
    /** Two points of a known distance, in image pixels. */
    onMeasure?: (a: Point, b: Point) => void;
    onSelect: (key: string | null) => void;
    onMove: (key: string, x: number, y: number) => void;
    onDrop: (key: string, x: number, y: number) => void;
    onRemove: (key: string) => void;
    /** New footprint in image pixels and its centre as image fractions. */
    onResize: (
        key: string,
        width: number,
        height: number,
        x: number,
        y: number,
    ) => void;
    /** Right click on an item, or on the plan when key is null; the point is in image fractions. */
    onContext: (key: string | null, point: Point) => void;
    onZoom: (zoom: number) => void;
    onError: (message: string) => void;
    api: Ref<FloorPlanCanvasApi>;
}) {
    const container = useRef<HTMLDivElement>(null);
    const stage = useRef<Konva.Stage>(null);
    const transformer = useRef<Konva.Transformer>(null);
    const cropTransformer = useRef<Konva.Transformer>(null);
    const cropRect = useRef<Konva.Rect>(null);
    const footprints = useRef(new Map<string, Konva.Rect>());
    const lastPointer = useRef<Point | null>(null);
    const { resolvedAppearance } = useAppearance();
    const dark = resolvedAppearance === 'dark';
    const [size, setSize] = useState({ width: 800, height: 560 });
    const [view, setView] = useState({ x: 0, y: 0, scale: 1 });
    const [image, setImage] = useState<HTMLImageElement | null>(null);
    const [hovered, setHovered] = useState<string | null>(null);
    const [measureStart, setMeasureStart] = useState<Point | null>(null);
    const [measureEnd, setMeasureEnd] = useState<Point | null>(null);
    const colors = dark
        ? {
              surface: '#171717',
              text: '#fafafa',
              border: '#404040',
              accent: '#60a5fa',
              area: 'rgba(96, 165, 250, 0.16)',
              areaActive: 'rgba(96, 165, 250, 0.3)',
              rack: '#e5e5e5',
              rackLine: '#404040',
              halo: '#0a0a0a',
          }
        : {
              surface: '#ffffff',
              text: '#171717',
              border: '#d4d4d4',
              accent: '#2563eb',
              area: 'rgba(37, 99, 235, 0.12)',
              areaActive: 'rgba(37, 99, 235, 0.24)',
              rack: '#262626',
              rackLine: '#a3a3a3',
              halo: '#ffffff',
          };
    // Visible part of the image, in image pixels; the whole image while choosing the crop.
    const bounds =
        mode !== 'crop' && crop
            ? {
                  x: crop.x * width,
                  y: crop.y * height,
                  width: crop.width * width,
                  height: crop.height * height,
              }
            : { x: 0, y: 0, width, height };

    useEffect(() => {
        let cancelled = false;
        const next = new window.Image();
        next.src = imageUrl;
        next.decode()
            .then(() => {
                if (!cancelled) setImage(next);
            })
            .catch(() => {
                if (!cancelled)
                    onError(
                        'Impossibile caricare l’immagine della planimetria. Ricarica la pagina per riprovare.',
                    );
            });
        return () => {
            cancelled = true;
        };
        // onError is a notification callback; only a new image needs reloading.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [imageUrl]);

    function applyView(next: typeof view) {
        setView(next);
        onZoom(next.scale);
    }
    function fit(viewport = size) {
        const factor = Math.min(
            (viewport.width - 48) / bounds.width,
            (viewport.height - 48) / bounds.height,
        );
        applyView({
            x: (viewport.width - bounds.width * factor) / 2 - bounds.x * factor,
            y:
                (viewport.height - bounds.height * factor) / 2 -
                bounds.y * factor,
            scale: factor,
        });
    }
    function zoom(
        factor: number,
        anchor = { x: size.width / 2, y: size.height / 2 },
    ) {
        const next = Math.max(0.02, Math.min(8, view.scale * factor));
        applyView({
            x: anchor.x - ((anchor.x - view.x) * next) / view.scale,
            y: anchor.y - ((anchor.y - view.y) * next) / view.scale,
            scale: next,
        });
    }
    /** Screen point to image fractions, kept inside the visible part of the plan. */
    function toImage(point: Point): Point {
        return {
            x: clamp(
                (point.x - view.x) / view.scale / width,
                bounds.x / width,
                (bounds.x + bounds.width) / width,
            ),
            y: clamp(
                (point.y - view.y) / view.scale / height,
                bounds.y / height,
                (bounds.y + bounds.height) / height,
            ),
        };
    }
    function toPixels(point: Point): Point {
        return {
            x: clamp((point.x - view.x) / view.scale, 0, width),
            y: clamp((point.y - view.y) / view.scale, 0, height),
        };
    }
    useEffect(() => {
        if (!container.current) return;
        let previous: typeof size | null = null;
        const observer = new ResizeObserver(([entry]) => {
            const viewport = {
                width: entry.contentRect.width,
                height: entry.contentRect.height,
            };
            setSize(viewport);
            if (!previous) fit(viewport);
            else {
                const dx = (viewport.width - previous.width) / 2;
                const dy = (viewport.height - previous.height) / 2;
                setView((current) => ({
                    ...current,
                    x: current.x + dx,
                    y: current.y + dy,
                }));
            }
            previous = viewport;
        });
        observer.observe(container.current);
        return () => observer.disconnect();
        // The initial fit belongs to this canvas mount.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    // A new crop, or entering and leaving the crop, changes what there is to fit.
    const fitted = useRef(false);
    const boundsKey = `${bounds.x}:${bounds.y}:${bounds.width}:${bounds.height}`;
    useEffect(() => {
        if (fitted.current) fit();
        fitted.current = true;
        // Only a change of the visible area refits the view.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [boundsKey]);
    // A measure left halfway does not survive leaving the mode.
    if (mode !== 'measure' && measureStart) {
        setMeasureStart(null);
        setMeasureEnd(null);
    }
    useImperativeHandle(api, () => ({
        fit: () => fit(),
        zoom,
        focus: (key) => {
            const marker = markers.find((item) => item.key === key);
            if (!marker) return;
            applyView({
                ...view,
                x: size.width / 2 - marker.x * width * view.scale,
                y: size.height / 2 - marker.y * height * view.scale,
            });
        },
        center: () => toImage({ x: size.width / 2, y: size.height / 2 }),
        pointer: () =>
            lastPointer.current ??
            toImage({ x: size.width / 2, y: size.height / 2 }),
    }));
    function setCursor(cursor: string) {
        if (container.current) container.current.style.cursor = cursor;
    }
    const selectedMarker = markers.find((marker) => marker.key === selected);
    const markerByKey = new Map(markers.map((marker) => [marker.key, marker]));
    const resizable =
        mode === 'normal' &&
        editing &&
        !!selectedMarker?.footprint &&
        !selectedMarker.locked;
    // Handles follow the selected footprint, or the crop; reattached only when the target changes.
    useEffect(() => {
        const node = selected ? footprints.current.get(selected) : undefined;
        for (const [handles, target] of [
            [transformer.current, resizable ? node : undefined],
            [
                cropTransformer.current,
                mode === 'crop' ? cropRect.current : undefined,
            ],
        ] as const) {
            if (handles && handles.nodes()[0] !== target)
                handles.nodes(target ? [target] : []);
        }
    });
    const draft = cropDraft ?? { x: 0, y: 0, width: 1, height: 1 };
    const draftPixels = {
        x: draft.x * width,
        y: draft.y * height,
        width: draft.width * width,
        height: draft.height * height,
    };
    function commitCrop(node: Konva.Rect) {
        const w = clamp(node.width() * node.scaleX(), MIN_CROP * width, width);
        const h = clamp(
            node.height() * node.scaleY(),
            MIN_CROP * height,
            height,
        );
        const x = clamp(node.x(), 0, width - w);
        const y = clamp(node.y(), 0, height - h);
        node.setAttrs({ x, y, width: w, height: h, scaleX: 1, scaleY: 1 });
        onCropDraft?.({
            x: x / width,
            y: y / height,
            width: w / width,
            height: h / height,
        });
    }
    const pixelsPerMetre = scale ? scale * view.scale : null;
    const barMetres = pixelsPerMetre ? scaleBarLength(pixelsPerMetre) : null;
    const measureTo = measureEnd ?? measureStart;
    const interactive = mode === 'normal';

    return (
        <div
            ref={container}
            tabIndex={0}
            aria-label="Planimetria. Frecce per spostare la vista o, in modifica, l’elemento selezionato; più e meno per lo zoom; zero per adattare; Canc per rimuovere la posizione; Ctrl o Cmd con C, X e V per copiare, tagliare e incollare."
            className="relative h-full w-full touch-none bg-muted/40 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
            onDragOver={(event) => {
                if (
                    editing &&
                    interactive &&
                    event.dataTransfer.types.includes(FLOOR_PLAN_DRAG_TYPE)
                ) {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                }
            }}
            onDrop={(event) => {
                const key = event.dataTransfer.getData(FLOOR_PLAN_DRAG_TYPE);
                if (!editing || !interactive || !key || !stage.current) return;
                event.preventDefault();
                stage.current.setPointersPositions(event.nativeEvent);
                const pointer = stage.current.getPointerPosition();
                if (!pointer) return;
                const point = toImage(pointer);
                container.current?.focus({ preventScroll: true });
                onDrop(key, point.x, point.y);
            }}
            onKeyDown={(event) => {
                if (event.target !== event.currentTarget) return;
                if (event.key === 'Escape') onSelect(null);
                else if (event.key === '+' || event.key === '=') zoom(1.2);
                else if (event.key === '-') zoom(1 / 1.2);
                else if (event.key === '0') fit();
                else if (
                    editing &&
                    interactive &&
                    selected &&
                    (event.key === 'Delete' || event.key === 'Backspace')
                ) {
                    if (!event.repeat) onRemove(selected);
                } else {
                    const delta: Record<string, [number, number]> = {
                        ArrowLeft: [-1, 0],
                        ArrowRight: [1, 0],
                        ArrowUp: [0, -1],
                        ArrowDown: [0, 1],
                    };
                    const direction = delta[event.key];
                    if (!direction) return;
                    if (editing && interactive && selectedMarker) {
                        // Screen pixels, so the step feels the same at every zoom.
                        const step = (event.shiftKey ? 1 : 8) / view.scale;
                        onMove(
                            selectedMarker.key,
                            clamp(
                                selectedMarker.x +
                                    (direction[0] * step) / width,
                            ),
                            clamp(
                                selectedMarker.y +
                                    (direction[1] * step) / height,
                            ),
                        );
                    } else
                        applyView({
                            ...view,
                            x: view.x - direction[0] * 40,
                            y: view.y - direction[1] * 40,
                        });
                }
                event.preventDefault();
            }}
        >
            {!image && (
                <Skeleton
                    className="absolute rounded-none"
                    style={{
                        left: view.x + bounds.x * view.scale,
                        top: view.y + bounds.y * view.scale,
                        width: bounds.width * view.scale,
                        height: bounds.height * view.scale,
                    }}
                />
            )}
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
                onMouseMove={() => {
                    const pointer = stage.current?.getPointerPosition();
                    if (!pointer) return;
                    lastPointer.current = toImage(pointer);
                    if (mode === 'measure' && measureStart)
                        setMeasureEnd(toPixels(pointer));
                }}
                onMouseLeave={() => {
                    lastPointer.current = null;
                }}
                onDragMove={(event) => {
                    if (event.target === stage.current)
                        setView((current) => ({
                            ...current,
                            x: event.target.x(),
                            y: event.target.y(),
                        }));
                }}
                onDragEnd={(event) => {
                    if (event.target === stage.current)
                        applyView({
                            ...view,
                            x: event.target.x(),
                            y: event.target.y(),
                        });
                }}
                onClick={(event) => {
                    container.current?.focus({ preventScroll: true });
                    if (event.evt.button !== 0) return;
                    if (mode === 'measure') {
                        const pointer = stage.current?.getPointerPosition();
                        if (!pointer) return;
                        const point = toPixels(pointer);
                        if (!measureStart) setMeasureStart(point);
                        else {
                            setMeasureStart(null);
                            setMeasureEnd(null);
                            onMeasure?.(measureStart, point);
                        }
                        return;
                    }
                    if (event.target === event.target.getStage())
                        onSelect(null);
                }}
                onTap={(event) => {
                    if (interactive && event.target === event.target.getStage())
                        onSelect(null);
                }}
                onContextMenu={(event) => {
                    if (!interactive) return;
                    const item = event.target.findAncestor('.item', true);
                    const pointer = stage.current?.getPointerPosition();
                    onContext(
                        item ? item.id().replace(/#footprint$/, '') : null,
                        pointer
                            ? toImage(pointer)
                            : toImage({
                                  x: size.width / 2,
                                  y: size.height / 2,
                              }),
                    );
                }}
            >
                <Layer>
                    {image && (
                        <CanvasImage
                            image={image}
                            x={bounds.x}
                            y={bounds.y}
                            width={bounds.width}
                            height={bounds.height}
                            crop={{
                                x: (bounds.x * image.naturalWidth) / width,
                                y: (bounds.y * image.naturalHeight) / height,
                                width:
                                    (bounds.width * image.naturalWidth) / width,
                                height:
                                    (bounds.height * image.naturalHeight) /
                                    height,
                            }}
                            listening={false}
                        />
                    )}
                    <Rect
                        x={bounds.x}
                        y={bounds.y}
                        width={bounds.width}
                        height={bounds.height}
                        stroke={colors.border}
                        strokeWidth={1 / view.scale}
                        listening={false}
                    />
                    {mode !== 'crop' &&
                        markers.map((marker) => {
                            if (!marker.footprint) return null;
                            const active = marker.key === selected;
                            const dimmed =
                                highlighted !== null &&
                                !highlighted.has(marker.key);
                            return (
                                <Rect
                                    key={marker.key}
                                    ref={(node) => {
                                        if (node)
                                            footprints.current.set(
                                                marker.key,
                                                node,
                                            );
                                        else
                                            footprints.current.delete(
                                                marker.key,
                                            );
                                    }}
                                    name="item"
                                    id={`${marker.key}#footprint`}
                                    x={marker.x * width}
                                    y={marker.y * height}
                                    width={marker.footprint.width}
                                    height={marker.footprint.height}
                                    offsetX={marker.footprint.width / 2}
                                    offsetY={marker.footprint.height / 2}
                                    fill={
                                        active ? colors.areaActive : colors.area
                                    }
                                    stroke={colors.accent}
                                    strokeWidth={(active ? 2 : 1) / view.scale}
                                    opacity={dimmed ? 0.35 : 1}
                                    listening={interactive}
                                    draggable={
                                        editing && interactive && !marker.locked
                                    }
                                    onDragStart={(event) => {
                                        event.cancelBubble = true;
                                        onSelect(marker.key);
                                    }}
                                    onDragEnd={(event) => {
                                        event.cancelBubble = true;
                                        onMove(
                                            marker.key,
                                            clamp(event.target.x() / width),
                                            clamp(event.target.y() / height),
                                        );
                                    }}
                                    onTransformEnd={(event) => {
                                        const node = event.target as Konva.Rect;
                                        const w = node.width() * node.scaleX();
                                        const h = node.height() * node.scaleY();
                                        node.scale({ x: 1, y: 1 });
                                        onResize(
                                            marker.key,
                                            w,
                                            h,
                                            clamp(node.x() / width),
                                            clamp(node.y() / height),
                                        );
                                    }}
                                    onClick={(event) => {
                                        if (event.evt.button !== 0) return;
                                        event.cancelBubble = true;
                                        container.current?.focus({
                                            preventScroll: true,
                                        });
                                        onSelect(marker.key);
                                    }}
                                    onTap={(event) => {
                                        event.cancelBubble = true;
                                        onSelect(marker.key);
                                    }}
                                />
                            );
                        })}
                    {mode !== 'crop' &&
                        lines.map((line) => {
                            const from = markerByKey.get(line.from);
                            const to = markerByKey.get(line.to);
                            if (!from || !to) return null;
                            const points = [
                                from.x * width,
                                from.y * height,
                                to.x * width,
                                to.y * height,
                            ];
                            return (
                                <Group
                                    key={line.key}
                                    listening={false}
                                    opacity={line.stale ? 0.5 : 1}
                                >
                                    {/* The halo keeps the line readable over any drawing. */}
                                    <Line
                                        points={points}
                                        stroke={colors.halo}
                                        strokeWidth={6 / view.scale}
                                        lineCap="round"
                                        opacity={0.8}
                                    />
                                    <Line
                                        points={points}
                                        stroke={colors.accent}
                                        strokeWidth={2.5 / view.scale}
                                        lineCap="round"
                                        dash={
                                            line.documented
                                                ? undefined
                                                : [
                                                      10 / view.scale,
                                                      6 / view.scale,
                                                  ]
                                        }
                                    />
                                </Group>
                            );
                        })}
                    {mode !== 'crop' &&
                        markers.map((marker) => {
                            const active = marker.key === selected;
                            const dimmed =
                                highlighted !== null &&
                                !highlighted.has(marker.key);
                            const showLabel =
                                active ||
                                hovered === marker.key ||
                                (labels && !dimmed);
                            const movable =
                                editing && interactive && !marker.locked;
                            return (
                                <Group
                                    key={marker.key}
                                    name="item"
                                    id={marker.key}
                                    x={marker.x * width}
                                    y={marker.y * height}
                                    // Markers keep the same size on screen at every zoom.
                                    scaleX={1 / view.scale}
                                    scaleY={1 / view.scale}
                                    opacity={dimmed ? 0.25 : 1}
                                    listening={interactive}
                                    draggable={movable}
                                    dragBoundFunc={(position) => ({
                                        x: Math.min(
                                            view.x +
                                                (bounds.x + bounds.width) *
                                                    view.scale,
                                            Math.max(
                                                view.x + bounds.x * view.scale,
                                                position.x,
                                            ),
                                        ),
                                        y: Math.min(
                                            view.y +
                                                (bounds.y + bounds.height) *
                                                    view.scale,
                                            Math.max(
                                                view.y + bounds.y * view.scale,
                                                position.y,
                                            ),
                                        ),
                                    })}
                                    onDragStart={(event) => {
                                        event.cancelBubble = true;
                                        container.current?.focus({
                                            preventScroll: true,
                                        });
                                        onSelect(marker.key);
                                        setCursor('grabbing');
                                    }}
                                    onDragEnd={(event) => {
                                        event.cancelBubble = true;
                                        setCursor('grab');
                                        onMove(
                                            marker.key,
                                            clamp(event.target.x() / width),
                                            clamp(event.target.y() / height),
                                        );
                                    }}
                                    onClick={(event) => {
                                        if (event.evt.button !== 0) return;
                                        event.cancelBubble = true;
                                        container.current?.focus({
                                            preventScroll: true,
                                        });
                                        onSelect(active ? null : marker.key);
                                    }}
                                    onTap={(event) => {
                                        event.cancelBubble = true;
                                        onSelect(active ? null : marker.key);
                                    }}
                                    onMouseEnter={() => {
                                        setHovered(marker.key);
                                        setCursor(movable ? 'grab' : 'pointer');
                                    }}
                                    onMouseLeave={() => {
                                        setHovered(null);
                                        setCursor('default');
                                    }}
                                >
                                    {active && (
                                        <Circle
                                            radius={18}
                                            fill={colors.accent}
                                            opacity={0.18}
                                            stroke={colors.accent}
                                            strokeWidth={2}
                                        />
                                    )}
                                    {marker.kind === 'rack' ? (
                                        <>
                                            <Rect
                                                x={-11}
                                                y={-11}
                                                width={22}
                                                height={22}
                                                cornerRadius={5}
                                                fill={colors.rack}
                                                stroke={colors.halo}
                                                strokeWidth={2}
                                                shadowColor="#000000"
                                                shadowOpacity={0.3}
                                                shadowBlur={4}
                                                shadowOffsetY={1}
                                            />
                                            {[-4, 0, 4].map((y) => (
                                                <Line
                                                    key={y}
                                                    points={[-5, y, 5, y]}
                                                    stroke={colors.rackLine}
                                                    strokeWidth={1.5}
                                                    lineCap="round"
                                                    listening={false}
                                                />
                                            ))}
                                        </>
                                    ) : (
                                        <>
                                            <Circle
                                                radius={10}
                                                fill={colors.accent}
                                                stroke={colors.halo}
                                                strokeWidth={2}
                                                shadowColor="#000000"
                                                shadowOpacity={0.3}
                                                shadowBlur={4}
                                                shadowOffsetY={1}
                                            />
                                            <Circle
                                                radius={3.5}
                                                fill={colors.halo}
                                                listening={false}
                                            />
                                        </>
                                    )}
                                    {showLabel && (
                                        <Label y={16} listening={false}>
                                            <Tag
                                                fill={colors.surface}
                                                stroke={
                                                    active
                                                        ? colors.accent
                                                        : colors.border
                                                }
                                                strokeWidth={1}
                                                cornerRadius={4}
                                                pointerDirection="up"
                                                pointerWidth={8}
                                                pointerHeight={4}
                                                opacity={0.95}
                                            />
                                            <Text
                                                text={
                                                    marker.label.length > 40
                                                        ? `${marker.label.slice(0, 39)}…`
                                                        : marker.label
                                                }
                                                fontSize={12}
                                                fontFamily="Instrument Sans, sans-serif"
                                                fontStyle={
                                                    active ? 'bold' : 'normal'
                                                }
                                                fill={colors.text}
                                                padding={5}
                                            />
                                        </Label>
                                    )}
                                </Group>
                            );
                        })}
                    {mode === 'crop' && (
                        <>
                            {/* Everything outside the crop is dimmed, not removed. */}
                            {[
                                [0, 0, width, draftPixels.y],
                                [
                                    0,
                                    draftPixels.y + draftPixels.height,
                                    width,
                                    height - draftPixels.y - draftPixels.height,
                                ],
                                [
                                    0,
                                    draftPixels.y,
                                    draftPixels.x,
                                    draftPixels.height,
                                ],
                                [
                                    draftPixels.x + draftPixels.width,
                                    draftPixels.y,
                                    width - draftPixels.x - draftPixels.width,
                                    draftPixels.height,
                                ],
                            ].map(([x, y, w, h], index) => (
                                <Rect
                                    key={index}
                                    x={x}
                                    y={y}
                                    width={Math.max(0, w)}
                                    height={Math.max(0, h)}
                                    fill="#000000"
                                    opacity={0.5}
                                    listening={false}
                                />
                            ))}
                            <Rect
                                ref={cropRect}
                                {...draftPixels}
                                stroke={colors.accent}
                                strokeWidth={2 / view.scale}
                                draggable
                                onDragStart={(event) => {
                                    event.cancelBubble = true;
                                }}
                                onDragEnd={(event) => {
                                    event.cancelBubble = true;
                                    commitCrop(event.target as Konva.Rect);
                                }}
                                onTransformEnd={(event) =>
                                    commitCrop(event.target as Konva.Rect)
                                }
                                onMouseEnter={() => setCursor('move')}
                                onMouseLeave={() => setCursor('default')}
                            />
                            <Transformer
                                ref={cropTransformer}
                                rotateEnabled={false}
                                flipEnabled={false}
                                keepRatio={false}
                                ignoreStroke
                                anchorSize={9}
                                anchorCornerRadius={2}
                                borderStroke={colors.accent}
                                anchorStroke={colors.accent}
                                boundBoxFunc={(previous, next) =>
                                    next.width <
                                        MIN_CROP * width * view.scale ||
                                    next.height < MIN_CROP * height * view.scale
                                        ? previous
                                        : next
                                }
                            />
                        </>
                    )}
                    {mode === 'measure' && measureStart && measureTo && (
                        <Group listening={false}>
                            <Line
                                points={[
                                    measureStart.x,
                                    measureStart.y,
                                    measureTo.x,
                                    measureTo.y,
                                ]}
                                stroke={colors.accent}
                                strokeWidth={2 / view.scale}
                                dash={[8 / view.scale, 4 / view.scale]}
                            />
                            {[measureStart, measureTo].map((point, index) => (
                                <Circle
                                    key={index}
                                    x={point.x}
                                    y={point.y}
                                    radius={4 / view.scale}
                                    fill={colors.accent}
                                    stroke={colors.halo}
                                    strokeWidth={1.5 / view.scale}
                                />
                            ))}
                        </Group>
                    )}
                    <Transformer
                        ref={transformer}
                        rotateEnabled={false}
                        flipEnabled={false}
                        keepRatio={false}
                        ignoreStroke
                        anchorSize={9}
                        anchorCornerRadius={2}
                        borderStroke={colors.accent}
                        anchorStroke={colors.accent}
                        boundBoxFunc={(previous, next) =>
                            next.width < 6 || next.height < 6 ? previous : next
                        }
                    />
                </Layer>
            </Stage>
            {barMetres && pixelsPerMetre && mode !== 'crop' && (
                <div
                    className="pointer-events-none absolute bottom-3 left-3 flex flex-col items-start gap-1 rounded-md bg-background/90 px-2 py-1.5 text-xs text-foreground shadow-sm"
                    aria-hidden
                >
                    <span className="tabular-nums">
                        {barMetres.toLocaleString('it-IT')} m
                    </span>
                    <span
                        className="h-1.5 border-x-2 border-b-2 border-foreground"
                        style={{ width: barMetres * pixelsPerMetre }}
                    />
                </div>
            )}
        </div>
    );
}
