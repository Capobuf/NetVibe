import type Konva from 'konva';
import { useEffect, useImperativeHandle, useRef, useState, type Ref } from 'react';
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
} from 'react-konva';
import { Skeleton } from '@/components/ui/skeleton';
import { useAppearance } from '@/hooks/use-appearance';
import type { FloorPlanMarker } from '@/types/floor-plan';

export const FLOOR_PLAN_DRAG_TYPE = 'application/x-netvibe-floor-plan';

export type FloorPlanCanvasApi = {
    fit: () => void;
    zoom: (factor: number) => void;
    focus: (key: string) => void;
    /** Centre of the visible area, as image fractions. */
    center: () => { x: number; y: number };
};

const clamp = (value: number) => Math.min(1, Math.max(0, value));

export function FloorPlanCanvas({
    imageUrl,
    width,
    height,
    markers,
    selected,
    highlighted,
    labels,
    editing,
    onSelect,
    onMove,
    onDrop,
    onRemove,
    onZoom,
    onError,
    api,
}: {
    imageUrl: string;
    width: number;
    height: number;
    markers: FloorPlanMarker[];
    selected: string | null;
    /** Markers matching the search; null when nothing is searched. */
    highlighted: Set<string> | null;
    labels: boolean;
    editing: boolean;
    onSelect: (key: string | null) => void;
    onMove: (key: string, x: number, y: number) => void;
    onDrop: (key: string, x: number, y: number) => void;
    onRemove: (key: string) => void;
    onZoom: (zoom: number) => void;
    onError: (message: string) => void;
    api: Ref<FloorPlanCanvasApi>;
}) {
    const container = useRef<HTMLDivElement>(null);
    const stage = useRef<Konva.Stage>(null);
    const { resolvedAppearance } = useAppearance();
    const dark = resolvedAppearance === 'dark';
    const [size, setSize] = useState({ width: 800, height: 560 });
    const [view, setView] = useState({ x: 0, y: 0, scale: 1 });
    const [image, setImage] = useState<HTMLImageElement | null>(null);
    const [hovered, setHovered] = useState<string | null>(null);
    const colors = dark
        ? {
              surface: '#171717',
              text: '#fafafa',
              border: '#404040',
              accent: '#60a5fa',
              rack: '#e5e5e5',
              rackLine: '#404040',
              halo: '#0a0a0a',
          }
        : {
              surface: '#ffffff',
              text: '#171717',
              border: '#d4d4d4',
              accent: '#2563eb',
              rack: '#262626',
              rackLine: '#a3a3a3',
              halo: '#ffffff',
          };

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
    function fit(bounds = size) {
        const scale = Math.min(
            (bounds.width - 48) / width,
            (bounds.height - 48) / height,
        );
        applyView({
            x: (bounds.width - width * scale) / 2,
            y: (bounds.height - height * scale) / 2,
            scale,
        });
    }
    function zoom(
        factor: number,
        anchor = { x: size.width / 2, y: size.height / 2 },
    ) {
        const scale = Math.max(0.02, Math.min(8, view.scale * factor));
        applyView({
            x: anchor.x - ((anchor.x - view.x) * scale) / view.scale,
            y: anchor.y - ((anchor.y - view.y) * scale) / view.scale,
            scale,
        });
    }
    function toImage(point: { x: number; y: number }) {
        return {
            x: clamp((point.x - view.x) / view.scale / width),
            y: clamp((point.y - view.y) / view.scale / height),
        };
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
            if (!previous) fit(bounds);
            else {
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
        // The initial fit belongs to this canvas mount.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
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
    }));
    function setCursor(cursor: string) {
        if (container.current) container.current.style.cursor = cursor;
    }
    const selectedMarker = markers.find((marker) => marker.key === selected);

    return (
        <div
            ref={container}
            tabIndex={0}
            aria-label="Planimetria. Frecce per spostare la vista o, in modifica, l’elemento selezionato; più e meno per lo zoom; zero per adattare; Canc per rimuovere la posizione."
            className="relative h-full w-full touch-none bg-muted/40 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
            onDragOver={(event) => {
                if (
                    editing &&
                    event.dataTransfer.types.includes(FLOOR_PLAN_DRAG_TYPE)
                ) {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                }
            }}
            onDrop={(event) => {
                const key = event.dataTransfer.getData(FLOOR_PLAN_DRAG_TYPE);
                if (!editing || !key || !stage.current) return;
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
                    if (editing && selectedMarker) {
                        // Screen pixels, so the step feels the same at every zoom.
                        const step = (event.shiftKey ? 1 : 8) / view.scale;
                        onMove(
                            selectedMarker.key,
                            clamp(
                                selectedMarker.x + (direction[0] * step) / width,
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
                        left: view.x,
                        top: view.y,
                        width: width * view.scale,
                        height: height * view.scale,
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
                    if (event.target === event.target.getStage())
                        onSelect(null);
                }}
                onTap={(event) => {
                    if (event.target === event.target.getStage())
                        onSelect(null);
                }}
            >
                <Layer>
                    {image && (
                        <CanvasImage
                            image={image}
                            width={width}
                            height={height}
                            listening={false}
                        />
                    )}
                    <Rect
                        width={width}
                        height={height}
                        stroke={colors.border}
                        strokeWidth={1 / view.scale}
                        listening={false}
                    />
                    {markers.map((marker) => {
                        const active = marker.key === selected;
                        const dimmed =
                            highlighted !== null &&
                            !highlighted.has(marker.key);
                        const showLabel =
                            active ||
                            hovered === marker.key ||
                            (labels && !dimmed);
                        return (
                            <Group
                                key={marker.key}
                                x={marker.x * width}
                                y={marker.y * height}
                                // Markers keep the same size on screen at every zoom.
                                scaleX={1 / view.scale}
                                scaleY={1 / view.scale}
                                opacity={dimmed ? 0.25 : 1}
                                draggable={editing}
                                dragBoundFunc={(position) => ({
                                    x: Math.min(
                                        view.x + width * view.scale,
                                        Math.max(view.x, position.x),
                                    ),
                                    y: Math.min(
                                        view.y + height * view.scale,
                                        Math.max(view.y, position.y),
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
                                    setCursor(editing ? 'grab' : 'pointer');
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
                                            fontStyle={active ? 'bold' : 'normal'}
                                            fill={colors.text}
                                            padding={5}
                                        />
                                    </Label>
                                )}
                            </Group>
                        );
                    })}
                </Layer>
            </Stage>
        </div>
    );
}
