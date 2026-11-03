import { useEffect, useEffectEvent, useRef, useState } from 'react';
import type Konva from 'konva';
import { Stage, Layer } from 'react-konva';
import { Minus, Plus, Maximize, Eye, Pencil } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { PanelScene } from '@/components/hardware-editor/panel-scene';
import { IconButton } from '@/components/icon-button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Tooltip,
    TooltipTrigger,
    TooltipContent,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/hooks/use-appearance';
import {
    boundsOf,
    imageBounds,
    portBounds,
    type PanelDocument,
    type Face,
    type Port,
} from '@/lib/hardware-editor/document';
import { connectorTypes } from '@/lib/hardware-editor/document';
import { cn } from '@/lib/utils';

export type PortAnchor = {
    contextElement: HTMLElement;
    getBoundingClientRect: () => DOMRect;
};

export function PanelViewer({
    panel,
    assetUrls,
    onPort,
    compact = false,
    initialPortId,
    highlightPortId,
    zoomToPort = false,
    editHref,
}: {
    panel: PanelDocument;
    assetUrls: Record<string, string>;
    onPort?: (port: Port | null, anchor: PortAnchor | null) => void;
    compact?: boolean;
    initialPortId?: string;
    highlightPortId?: string;
    zoomToPort?: boolean;
    editHref?: string;
}) {
    const [face, setFace] = useState<Face>(
        panel.faces.rear.ports.some(
            (port) => port.id === (highlightPortId ?? initialPortId),
        )
            ? 'rear'
            : 'front',
    );
    const [images, setImages] = useState<Record<string, HTMLImageElement>>({});
    const [error, setError] = useState('');
    const [width, setWidth] = useState(800);
    const [zoom, setZoom] = useState(1);
    const [fitRevision, setFitRevision] = useState(0);
    const host = useRef<HTMLDivElement>(null);
    const stage = useRef<Konva.Stage>(null);
    const { resolvedAppearance } = useAppearance();
    const side = panel.faces[face];
    const focusedPort = zoomToPort
        ? side.ports.find((port) => port.id === highlightPortId)
        : undefined;
    const height = compact
        ? focusedPort
            ? 144
            : highlightPortId
              ? 120
              : 48
        : 360;
    const box = focusedPort
        ? portBounds(focusedPort)
        : boundsOf([
              panel.shell,
              ...side.ports
                  .filter((p) => !p.hidden || p.id === highlightPortId)
                  .map(portBounds),
              ...(side.image && !side.image.hidden && !side.image.clip
                  ? [imageBounds(side.image)]
                  : []),
          ]);
    const fit = Math.min(
        (width - (compact ? 16 : 48)) / (box.width + (focusedPort ? 8 : 24)),
        (height - (compact ? 16 : 64)) / (box.height + (focusedPort ? 8 : 24)),
    );
    const scale = Math.max(0.01, fit) * zoom * (focusedPort ? 0.495 : 1);
    const assetKey = JSON.stringify(assetUrls);
    useEffect(() => {
        let cancelled = false;
        setError('');
        Promise.all(
            Object.entries(JSON.parse(assetKey) as Record<string, string>).map(
                async ([id, url]) => {
                    const image = new Image();
                    image.src = url;
                    await image.decode();
                    return [id, image] as const;
                },
            ),
        )
            .then((entries) => {
                if (!cancelled) setImages(Object.fromEntries(entries));
            })
            .catch(() => {
                if (!cancelled)
                    setError(
                        'Impossibile caricare le immagini del pannello. Ricarica la pagina.',
                    );
            });
        return () => {
            cancelled = true;
        };
    }, [assetKey]);
    useEffect(() => {
        const observer = new ResizeObserver(([entry]) =>
            setWidth(entry.contentRect.width),
        );
        if (host.current) observer.observe(host.current);
        return () => observer.disconnect();
    }, []);
    function showPort(port: Port) {
        const symbol = stage.current
            ?.findOne<Konva.Group>(`#${port.id}`)
            ?.findOne('.symbol');
        const container = stage.current?.container();
        if (!symbol || !container) return;
        onPort?.(port, {
            contextElement: container,
            getBoundingClientRect: () => {
                const bounds = symbol.getClientRect({
                    skipStroke: true,
                    skipShadow: true,
                });
                const canvas = container.getBoundingClientRect();
                return new DOMRect(
                    canvas.left + bounds.x,
                    canvas.top + bounds.y,
                    bounds.width,
                    bounds.height,
                );
            },
        });
    }
    const openInitialPort = useEffectEvent(() => {
        const port = side.ports.find(
            (port) => port.id === initialPortId && !port.hidden,
        );
        if (port) showPort(port);
    });
    useEffect(() => {
        if (!initialPortId) return;
        const frame = requestAnimationFrame(() => openInitialPort());
        return () => cancelAnimationFrame(frame);
    }, [initialPortId]);
    return (
        <div
            className={cn(
                'min-w-0 overflow-hidden',
                !compact && 'rounded-xl border bg-muted/20',
            )}
        >
            {!compact && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-background p-3">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={face}
                        onValueChange={(v) => {
                            if (v) {
                                setFace(v as Face);
                                setZoom(1);
                            }
                        }}
                        aria-label="Faccia del dispositivo"
                    >
                        <ToggleGroupItem value="front">
                            Frontale
                        </ToggleGroupItem>
                        <ToggleGroupItem value="rear">Retro</ToggleGroupItem>
                    </ToggleGroup>
                    <div className="flex flex-wrap items-center gap-2">
                        {editHref && (
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value="view"
                                aria-label="Modalità pannello"
                            >
                                <ToggleGroupItem value="view">
                                    <Eye />
                                    Visualizza
                                </ToggleGroupItem>
                                <ToggleGroupItem value="edit" asChild>
                                    <Link href={editHref}>
                                        <Pencil />
                                        Modifica
                                    </Link>
                                </ToggleGroupItem>
                            </ToggleGroup>
                        )}
                        <IconButton
                            label="Riduci zoom"
                            onClick={() =>
                                setZoom((v) => Math.max(0.5, v / 1.25))
                            }
                        >
                            <Minus />
                        </IconButton>
                        <IconButton
                            label="Aumenta zoom"
                            onClick={() =>
                                setZoom((v) => Math.min(6, v * 1.25))
                            }
                        >
                            <Plus />
                        </IconButton>
                        <IconButton
                            label="Adatta pannello"
                            onClick={() => {
                                setZoom(1);
                                setFitRevision((value) => value + 1);
                            }}
                        >
                            <Maximize />
                        </IconButton>
                    </div>
                </div>
            )}
            {error && (
                <Alert variant="destructive">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}
            <div
                ref={host}
                role="img"
                aria-label={
                    focusedPort
                        ? `Porta ${focusedPort.label} · dettaglio ingrandito`
                        : `Pannello ${face === 'front' ? 'frontale' : 'posteriore'}`
                }
            >
                <Stage
                    ref={stage}
                    key={`${face}-${zoom}-${fitRevision}`}
                    width={width}
                    height={height}
                    x={(width - box.width * scale) / 2 - box.x * scale}
                    y={(height - box.height * scale) / 2 - box.y * scale}
                    scaleX={scale}
                    scaleY={scale}
                    draggable={!compact}
                    onDragStart={() => onPort?.(null, null)}
                    onClick={(e) => {
                        const node = e.target.findAncestor('.port', true);
                        const port = side.ports.find(
                            (p) => p.id === node?.id(),
                        );
                        if (port) showPort(port);
                        else onPort?.(null, null);
                    }}
                    onTap={(e) => {
                        const node = e.target.findAncestor('.port', true);
                        const port = side.ports.find(
                            (p) => p.id === node?.id(),
                        );
                        if (port) showPort(port);
                        else onPort?.(null, null);
                    }}
                >
                    <Layer>
                        <PanelScene
                            shell={panel.shell}
                            face={side}
                            images={images}
                            dark={resolvedAppearance === 'dark'}
                            zoom={scale}
                            selectedPortId={highlightPortId}
                        />
                    </Layer>
                </Stage>
            </div>
            {onPort && (
                <div className="flex flex-wrap gap-1 border-t p-2">
                    {side.ports
                        .filter((p) => !p.hidden)
                        .map((p) => (
                            <Tooltip key={p.id}>
                                <TooltipTrigger asChild>
                                    <button
                                        id={`port-${p.id}`}
                                        data-device-port=""
                                        type="button"
                                        aria-haspopup="dialog"
                                        className="rounded-md px-3 py-2 text-xs hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
                                        onClick={() => showPort(p)}
                                    >
                                        {p.label || connectorTypes[p.type].name}
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {connectorTypes[p.type].name}
                                </TooltipContent>
                            </Tooltip>
                        ))}
                </div>
            )}
        </div>
    );
}
