import Konva from 'konva';
import type { KonvaEventObject } from 'konva/lib/Node';
import {
    useEffect,
    useImperativeHandle,
    useLayoutEffect,
    useRef,
    useState,
    type Ref,
} from 'react';
import {
    Group,
    Image as CanvasImage,
    Layer,
    Line,
    Rect,
    Stage,
    Text,
    Transformer,
} from 'react-konva';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
    TOOLTIP_DELAY,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/hooks/use-appearance';
import {
    advancePointer,
    boundsOf,
    imageBounds,
    duplicatePorts,
    portBounds,
    portLabelLayout,
    snapDelta,
    resizeBlockSpacing,
    resizePortGroup,
    reshapeBlock,
    type BlockOptions,
    type BlockEdge,
    type BlockCorner,
    type Bounds,
    type Point,
    type PointerMotion,
    type Port,
} from '@/lib/hardware-editor/document';
import { PortSymbol } from './port-symbol';
import { PanelScene } from './panel-scene';
import type { Editor, Selection } from './use-editor';

const blockHandles = [
    ['left', 0, 0.5, 'ew-resize'],
    ['right', 1, 0.5, 'ew-resize'],
    ['top', 0.5, 0, 'ns-resize'],
    ['bottom', 0.5, 1, 'ns-resize'],
    ['top-left', 0, 0, 'nwse-resize'],
    ['top-right', 1, 0, 'nesw-resize'],
    ['bottom-left', 0, 1, 'nesw-resize'],
    ['bottom-right', 1, 1, 'nwse-resize'],
] as const;

export type CanvasApi = {
    fit: () => void;
    zoom: (factor: number) => void;
    center: () => Point;
};
type Gesture = {
    start: Point;
    screen: Point;
    motion: PointerMotion;
    clickSelection?: string[];
    selection: string[];
    moving: Port[];
    copy: boolean;
    duplicated: boolean;
    moved: boolean;
    mode:
        | 'pan'
        | 'ports'
        | 'marquee'
        | 'image'
        | 'crop'
        | 'block-spacing'
        | 'group-scale';
    groupCorner?: BlockCorner;
    block?: BlockOptions;
    blockEdge?: BlockEdge | BlockCorner;
    crop?: NonNullable<Editor['crop']>;
    handle?: string;
    additive: boolean;
    originalView: Point;
};
export function EditorCanvas({
    editor: e,
    pan,
    snap,
    preview,
    blockPreview,
    blockOptions,
    onChangeBlock,
    fitKey,
    onContext,
    onEditLabel,
    onImageSelect,
    onZoom,
    api,
}: {
    editor: Editor;
    pan: boolean;
    snap: boolean;
    preview?: Map<string, string>;
    blockPreview?: Port[];
    blockOptions: BlockOptions | null;
    onChangeBlock: (options: BlockOptions) => void;
    fitKey: number;
    onContext: (point: Point, selection: Selection) => void;
    onEditLabel: () => void;
    onImageSelect: () => void;
    onZoom: (zoom: number) => void;
    api: Ref<CanvasApi>;
}) {
    const host = useRef<HTMLDivElement>(null);
    const stage = useRef<Konva.Stage>(null);
    const transformer = useRef<Konva.Transformer>(null);
    const [size, setSize] = useState({ width: 0, height: 0 });
    const hasSize = size.width > 0 && size.height > 0;
    const [view, setView] = useState({ x: 40, y: 100, zoom: 1 });
    const viewRef = useRef(view);
    viewRef.current = view;
    const [space, setSpace] = useState(false);
    const [hover, setHover] = useState<string | null>(null);
    const [labelTooltip, setLabelTooltip] = useState<string | null>(null);
    const [draft, setDraft] = useState<Port[] | null>(null);
    const [imageDelta, setImageDelta] = useState<Point | null>(null);
    const [marquee, setMarquee] = useState<Bounds | null>(null);
    const [guides, setGuides] = useState<{ axis: 'x' | 'y'; value: number }[]>(
        [],
    );
    const gesture = useRef<Gesture | null>(null);
    const anchorMotion = useRef<PointerMotion | null>(null);
    const blockMotion = useRef<PointerMotion | null>(null);
    const middlePan = useRef<{ x: number; y: number; view: Point } | null>(
        null,
    );
    const [panning, setPanning] = useState(false);
    const [blockDragging, setBlockDragging] = useState(false);
    const draftRef = useRef<Port[] | null>(null);
    const delta = useRef<Point>({ x: 0, y: 0 });
    const { resolvedAppearance } = useAppearance();
    const dark = resolvedAppearance === 'dark';
    const { shell } = e.doc;
    const image = e.doc.faces[e.face].image;
    const ports = draft ?? e.ports;
    const selected = ports.filter(
        (p) => !p.hidden && e.selection.ids.includes(p.id),
    );
    const selectedBox = selected.length
        ? boundsOf(selected.map(portBounds))
        : null;
    const blocked = !!e.session || !!preview || !!e.crop || !!blockPreview;
    const canResizeGroup =
        !!e.group &&
        selected.length > 1 &&
        !blocked &&
        !e.loading &&
        !pan &&
        !space &&
        !e.selected.some((p) => p.hidden || e.portLocked(p));
    const blockBox = blockPreview
        ? boundsOf(blockPreview.map(portBounds))
        : null;

    const labelPort = (blockPreview ?? ports).find(
        (p) => p.id === hover && !p.hidden,
    );
    const fullLabel = labelPort
        ? (preview?.get(labelPort.id) ?? labelPort.label)
        : '';
    const tooltipKey =
        labelPort &&
        fullLabel &&
        (labelPort.labelPosition !== 'hidden' || preview?.has(labelPort.id)) &&
        portLabelLayout(labelPort, fullLabel).truncated &&
        !pan &&
        !space &&
        !panning &&
        !blockDragging &&
        !e.crop
            ? `${e.face}:${labelPort.id}:${fullLabel}`
            : null;
    useEffect(() => {
        setLabelTooltip(null);
        if (!tooltipKey) return;
        const timer = window.setTimeout(
            () => setLabelTooltip(tooltipKey),
            TOOLTIP_DELAY,
        );
        return () => window.clearTimeout(timer);
    }, [tooltipKey, view]);

    function fit() {
        const boxes: Bounds[] = [
            shell,
            ...e.ports.filter((p) => !p.hidden).map(portBounds),
            ...(blockPreview ?? []).map(portBounds),
        ];
        if (e.crop)
            boxes.push({
                x: e.crop.image.x,
                y: e.crop.image.y,
                width: e.crop.image.width * e.crop.image.scale,
                height: e.crop.image.height * e.crop.image.scale,
            });
        else if (image && !image.hidden) boxes.push(imageBounds(image));
        const b = boundsOf(boxes);
        const zoom = Math.max(
            0.1,
            Math.min(
                2.5,
                (size.width - 100) / (b.width + 40),
                (size.height - 140) / (b.height + 40),
            ),
        );
        setView({
            zoom,
            x: (size.width - b.width * zoom) / 2 - b.x * zoom,
            y: (size.height - b.height * zoom) / 2 - b.y * zoom,
        });
    }
    function zoomAt(
        factor: number,
        pointer = { x: size.width / 2, y: size.height / 2 },
    ) {
        setView((v) => {
            const zoom = Math.min(6, Math.max(0.1, v.zoom * factor));
            return {
                zoom,
                x: pointer.x - ((pointer.x - v.x) / v.zoom) * zoom,
                y: pointer.y - ((pointer.y - v.y) / v.zoom) * zoom,
            };
        });
    }
    useImperativeHandle(api, () => ({
        fit,
        zoom: zoomAt,
        center: () => ({
            x: (size.width / 2 - view.x) / view.zoom,
            y: (size.height / 2 - view.y) / view.zoom,
        }),
    }));
    useEffect(() => {
        const observer = new ResizeObserver((entries) => {
            const { width, height } = entries[0].contentRect;
            setSize({ width, height });
        });
        if (host.current) observer.observe(host.current);
        return () => observer.disconnect();
    }, []);
    useEffect(() => {
        if (hasSize) fit();
    }, [hasSize, fitKey, e.loading]);
    useEffect(() => {
        onZoom(view.zoom);
    }, [view.zoom, onZoom]);
    useEffect(() => {
        const down = (event: KeyboardEvent) => {
            if (
                (event.target as HTMLElement).closest(
                    'input,textarea,select,[contenteditable="true"],[role="dialog"],[role="menu"]',
                )
            )
                return;
            if (
                event.code === 'Space' &&
                host.current?.contains(event.target as Node)
            ) {
                event.preventDefault();
                setSpace(true);
            }
        };
        const up = (event: KeyboardEvent) => {
            if (event.code === 'Space') setSpace(false);
        };
        const reset = () => {
            setHover(null);
            setSpace(false);
            middlePan.current = null;
            setPanning(false);
            setBlockDragging(false);
            gesture.current = null;
            anchorMotion.current = null;
            blockMotion.current = null;
            setDraft(null);
            setImageDelta(null);
            setMarquee(null);
            setGuides([]);
        };
        window.addEventListener('keydown', down);
        window.addEventListener('keyup', up);
        window.addEventListener('blur', reset);
        return () => {
            window.removeEventListener('keydown', down);
            window.removeEventListener('keyup', up);
            window.removeEventListener('blur', reset);
        };
    }, []);
    useLayoutEffect(() => {
        const tr = transformer.current;
        if (!tr || !stage.current) return;
        let node: Konva.Node | undefined;
        if (!blocked && !pan && !space && !gesture.current) {
            if (
                e.selection.kind === 'ports' &&
                e.selected.length === 1 &&
                selected.length === 1 &&
                !e.portLocked(selected[0])
            )
                node = stage.current
                    .findOne<Konva.Group>(`#${selected[0].id}`)
                    ?.findOne('.symbol');
            if (
                e.selection.kind === 'shell' &&
                !e.shellState.locked &&
                !e.shellState.hidden
            )
                node = stage.current.findOne('#shell');
            if (
                e.selection.kind === 'image' &&
                !image?.locked &&
                !image?.hidden
            )
                node = stage.current.findOne('#panel-image');
        }
        tr.nodes(node ? [node] : []);
    });
    function point(): Point {
        const p = stage.current!.getPointerPosition()!;
        return {
            x: (p.x - viewRef.current.x) / viewRef.current.zoom,
            y: (p.y - viewRef.current.y) / viewRef.current.zoom,
        };
    }
    function pick(target: Konva.Node): Selection {
        const port = target.findAncestor('.port', true);
        if (port) {
            const p = e.ports.find((p) => p.id === port.id());
            if (p)
                return {
                    kind: 'ports',
                    ids:
                        p.group && p.group !== e.enteredGroup
                            ? e.ports
                                  .filter((item) => item.group === p.group)
                                  .map((item) => item.id)
                            : [p.id],
                };
        }
        if (['panel-image', 'image-selection-area'].includes(target.id()))
            return { kind: 'image', ids: [] };
        if (target.id() === 'shell') return { kind: 'shell', ids: [] };
        return { kind: 'canvas', ids: [] };
    }
    function down(event: KonvaEventObject<PointerEvent>) {
        if (
            event.evt.button !== 0 ||
            event.target.findAncestor('Transformer', true)
        )
            return;
        host.current?.focus({ preventScroll: true });
        const blockEdge = blockHandles.find(
            ([edge]) => event.target.name() === `block-${edge}`,
        )?.[0];
        const groupCorner = canResizeGroup
            ? (blockHandles.find(
                  ([edge, x, y]) =>
                      x !== 0.5 &&
                      y !== 0.5 &&
                      event.target.name() === `group-${edge}`,
              )?.[0] as BlockCorner | undefined)
            : undefined;
        if (blockPreview && !pan && !space && !blockEdge) return;
        const hit = pick(event.target);
        if (
            e.crop &&
            !space &&
            !event.target.findAncestor('.crop-editor', true)
        )
            return;
        if (e.session && !pan && !space) {
            const p = event.target.findAncestor('.port', true);
            if (p) e.numberPort(p.id());
            return;
        }
        if (preview && !pan && !space) return;
        const screen = stage.current!.getPointerPosition()!;
        const start = point();
        const additive =
            event.evt.ctrlKey || event.evt.metaKey || event.evt.shiftKey;
        const panning = (!e.crop && pan) || space;
        let ids = groupCorner ? e.selection.ids : hit.ids;
        let clickSelection: string[] | undefined;
        if (
            !groupCorner &&
            !panning &&
            !e.crop &&
            !blockOptions &&
            hit.kind === 'ports'
        ) {
            if (additive) {
                const alreadySelected = hit.ids.every((id) =>
                    e.selection.ids.includes(id),
                );
                ids = alreadySelected
                    ? e.selection.ids.filter((id) => !hit.ids.includes(id))
                    : [...new Set([...e.selection.ids, ...hit.ids])];
                // Ctrl+click still toggles selection; Ctrl+drag keeps the selected items moving.
                if (event.evt.ctrlKey && alreadySelected) {
                    clickSelection = ids;
                    ids = e.selection.ids;
                }
            } else if (hit.ids.some((id) => e.selection.ids.includes(id)))
                ids = e.selection.ids;
            e.setSelection({ kind: 'ports', ids });
        } else if (!groupCorner && !panning && !e.crop && !blockOptions)
            e.setSelection(hit);
        const mode = panning
            ? 'pan'
            : groupCorner
              ? 'group-scale'
              : blockEdge && blockOptions
                ? 'block-spacing'
                : e.crop
                  ? 'crop'
                  : hit.kind === 'ports'
                    ? 'ports'
                    : hit.kind === 'image' && !image?.locked
                      ? 'image'
                      : 'marquee';
        gesture.current = {
            start,
            screen,
            motion: { pointer: screen, position: screen },
            clickSelection,
            selection: additive ? e.selection.ids : [],
            moving: e.ports.filter((p) => ids.includes(p.id)).some(e.portLocked)
                ? []
                : e.ports.filter((p) => ids.includes(p.id)),
            crop: e.crop ?? undefined,
            block: blockOptions ?? undefined,
            blockEdge,
            groupCorner,
            handle: event.target.name(),
            copy: mode === 'ports' && event.evt.altKey,
            duplicated: false,
            moved: false,
            mode,
            additive,
            originalView: { x: view.x, y: view.y },
        };
        delta.current = { x: 0, y: 0 };
        draftRef.current = null;
        (
            stage.current?.container().firstElementChild as HTMLElement | null
        )?.setPointerCapture(event.evt.pointerId);
    }
    function move(event: KonvaEventObject<PointerEvent>) {
        const g = gesture.current;
        if (!g) {
            const p =
                event.evt.buttons === 0
                    ? event.target.findAncestor('.port', true)
                    : undefined;
            setHover(p?.id() ?? null);
            return;
        }
        const screen = stage.current!.getPointerPosition()!;
        if (g.mode === 'pan') {
            setView((v) => ({
                ...v,
                x: g.originalView.x + screen.x - g.screen.x,
                y: g.originalView.y + screen.y - g.screen.y,
            }));
            return;
        }
        g.motion = advancePointer(
            g.motion,
            screen,
            event.evt.ctrlKey && g.mode !== 'marquee',
        );
        let d = {
            x: (g.motion.position.x - g.screen.x) / view.zoom,
            y: (g.motion.position.y - g.screen.y) / view.zoom,
        };
        if (
            !g.moved &&
            Math.hypot(screen.x - g.screen.x, screen.y - g.screen.y) < 3
        )
            return;
        g.moved = true;
        if (g.mode === 'group-scale' && g.groupCorner) {
            const resized = resizePortGroup(g.moving, g.groupCorner, d);
            draftRef.current = e.ports.map(
                (port) => resized.find((p) => p.id === port.id) ?? port,
            );
            setDraft(draftRef.current);
            return;
        }
        if (g.mode === 'block-spacing' && g.block && g.blockEdge) {
            onChangeBlock(
                g.blockEdge === 'left' ||
                    g.blockEdge === 'right' ||
                    g.blockEdge === 'top' ||
                    g.blockEdge === 'bottom'
                    ? resizeBlockSpacing(
                          g.block,
                          g.blockEdge,
                          g.blockEdge === 'left' || g.blockEdge === 'right'
                              ? d.x
                              : d.y,
                      )
                    : reshapeBlock(g.block, g.blockEdge, d),
            );
            return;
        }
        if (g.mode === 'crop' && g.crop) {
            const { image: original, frame } = g.crop;
            const w = original.width * original.scale,
                h = original.height * original.scale;
            const clamp = (n: number, min: number, max: number) =>
                Math.max(min, Math.min(max, n));
            if (g.handle?.startsWith('crop-')) {
                let left = frame.x,
                    top = frame.y,
                    right = frame.x + frame.width,
                    bottom = frame.y + frame.height;
                if (g.handle.slice(5).includes('w'))
                    left = clamp(
                        frame.x + d.x,
                        original.x,
                        right - original.scale,
                    );
                if (g.handle.slice(5).includes('e'))
                    right = clamp(
                        right + d.x,
                        left + original.scale,
                        original.x + w,
                    );
                if (g.handle.slice(5).includes('n'))
                    top = clamp(
                        frame.y + d.y,
                        original.y,
                        bottom - original.scale,
                    );
                if (g.handle.slice(5).includes('s'))
                    bottom = clamp(
                        bottom + d.y,
                        top + original.scale,
                        original.y + h,
                    );
                e.setCrop({
                    image: original,
                    frame: {
                        x: left,
                        y: top,
                        width: right - left,
                        height: bottom - top,
                    },
                });
            } else {
                e.setCrop({
                    frame,
                    image: {
                        ...original,
                        x: clamp(
                            original.x + d.x,
                            frame.x + frame.width - w,
                            frame.x,
                        ),
                        y: clamp(
                            original.y + d.y,
                            frame.y + frame.height - h,
                            frame.y,
                        ),
                    },
                });
            }
            return;
        }
        if (g.mode === 'ports' && g.moving.length) {
            if (g.copy && !g.duplicated) {
                g.duplicated = true;
                g.moving = duplicatePorts(g.moving);
                e.setSelection({
                    kind: 'ports',
                    ids: g.moving.map((p) => p.id),
                });
            }
            const stationary = e.ports.filter(
                (p) => !g.moving.some((m) => m.id === p.id),
            );
            if (snap && !event.evt.ctrlKey) {
                const snapped = snapDelta(
                    g.moving,
                    stationary.filter((p) => !p.hidden),
                    shell,
                    d,
                    view.zoom,
                );
                d = snapped.delta;
                setGuides(snapped.guides);
            } else setGuides([]);
            const movedPorts = g.moving.map((p) => ({
                ...p,
                x: p.x + d.x,
                y: p.y + d.y,
            }));
            draftRef.current = g.duplicated
                ? [...e.ports, ...movedPorts]
                : e.ports.map(
                      (p) => movedPorts.find((m) => m.id === p.id) ?? p,
                  );
            setDraft(draftRef.current);
        } else if (g.mode === 'image') setImageDelta(d);
        else if (g.mode === 'marquee') {
            const p = { x: g.start.x + d.x, y: g.start.y + d.y };
            setMarquee({
                x: Math.min(g.start.x, p.x),
                y: Math.min(g.start.y, p.y),
                width: Math.abs(d.x),
                height: Math.abs(d.y),
            });
        }
        delta.current = d;
    }
    function up() {
        const g = gesture.current;
        if (!g) return;
        const moved = g.moved;
        if (!moved && g.clickSelection)
            e.setSelection({ kind: 'ports', ids: g.clickSelection });
        if (!moved && !blocked && g.mode !== 'pan') e.setPastePoint(g.start);
        if (
            !moved &&
            !blocked &&
            g.mode !== 'pan' &&
            e.selection.kind === 'image'
        )
            onImageSelect();
        if (
            (g.mode === 'block-spacing' ||
                g.mode === 'group-scale' ||
                g.mode === 'crop') &&
            stage.current
        )
            stage.current.container().style.cursor = '';
        if (
            moved &&
            (g.mode === 'ports' || g.mode === 'group-scale') &&
            draftRef.current
        )
            e.setPortList(draftRef.current);
        if (moved && g.mode === 'image' && image)
            e.patchImage({
                x: image.x + delta.current.x,
                y: image.y + delta.current.y,
            });
        if (moved && g.mode === 'marquee' && marquee) {
            const ids = new Set(g.additive ? g.selection : []);
            for (const p of e.ports) {
                if (p.hidden) continue;
                const b = portBounds(p);
                if (
                    b.x < marquee.x + marquee.width &&
                    b.x + b.width > marquee.x &&
                    b.y < marquee.y + marquee.height &&
                    b.y + b.height > marquee.y
                ) {
                    if (p.group && p.group !== e.enteredGroup)
                        e.ports
                            .filter((item) => item.group === p.group)
                            .forEach((item) => ids.add(item.id));
                    else ids.add(p.id);
                }
            }
            e.setSelection({ kind: 'ports', ids: [...ids] });
        }
        gesture.current = null;
        setDraft(null);
        setImageDelta(null);
        setMarquee(null);
        setGuides([]);
    }
    function transformEnd() {
        const node = transformer.current?.nodes()[0];
        if (!node) return;
        if (e.selection.kind === 'ports' && e.selected.length === 1) {
            const p = e.selected[0];
            const rotation =
                (((Math.round(node.rotation() / 90) * 90) % 360) + 360) % 360;
            const scale = Math.max(0.2, Math.min(8, node.scaleX()));
            const offset = { x: node.x(), y: node.y() };
            node.position({ x: 0, y: 0 });
            node.scale({ x: p.scale, y: p.scale });
            node.rotation(p.rotation);
            e.patchPorts({
                scale,
                rotation,
                x: p.x + offset.x,
                y: p.y + offset.y,
            });
        } else if (e.selection.kind === 'shell') {
            const width = shell.width * node.scaleX();
            const height = shell.height * node.scaleY();
            const x = node.x(),
                y = node.y();
            node.scale({ x: 1, y: 1 });
            e.commit({ ...e.doc, shell: { ...shell, x, y, width, height } });
        } else if (image) {
            const scale = Math.max(
                0.001,
                Math.min(8, image.scale * node.scaleX()),
            );
            const x = node.x(),
                y = node.y();
            node.scale({ x: 1, y: 1 });
            const crop = image.crop;
            e.patchImage({
                scale,
                x: x - crop.x * scale,
                y: y - crop.y * scale,
            });
        }
    }
    const hovered = ports.find(
        (p) => p.id === hover && !p.hidden && !e.selection.ids.includes(p.id),
    );
    const frame = hovered ? portBounds(hovered) : null;
    return (
        <div
            ref={host}
            onPointerLeave={() => setHover(null)}
            onPointerDownCapture={(event) => {
                setHover(null);
                setLabelTooltip(null);
                if (event.button !== 1) return;
                event.preventDefault();
                event.stopPropagation();
                middlePan.current = {
                    x: event.clientX,
                    y: event.clientY,
                    view: viewRef.current,
                };
                setPanning(true);
                event.currentTarget.setPointerCapture(event.pointerId);
            }}
            onPointerMoveCapture={(event) => {
                const g = middlePan.current;
                if (!g) return;
                event.preventDefault();
                event.stopPropagation();
                setView((v) => ({
                    ...v,
                    x: g.view.x + event.clientX - g.x,
                    y: g.view.y + event.clientY - g.y,
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
            tabIndex={0}
            role="region"
            aria-label="Canvas del pannello. Seleziona una porta o trascina per selezionare. Spazio per spostare la vista."
            className="relative h-full min-h-80 w-full touch-none overflow-hidden bg-muted/40 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
            style={{
                cursor: panning
                    ? 'grabbing'
                    : space
                      ? 'grab'
                      : e.crop
                        ? 'move'
                        : pan
                          ? 'grab'
                          : e.session
                            ? 'crosshair'
                            : 'default',
                backgroundImage:
                    'radial-gradient(var(--border) 1px, transparent 1px)',
                backgroundSize: '20px 20px',
            }}
        >
            <Stage
                ref={stage}
                width={size.width}
                height={size.height}
                x={view.x}
                y={view.y}
                scaleX={view.zoom}
                scaleY={view.zoom}
                onPointerDown={down}
                onPointerMove={move}
                onPointerUp={up}
                onPointerCancel={() => {
                    gesture.current = null;
                    anchorMotion.current = null;
                    blockMotion.current = null;
                    if (stage.current)
                        stage.current.container().style.cursor = '';
                    setBlockDragging(false);
                    setDraft(null);
                    setMarquee(null);
                    setImageDelta(null);
                    setGuides([]);
                }}
                onWheel={(event) => {
                    setHover(null);
                    event.evt.preventDefault();
                    if (
                        !gesture.current &&
                        !middlePan.current &&
                        !stage.current?.findOne('.block-preview')?.isDragging()
                    )
                        zoomAt(
                            event.evt.deltaY < 0 ? 1.1 : 1 / 1.1,
                            stage.current!.getPointerPosition()!,
                        );
                }}
                onContextMenu={(event) => {
                    setHover(null);
                    event.evt.preventDefault();
                    if (blocked) return;
                    const hit = pick(event.target);
                    const selection =
                        hit.kind === 'ports' &&
                        hit.ids.some((id) => e.selection.ids.includes(id))
                            ? e.selection
                            : hit;
                    e.setSelection(selection);
                    onContext(point(), selection);
                }}
                onDblClick={(event) => {
                    if (blocked) return;
                    const port = event.target.findAncestor('.port', true);
                    if (port) {
                        e.setSelection({ kind: 'ports', ids: [port.id()] });
                        onEditLabel();
                    }
                }}
                onDblTap={(event) => {
                    if (blocked) return;
                    const port = event.target.findAncestor('.port', true);
                    if (port) {
                        e.setSelection({ kind: 'ports', ids: [port.id()] });
                        onEditLabel();
                    }
                }}
            >
                <Layer>
                    <Text
                        listening={false}
                        x={shell.x}
                        y={shell.y - 28 / view.zoom}
                        text={e.face === 'front' ? 'Frontale' : 'Retro'}
                        fontFamily="Instrument Sans, sans-serif"
                        fontSize={12 / view.zoom}
                        fill={dark ? '#a3a3a3' : '#737373'}
                    />
                    {/* Keep the clipped image draggable outside the shell, behind other objects. */}
                    {image &&
                        e.selection.kind === 'image' &&
                        !image.hidden &&
                        !image.locked &&
                        !blocked && (
                            <Rect
                                id="image-selection-area"
                                {...imageBounds(image)}
                                x={imageBounds(image).x + (imageDelta?.x ?? 0)}
                                y={imageBounds(image).y + (imageDelta?.y ?? 0)}
                                fill="transparent"
                                onMouseEnter={() => {
                                    if (!pan && !space && stage.current)
                                        stage.current.container().style.cursor =
                                            'move';
                                }}
                                onMouseLeave={() => {
                                    if (stage.current)
                                        stage.current.container().style.cursor =
                                            '';
                                }}
                            />
                        )}
                    <PanelScene
                        selectLockedImage
                        shell={shell}
                        face={{
                            ...e.doc.faces[e.face],
                            ports: ports.map((port) =>
                                e.selection.ids.includes(port.id)
                                    ? {
                                          ...port,
                                          opacity:
                                              e.opacityPreview ?? port.opacity,
                                          borderColor:
                                              e.borderColorPreview ??
                                              port.borderColor,
                                      }
                                    : port,
                            ),
                        }}
                        images={e.images}
                        dark={dark}
                        zoom={view.zoom}
                        hideImage={!!e.crop}
                        imageDelta={imageDelta}
                        imageOpacity={
                            e.selection.kind === 'image'
                                ? (e.opacityPreview ?? image?.opacity)
                                : image?.opacity
                        }
                        preview={preview}
                    />
                    {blockPreview && blockOptions && blockBox && (
                        <Group
                            name="block-preview"
                            draggable={!pan && !space}
                            onDragStart={() => {
                                blockMotion.current = {
                                    pointer: { x: 0, y: 0 },
                                    position: { x: 0, y: 0 },
                                };
                                setBlockDragging(true);
                            }}
                            onDragMove={(event) => {
                                const node = event.currentTarget;
                                if (blockMotion.current) {
                                    blockMotion.current = advancePointer(
                                        blockMotion.current,
                                        node.position(),
                                        event.evt.ctrlKey,
                                    );
                                    node.position(blockMotion.current.position);
                                }
                                if (!snap || event.evt.ctrlKey) {
                                    setGuides([]);
                                    return;
                                }
                                const snapped = snapDelta(
                                    blockPreview,
                                    e.ports.filter((p) => !p.hidden),
                                    shell,
                                    node.position(),
                                    view.zoom,
                                );
                                node.position(snapped.delta);
                                setGuides(snapped.guides);
                            }}
                            onDragEnd={(event) => {
                                const node = event.currentTarget;
                                onChangeBlock({
                                    ...blockOptions,
                                    x: blockOptions.x + node.x(),
                                    y: blockOptions.y + node.y(),
                                });
                                node.position({ x: 0, y: 0 });
                                blockMotion.current = null;
                                setBlockDragging(false);
                                setGuides([]);
                            }}
                            onMouseEnter={() => {
                                if (
                                    !pan &&
                                    !space &&
                                    stage.current &&
                                    gesture.current?.mode !== 'block-spacing'
                                )
                                    stage.current.container().style.cursor =
                                        'move';
                            }}
                            onMouseLeave={() => {
                                if (
                                    stage.current &&
                                    gesture.current?.mode !== 'block-spacing'
                                )
                                    stage.current.container().style.cursor = '';
                            }}
                        >
                            <Rect
                                {...blockBox}
                                fill="transparent"
                                stroke="#3b82f6"
                                strokeWidth={1 / view.zoom}
                                dash={[5 / view.zoom, 3 / view.zoom]}
                            />
                            {blockPreview.map((port) => (
                                <PortSymbol
                                    key={port.id}
                                    port={port}
                                    selected={false}
                                    zoom={view.zoom}
                                />
                            ))}
                        </Group>
                    )}
                    {blockPreview &&
                        blockBox &&
                        !blockDragging &&
                        !pan &&
                        !space &&
                        blockHandles.map(([edge, x, y, cursor]) => {
                            const horizontal =
                                edge === 'left' || edge === 'right';
                            const corner = x !== 0.5 && y !== 0.5;
                            if (
                                blockPreview.length < 2 ||
                                (!corner &&
                                    new Set(
                                        blockPreview.map((port) =>
                                            horizontal ? port.x : port.y,
                                        ),
                                    ).size < 2)
                            )
                                return null;
                            return (
                                <Rect
                                    key={edge}
                                    name={`block-${edge}`}
                                    x={
                                        blockBox.x +
                                        blockBox.width * x -
                                        5 / view.zoom
                                    }
                                    y={
                                        blockBox.y +
                                        blockBox.height * y -
                                        5 / view.zoom
                                    }
                                    width={10 / view.zoom}
                                    height={10 / view.zoom}
                                    fill={dark ? '#17202c' : '#fff'}
                                    stroke="#3b82f6"
                                    strokeWidth={1 / view.zoom}
                                    hitStrokeWidth={14 / view.zoom}
                                    cornerRadius={2 / view.zoom}
                                    onMouseEnter={() => {
                                        if (stage.current)
                                            stage.current.container().style.cursor =
                                                cursor;
                                    }}
                                    onMouseLeave={() => {
                                        if (
                                            stage.current &&
                                            gesture.current?.mode !==
                                                'block-spacing'
                                        )
                                            stage.current.container().style.cursor =
                                                '';
                                    }}
                                />
                            );
                        })}
                    {ports
                        .filter((p) => e.selection.ids.includes(p.id))
                        .map((p) => (
                            <Rect
                                key={`selection-${p.id}`}
                                dash={
                                    p.hidden
                                        ? [5 / view.zoom, 4 / view.zoom]
                                        : undefined
                                }
                                x={portBounds(p).x - 6 / view.zoom}
                                y={portBounds(p).y - 6 / view.zoom}
                                width={portBounds(p).width + 12 / view.zoom}
                                height={portBounds(p).height + 12 / view.zoom}
                                listening={false}
                                stroke="#3b82f6"
                                strokeWidth={1.5 / view.zoom}
                                cornerRadius={2}
                            />
                        ))}
                    {e.selection.kind === 'shell' &&
                        (e.shellState.hidden ||
                            e.shellState.locked ||
                            blocked ||
                            pan ||
                            space) && (
                            <Rect
                                x={shell.x}
                                y={shell.y}
                                width={shell.width}
                                height={shell.height}
                                cornerRadius={shell.radius}
                                listening={false}
                                stroke="#3b82f6"
                                strokeWidth={1.5 / view.zoom}
                                dash={
                                    e.shellState.hidden
                                        ? [5 / view.zoom, 4 / view.zoom]
                                        : undefined
                                }
                            />
                        )}
                    {image && e.selection.kind === 'image' && !e.crop && (
                        <Rect
                            {...imageBounds(image)}
                            x={imageBounds(image).x + (imageDelta?.x ?? 0)}
                            y={imageBounds(image).y + (imageDelta?.y ?? 0)}
                            listening={false}
                            stroke="#3b82f6"
                            strokeWidth={1 / view.zoom}
                            dash={[5 / view.zoom, 4 / view.zoom]}
                        />
                    )}
                    {e.crop &&
                        (() => {
                            const { image: original, frame: f } = e.crop;
                            const crop = {
                                x: (f.x - original.x) / original.scale,
                                y: (f.y - original.y) / original.scale,
                                width: f.width / original.scale,
                                height: f.height / original.scale,
                            };
                            return (
                                <Group name="crop-editor">
                                    <CanvasImage
                                        image={e.images[original.assetId]}
                                        x={original.x}
                                        y={original.y}
                                        width={original.width * original.scale}
                                        height={
                                            original.height * original.scale
                                        }
                                        opacity={0.3}
                                    />
                                    <CanvasImage
                                        image={e.images[original.assetId]}
                                        {...f}
                                        crop={crop}
                                    />
                                    <Rect
                                        {...f}
                                        stroke="#3b82f6"
                                        strokeWidth={1.5 / view.zoom}
                                        listening={false}
                                    />
                                    {[1 / 3, 2 / 3].map((r) => (
                                        <Group key={r} listening={false}>
                                            <Line
                                                points={[
                                                    f.x + f.width * r,
                                                    f.y,
                                                    f.x + f.width * r,
                                                    f.y + f.height,
                                                ]}
                                                stroke="#fff"
                                                opacity={0.6}
                                                strokeWidth={1 / view.zoom}
                                            />
                                            <Line
                                                points={[
                                                    f.x,
                                                    f.y + f.height * r,
                                                    f.x + f.width,
                                                    f.y + f.height * r,
                                                ]}
                                                stroke="#fff"
                                                opacity={0.6}
                                                strokeWidth={1 / view.zoom}
                                            />
                                        </Group>
                                    ))}
                                    {(
                                        [
                                            ['nw', 0, 0, 'nwse-resize'],
                                            ['n', 0.5, 0, 'ns-resize'],
                                            ['ne', 1, 0, 'nesw-resize'],
                                            ['e', 1, 0.5, 'ew-resize'],
                                            ['se', 1, 1, 'nwse-resize'],
                                            ['s', 0.5, 1, 'ns-resize'],
                                            ['sw', 0, 1, 'nesw-resize'],
                                            ['w', 0, 0.5, 'ew-resize'],
                                        ] as const
                                    ).map(([name, x, y, cursor]) => (
                                        <Rect
                                            key={name}
                                            name={`crop-${name}`}
                                            x={
                                                f.x +
                                                f.width * x -
                                                5 / view.zoom
                                            }
                                            y={
                                                f.y +
                                                f.height * y -
                                                5 / view.zoom
                                            }
                                            width={10 / view.zoom}
                                            height={10 / view.zoom}
                                            fill="#fff"
                                            stroke="#3b82f6"
                                            strokeWidth={1 / view.zoom}
                                            hitStrokeWidth={14 / view.zoom}
                                            cornerRadius={2 / view.zoom}
                                            onMouseEnter={() => {
                                                if (!space && stage.current)
                                                    stage.current.container().style.cursor =
                                                        cursor;
                                            }}
                                            onMouseLeave={() => {
                                                if (
                                                    stage.current &&
                                                    gesture.current?.mode !==
                                                        'crop'
                                                )
                                                    stage.current.container().style.cursor =
                                                        '';
                                            }}
                                        />
                                    ))}
                                </Group>
                            );
                        })()}
                    {frame && (
                        <Rect
                            listening={false}
                            x={frame.x - 4}
                            y={frame.y - 4}
                            width={frame.width + 8}
                            height={frame.height + 8}
                            stroke="#60a5fa"
                            strokeWidth={1 / view.zoom}
                            cornerRadius={4}
                        />
                    )}
                    {selectedBox && selected.length > 1 && (
                        <Rect
                            listening={false}
                            x={selectedBox.x - 10 / view.zoom}
                            y={selectedBox.y - 10 / view.zoom}
                            width={selectedBox.width + 20 / view.zoom}
                            height={selectedBox.height + 20 / view.zoom}
                            stroke="#3b82f6"
                            strokeWidth={1 / view.zoom}
                            dash={[5 / view.zoom, 3 / view.zoom]}
                        />
                    )}
                    {selectedBox &&
                        canResizeGroup &&
                        blockHandles.map(
                            ([corner, x, y, cursor]) =>
                                x !== 0.5 &&
                                y !== 0.5 && (
                                    <Rect
                                        key={corner}
                                        name={`group-${corner}`}
                                        x={
                                            selectedBox.x +
                                            selectedBox.width * x +
                                            (x ? 10 : -10) / view.zoom -
                                            4.5 / view.zoom
                                        }
                                        y={
                                            selectedBox.y +
                                            selectedBox.height * y +
                                            (y ? 10 : -10) / view.zoom -
                                            4.5 / view.zoom
                                        }
                                        width={9 / view.zoom}
                                        height={9 / view.zoom}
                                        fill={dark ? '#17202c' : '#fff'}
                                        stroke="#3b82f6"
                                        strokeWidth={1 / view.zoom}
                                        hitStrokeWidth={14 / view.zoom}
                                        cornerRadius={2 / view.zoom}
                                        onMouseEnter={() => {
                                            if (stage.current)
                                                stage.current.container().style.cursor =
                                                    cursor;
                                        }}
                                        onMouseLeave={() => {
                                            if (
                                                stage.current &&
                                                gesture.current?.mode !==
                                                    'group-scale'
                                            )
                                                stage.current.container().style.cursor =
                                                    '';
                                        }}
                                    />
                                ),
                        )}
                    {guides.map((g) => (
                        <Line
                            key={g.axis}
                            listening={false}
                            points={
                                g.axis === 'x'
                                    ? [g.value, -10000, g.value, 10000]
                                    : [-10000, g.value, 10000, g.value]
                            }
                            stroke="#d946ef"
                            strokeWidth={1 / view.zoom}
                            dash={[4 / view.zoom, 4 / view.zoom]}
                        />
                    ))}
                    {marquee && (
                        <Rect
                            {...marquee}
                            listening={false}
                            fill="#3b82f620"
                            stroke="#3b82f6"
                            strokeWidth={1 / view.zoom}
                        />
                    )}
                    <Transformer
                        ref={transformer}
                        rotateEnabled={e.selection.kind === 'ports'}
                        rotationSnaps={[0, 90, 180, 270]}
                        rotationSnapTolerance={45}
                        keepRatio={e.selection.kind !== 'shell'}
                        ignoreStroke={e.selection.kind === 'shell'}
                        flipEnabled={false}
                        enabledAnchors={
                            e.selection.kind === 'shell'
                                ? [
                                      'top-left',
                                      'top-center',
                                      'top-right',
                                      'middle-left',
                                      'middle-right',
                                      'bottom-left',
                                      'bottom-center',
                                      'bottom-right',
                                  ]
                                : [
                                      'top-left',
                                      'top-right',
                                      'bottom-left',
                                      'bottom-right',
                                  ]
                        }
                        anchorSize={9}
                        anchorCornerRadius={2}
                        padding={e.selection.kind === 'ports' ? 6 : 0}
                        borderEnabled={e.selection.kind !== 'ports'}
                        borderStroke="#3b82f6"
                        anchorStroke="#3b82f6"
                        anchorFill={dark ? '#17202c' : '#fff'}
                        onTransformStart={() => {
                            anchorMotion.current = null;
                        }}
                        anchorDragBoundFunc={(old, next, event: MouseEvent) => {
                            if (
                                transformer.current?.getActiveAnchor() ===
                                'rotater'
                            )
                                return next;
                            anchorMotion.current = advancePointer(
                                anchorMotion.current ?? {
                                    pointer: old,
                                    position: old,
                                },
                                next,
                                event.ctrlKey,
                            );
                            return anchorMotion.current.position;
                        }}
                        boundBoxFunc={(old, next) => {
                            if (e.selection.kind === 'shell') {
                                const width = next.width / view.zoom;
                                const height = next.height / view.zoom;
                                return width < 40 ||
                                    height < 40 ||
                                    width > 5000 ||
                                    height > 5000
                                    ? old
                                    : next;
                            }
                            return Math.abs(next.width) < 8 ||
                                Math.abs(next.height) < 8
                                ? old
                                : next;
                        }}
                        onTransformEnd={() => {
                            anchorMotion.current = null;
                            transformEnd();
                        }}
                    />
                </Layer>
            </Stage>
            {labelPort && tooltipKey && (
                <Tooltip
                    open={labelTooltip === tooltipKey}
                    onOpenChange={(open) => {
                        if (!open) setHover(null);
                    }}
                    disableHoverableContent
                >
                    <TooltipTrigger asChild>
                        <span
                            aria-hidden="true"
                            className="pointer-events-none absolute"
                            style={{
                                left:
                                    view.x +
                                    portBounds(labelPort).x * view.zoom,
                                top:
                                    view.y +
                                    portBounds(labelPort).y * view.zoom,
                                width: portBounds(labelPort).width * view.zoom,
                                height:
                                    portBounds(labelPort).height * view.zoom,
                            }}
                        />
                    </TooltipTrigger>
                    <TooltipContent
                        className="pointer-events-none max-w-none whitespace-nowrap"
                        sideOffset={10}
                    >
                        {fullLabel}
                    </TooltipContent>
                </Tooltip>
            )}
        </div>
    );
}
