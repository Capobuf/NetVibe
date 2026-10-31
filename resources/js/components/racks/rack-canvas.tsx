import type Konva from 'konva';
import {
    useEffect,
    useImperativeHandle,
    useLayoutEffect,
    useRef,
    useState,
    type ReactElement,
    type Ref,
} from 'react';
import { Group, Layer, Line, Rect, Stage, Text } from 'react-konva';
import { RackCables } from './rack-cables';
import {
    panelTransform,
    RACK_LEFT as LEFT,
    RACK_TOP as TOP,
    FACE_WIDTH,
    type Cable,
    type CableEnd,
} from '@/lib/rack-cabling';
import { PanelScene } from '@/components/hardware-editor/panel-scene';
import { useAppearance } from '@/hooks/use-appearance';
import {
    RACK_UNIT_HEIGHT as U,
    RACK_WIDTH as WIDTH,
    type Face,
} from '@/lib/hardware-editor/document';
import {
    clampDeviceDrag,
    dropDevice,
    itemBounds,
    itemName,
    unitLabel,
} from '@/lib/rack-layout';
import type { Rack, RackDevice, RackItem } from '@/types/rack';

export type RackCanvasApi = {
    fit: () => void;
    zoom: (factor: number) => void;
};

export function DeviceDrawing({
    device,
    face,
    width,
    height,
    dark,
    zoom,
    onError,
}: {
    device: RackDevice;
    face: Face;
    width: number;
    height: number;
    dark: boolean;
    zoom: number;
    onError: (message: string) => void;
}) {
    const [images, setImages] = useState<Record<string, HTMLImageElement>>({});
    const assetKey = JSON.stringify(device.assetUrls);
    useEffect(() => {
        let cancelled = false;
        const entries = Object.entries(
            JSON.parse(assetKey) as Record<string, string>,
        );
        Promise.all(
            entries.map(async ([id, url]) => {
                const image = new Image();
                image.src = url;
                await image.decode();
                return [id, image] as const;
            }),
        )
            .then((loaded) => {
                if (!cancelled) setImages(Object.fromEntries(loaded));
            })
            .catch(() => {
                if (!cancelled)
                    onError(
                        'Impossibile caricare un’immagine del modello. Ricarica la pagina.',
                    );
            });
        return () => {
            cancelled = true;
        };
    }, [assetKey, onError]);
    const panel = device.model?.panel;
    if (!panel)
        return (
            <Text
                text={device.model?.name ?? device.name}
                x={12}
                y={height / 2 - 7}
                width={width - 24}
                height={16}
                ellipsis
                fontSize={13}
                fill={dark ? '#e2e8f0' : '#334155'}
            />
        );
    const side = panel.faces[face];
    const { x, y, scale } = panelTransform(device, face, width, height);
    return (
        <Group x={x} y={y} scaleX={scale} scaleY={scale} listening={false}>
            <PanelScene
                shell={panel.shell}
                face={side}
                images={images}
                dark={dark}
                zoom={scale * zoom}
            />
        </Group>
    );
}

function ItemDrawing({
    item,
    devices,
    face,
    dark,
    zoom,
    onError,
}: {
    item: RackItem;
    devices: RackDevice[];
    face: Face;
    dark: boolean;
    zoom: number;
    onError: (message: string) => void;
}) {
    const width = (WIDTH * item.width) / 100;
    const height = U * item.height;
    const device = devices.find((candidate) => candidate.id === item.device_id);
    const rackBackground = dark ? '#111827' : '#f8fafc';
    const text = dark ? '#e2e8f0' : '#334155';
    if (item.kind === 'shelf')
        return (
            <Line
                points={[0, 0, 0, U, width, U, width, 0]}
                stroke={dark ? '#94a3b8' : '#64748b'}
                strokeWidth={3}
                hitStrokeWidth={12}
                lineJoin="round"
            />
        );
    return (
        <>
            {/* Mask unit guides behind devices using the rack background, without a visible frame. */}
            <Rect
                width={width}
                height={height}
                fill={device ? rackBackground : dark ? '#1e293b' : '#eef2f6'}
                stroke={device ? rackBackground : dark ? '#64748b' : '#94a3b8'}
                strokeWidth={device ? 2 : 1}
                cornerRadius={device ? 0 : item.shelf_key ? 5 : 2}
            />
            <Group
                listening={false}
                clipX={0}
                clipY={0}
                clipWidth={width}
                clipHeight={height}
            >
                {device ? (
                    <DeviceDrawing
                        device={device}
                        face={face}
                        width={width}
                        height={height}
                        dark={dark}
                        zoom={zoom}
                        onError={onError}
                    />
                ) : (
                    <>
                        <Rect
                            x={width / 2 - 42}
                            y={height / 2 - 5}
                            width={84}
                            height={10}
                            stroke={text}
                            cornerRadius={3}
                        />
                        <Text
                            text="Cassetto"
                            x={12}
                            y={height / 2 - 7}
                            fill={text}
                            fontSize={13}
                        />
                    </>
                )}
            </Group>
        </>
    );
}

export function RackCanvas({
    rack,
    items,
    devices,
    face,
    selected,
    api,
    onZoom,
    onSelect,
    onMove,
    onPlace,
    onError,
    disabled,
    pan,
    cables,
    allCables,
    focusedBundle,
    selectedCable,
    pendingCable,
    onPort,
    onConnect,
    onBundle,
    onCable,
    onReconnect,
}: {
    pan: boolean;
    cables: Cable[];
    allCables: Cable[];
    focusedBundle: string | null;
    selectedCable: number | null;
    pendingCable: Cable | null;
    onPort: (end: CableEnd) => void;
    onConnect: (a: CableEnd, b: CableEnd) => void;
    onBundle: (key: string | null) => void;
    onCable: (cable: Cable) => void;
    onReconnect: (id: number, side: 'a' | 'b', end: CableEnd) => void;
    rack: Pick<Rack, 'height_u' | 'numbering'>;
    items: RackItem[];
    devices: RackDevice[];
    face: Face | 'both';
    selected: string | null;
    api: Ref<RackCanvasApi>;
    onZoom: (zoom: number) => void;
    onSelect: (key: string | null) => void;
    onMove: (key: string, position: number, x?: number) => void;
    onPlace: (items: RackItem[]) => void;
    onError: (message: string) => void;
    disabled: boolean;
}) {
    const host = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ width: 700, height: 650 });
    const [view, setView] = useState({ x: 0, y: 0, zoom: 1 });
    const middlePan = useRef<{
        x: number;
        y: number;
        view: { x: number; y: number };
    } | null>(null);
    const [panning, setPanning] = useState(false);
    const [dropTarget, setDropTarget] = useState<string | null>(null);
    const { resolvedAppearance } = useAppearance();
    const dark = resolvedAppearance === 'dark';
    useEffect(() => {
        const observer = new ResizeObserver(([entry]) =>
            setSize({
                width: entry.contentRect.width,
                height: entry.contentRect.height,
            }),
        );
        if (host.current) observer.observe(host.current);
        return () => observer.disconnect();
    }, []);
    const faces: Face[] = face === 'both' ? ['front', 'rear'] : [face];
    const totalWidth = FACE_WIDTH * faces.length;
    const totalHeight = rack.height_u * U + TOP * 2;
    const fit = Math.max(
        0.05,
        Math.min(
            (size.width - 24) / totalWidth,
            (size.height - 24) / totalHeight,
        ),
    );
    const scale = fit * view.zoom;
    function fitRack() {
        setView({
            x: (size.width - totalWidth * fit) / 2,
            y: (size.height - totalHeight * fit) / 2,
            zoom: 1,
        });
    }
    function zoomAt(
        factor: number,
        pointer = { x: size.width / 2, y: size.height / 2 },
    ) {
        setView((current) => {
            const zoom = Math.min(5, Math.max(0.5, current.zoom * factor));
            return {
                zoom,
                x: pointer.x - ((pointer.x - current.x) / current.zoom) * zoom,
                y: pointer.y - ((pointer.y - current.y) / current.zoom) * zoom,
            };
        });
    }
    useImperativeHandle(api, () => ({ fit: fitRack, zoom: zoomAt }));
    useLayoutEffect(() => {
        fitRack();
    }, [size.width, size.height, totalHeight, totalWidth]);
    useEffect(() => {
        onZoom(view.zoom);
    }, [view.zoom, onZoom]);

    function displayedX(item: RackItem, face: Face) {
        return (
            ((face === 'rear' ? 100 - item.x - item.width : item.x) / 100) *
            WIDTH
        );
    }
    function renderItem(
        item: RackItem,
        face: Face,
        support?: RackItem,
    ): ReactElement {
        const x = support
            ? displayedX(item, face) - displayedX(support, face)
            : LEFT;
        const visualHeight = item.kind === 'shelf' ? 1 : item.height;
        const y = support
            ? ((support.kind === 'shelf' ? 1 : 0) - visualHeight) * U
            : TOP + (rack.height_u - item.position - visualHeight) * U;
        function draggedPosition(local: { x: number; y: number }) {
            const displayed =
                ((local.x + (support ? displayedX(support, face) : 0)) /
                    WIDTH) *
                100;
            return clampDeviceDrag(
                item,
                items,
                face === 'rear' ? 100 - displayed - item.width : displayed,
                itemBounds(item, items).bottom - (local.y - y) / U,
                rack.height_u,
            );
        }
        return (
            <Group
                key={item.key}
                x={x}
                y={y}
                draggable={!disabled && !pendingCable}
                dragBoundFunc={
                    support
                        ? function (position) {
                              const transform =
                                  this.getParent()!.getAbsoluteTransform();
                              const local = transform
                                  .copy()
                                  .invert()
                                  .point(position);
                              const bounded = draggedPosition(local);
                              const left =
                                  face === 'rear'
                                      ? 100 - bounded.x - item.width
                                      : bounded.x;
                              return transform.point({
                                  x:
                                      (left / 100) * WIDTH -
                                      displayedX(support, face),
                                  y:
                                      y -
                                      (bounded.bottom -
                                          itemBounds(item, items).bottom) *
                                          U,
                              });
                          }
                        : undefined
                }
                onMouseDown={(event) => {
                    event.cancelBubble = true;
                    onSelect(item.key);
                }}
                onTouchStart={(event) => {
                    event.cancelBubble = true;
                    onSelect(item.key);
                }}
                onContextMenu={(event) => {
                    event.cancelBubble = true;
                    onSelect(item.key);
                }}
                onDragStart={(event) => {
                    event.cancelBubble = true;
                }}
                onDragMove={(event) => {
                    event.cancelBubble = true;
                    if (support) {
                        const position = draggedPosition(
                            event.target.position(),
                        );
                        const placed = dropDevice(
                            item,
                            items,
                            position.x,
                            position.bottom,
                            rack.height_u,
                            devices,
                        );
                        setDropTarget(
                            placed?.find(
                                (candidate) => candidate.key === item.key,
                            )?.shelf_key ?? null,
                        );
                    } else event.target.x(x);
                }}
                onDragEnd={(event) => {
                    event.cancelBubble = true;
                    setDropTarget(null);
                    if (support) {
                        const position = draggedPosition(
                            event.target.position(),
                        );
                        const placed = dropDevice(
                            item,
                            items,
                            position.x,
                            position.bottom,
                            rack.height_u,
                            devices,
                        );
                        event.target.position({ x, y });
                        if (placed) onPlace(placed);
                    } else {
                        const position = Math.round(
                            rack.height_u -
                                (event.target.y() - TOP) / U -
                                visualHeight,
                        );
                        event.target.position({ x, y });
                        onMove(item.key, position);
                    }
                }}
            >
                {item.kind !== 'shelf' && (
                    <ItemDrawing
                        item={item}
                        devices={devices}
                        face={face}
                        dark={dark}
                        zoom={scale}
                        onError={onError}
                    />
                )}
                {items
                    .filter((child) => child.shelf_key === item.key)
                    .map((child) => renderItem(child, face, item))}
                {item.kind === 'shelf' && (
                    <ItemDrawing
                        item={item}
                        devices={devices}
                        face={face}
                        dark={dark}
                        zoom={scale}
                        onError={onError}
                    />
                )}
                {selected === item.key && (
                    <Group listening={false}>
                        <Rect
                            x={-3 / scale}
                            y={-3 / scale}
                            width={(WIDTH * item.width) / 100 + 6 / scale}
                            height={visualHeight * U + 6 / scale}
                            cornerRadius={4 / scale}
                            stroke={dark ? '#0f172a' : '#ffffff'}
                            strokeWidth={4 / scale}
                        />
                        <Rect
                            x={-3 / scale}
                            y={-3 / scale}
                            width={(WIDTH * item.width) / 100 + 6 / scale}
                            height={visualHeight * U + 6 / scale}
                            cornerRadius={4 / scale}
                            stroke={dark ? '#60a5fa' : '#2563eb'}
                            strokeWidth={2 / scale}
                            shadowColor={dark ? '#60a5fa' : '#2563eb'}
                            shadowBlur={10 / scale}
                            shadowOpacity={dark ? 0.65 : 0.35}
                        />
                    </Group>
                )}
                {selected === item.key && !support && (
                    <Text
                        listening={false}
                        text={itemName(item, devices)}
                        x={8}
                        y={-20}
                        width={WIDTH - 16}
                        fontSize={14}
                        fill={dark ? '#93c5fd' : '#1d4ed8'}
                    />
                )}
            </Group>
        );
    }
    return (
        <div
            ref={host}
            className="h-[min(72vh,780px)] min-h-96 overflow-hidden bg-muted/30"
            style={{
                cursor: panning ? 'grabbing' : pan ? 'grab' : 'default',
                touchAction: 'none',
            }}
            onPointerDownCapture={(event) => {
                if (event.button !== 1) return;
                event.preventDefault();
                event.stopPropagation();
                middlePan.current = {
                    x: event.clientX,
                    y: event.clientY,
                    view,
                };
                setPanning(true);
                event.currentTarget.setPointerCapture(event.pointerId);
            }}
            onPointerMoveCapture={(event) => {
                const gesture = middlePan.current;
                if (!gesture) return;
                event.preventDefault();
                event.stopPropagation();
                setView((current) => ({
                    ...current,
                    x: gesture.view.x + event.clientX - gesture.x,
                    y: gesture.view.y + event.clientY - gesture.y,
                }));
            }}
            onPointerUpCapture={(event) => {
                if (!middlePan.current) return;
                event.preventDefault();
                event.stopPropagation();
                middlePan.current = null;
                setPanning(false);
                event.currentTarget.releasePointerCapture(event.pointerId);
            }}
            onLostPointerCapture={() => {
                middlePan.current = null;
                setPanning(false);
            }}
            onAuxClick={(event) => {
                if (event.button === 1) event.preventDefault();
            }}
            role="img"
            aria-label={
                faces.length === 2
                    ? 'Rack, fronte a sinistra e retro a destra'
                    : `Rack, vista ${faces[0] === 'front' ? 'frontale' : 'posteriore'}`
            }
        >
            <Stage
                width={size.width}
                height={size.height}
                scaleX={scale}
                scaleY={scale}
                x={view.x}
                y={view.y}
                draggable={pan}
                onDragEnd={(event) => {
                    if (event.target === event.target.getStage())
                        setView((current) => ({
                            ...current,
                            x: event.target.x(),
                            y: event.target.y(),
                        }));
                }}
                onWheel={(event) => {
                    event.evt.preventDefault();
                    const stage = event.target.getStage();
                    const pointer = stage?.getPointerPosition();
                    if (
                        pointer &&
                        !middlePan.current &&
                        !stage?.findOne((node: Konva.Node) => node.isDragging())
                    )
                        zoomAt(event.evt.deltaY < 0 ? 1.1 : 1 / 1.1, pointer);
                }}
                onClick={(event) => {
                    if (!pan && event.target === event.target.getStage())
                        onSelect(null);
                }}
                onTap={(event) => {
                    if (!pan && event.target === event.target.getStage())
                        onSelect(null);
                }}
            >
                <Layer listening={!pan}>
                    {faces.map((side, faceIndex) => (
                        <Group key={side} x={faceIndex * FACE_WIDTH}>
                            <Text
                                text={side === 'front' ? 'FRONTE' : 'RETRO'}
                                x={LEFT}
                                y={8}
                                width={WIDTH}
                                align="center"
                                fontSize={16}
                                fontStyle="bold"
                                fill={dark ? '#cbd5e1' : '#475569'}
                                listening={false}
                            />
                            <Group listening={false}>
                                <Rect
                                    x={LEFT - 29}
                                    y={TOP - 14}
                                    width={WIDTH + 58}
                                    height={rack.height_u * U + 28}
                                    fill={dark ? '#0f172a' : '#dbe1e8'}
                                    stroke={dark ? '#475569' : '#94a3b8'}
                                    strokeWidth={2}
                                    cornerRadius={6}
                                />
                                <Rect
                                    x={LEFT}
                                    y={TOP}
                                    width={WIDTH}
                                    height={rack.height_u * U}
                                    fill={dark ? '#111827' : '#f8fafc'}
                                />
                                {Array.from(
                                    { length: rack.height_u },
                                    (_, row) => {
                                        const y = TOP + row * U;
                                        const label = String(
                                            unitLabel(
                                                rack.height_u - row - 1,
                                                rack,
                                            ),
                                        );
                                        const labelSize = Math.min(
                                            36,
                                            Math.max(16, 11 / scale),
                                        );
                                        return (
                                            <Group key={row}>
                                                <Line
                                                    points={[
                                                        LEFT,
                                                        y,
                                                        LEFT + WIDTH,
                                                        y,
                                                    ]}
                                                    stroke={
                                                        dark
                                                            ? '#334155'
                                                            : '#cbd5e1'
                                                    }
                                                    dash={[4, 4]}
                                                />
                                                <Text
                                                    text={label}
                                                    x={2}
                                                    y={
                                                        y +
                                                        U / 2 -
                                                        labelSize / 2
                                                    }
                                                    width={LEFT - 42}
                                                    align="right"
                                                    fontSize={labelSize}
                                                    fill={
                                                        dark
                                                            ? '#cbd5e1'
                                                            : '#475569'
                                                    }
                                                />
                                                <Text
                                                    text={label}
                                                    x={LEFT + WIDTH + 36}
                                                    y={
                                                        y +
                                                        U / 2 -
                                                        labelSize / 2
                                                    }
                                                    width={38}
                                                    fontSize={labelSize}
                                                    fill={
                                                        dark
                                                            ? '#cbd5e1'
                                                            : '#475569'
                                                    }
                                                />
                                            </Group>
                                        );
                                    },
                                )}
                            </Group>
                            {items
                                .filter((item) => !item.shelf_key)
                                .map((item) => renderItem(item, side))}
                            {items
                                .filter((item) => item.key === dropTarget)
                                .map((item) => (
                                    <Line
                                        key={`drop-${item.key}`}
                                        points={[
                                            LEFT + displayedX(item, side),
                                            TOP +
                                                (rack.height_u -
                                                    itemBounds(item, items)
                                                        .top) *
                                                    U,
                                            LEFT +
                                                displayedX(item, side) +
                                                (item.width / 100) * WIDTH,
                                            TOP +
                                                (rack.height_u -
                                                    itemBounds(item, items)
                                                        .top) *
                                                    U,
                                        ]}
                                        stroke={dark ? '#93c5fd' : '#1d4ed8'}
                                        strokeWidth={2 / scale}
                                        listening={false}
                                    />
                                ))}
                        </Group>
                    ))}
                    <RackCables
                        items={items}
                        devices={devices}
                        height={rack.height_u}
                        faces={faces}
                        scale={scale}
                        zoom={view.zoom}
                        dark={dark}
                        cables={cables}
                        allCables={allCables}
                        focusedBundle={focusedBundle}
                        selectedCable={selectedCable}
                        pendingCable={pendingCable}
                        editing={!disabled}
                        onPort={onPort}
                        onConnect={onConnect}
                        onBundle={onBundle}
                        onCable={onCable}
                        onReconnect={onReconnect}
                    />
                </Layer>
            </Stage>
        </div>
    );
}
