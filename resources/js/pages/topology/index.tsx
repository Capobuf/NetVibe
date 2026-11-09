import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment, useId, useRef, useState, type ReactNode } from 'react';
import {
    ArrowLeft,
    ArrowUpRight,
    Cable,
    CircleAlert,
    Clock3,
    LayoutGrid,
    GitFork,
    Monitor,
    RefreshCw,
    Search,
    Server,
    Workflow,
    X,
} from 'lucide-react';
import { ZoomControls } from '@/components/zoom-controls';
import { useSiteContext } from '@/hooks/use-site-context';
import { IconButton } from '@/components/icon-button';
import {
    Tooltip,
    TooltipTrigger,
    TooltipContent,
} from '@/components/ui/tooltip';
import { ListPageHeader } from '@/components/list-page-header';
import {
    TopologyCanvas,
    type TopologyCanvasApi,
} from '@/components/topology/topology-canvas';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    PanelViewer,
    type PortAnchor,
} from '@/components/devices/panel-viewer';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTitle,
} from '@/components/ui/popover';
import type { TopologyOrientation } from '@/lib/topology-layout';
import type { TopologyRack } from '@/lib/topology-rack-layout';
import { unifiState } from '@/components/unifi/types';
import { DeviceIps } from '@/components/devices/device-ips';
import TextLink from '@/components/text-link';
import { index as modelIndex } from '@/routes/device-models';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn, formatDateTime } from '@/lib/utils';
import { index } from '@/routes/companies/sites/topology';
import {
    show as deviceShow,
    integrations as deviceIntegrations,
} from '@/routes/companies/sites/devices';
import { show as rackShow } from '@/routes/companies/sites/racks';
import type { Company, Site } from '@/types/company';
import {
    linkKinds,
    topologyPortLabel,
    topologyPortMessage,
    type TopologyData,
    type TopologyEdge,
    type TopologySelection,
} from '@/types/topology';

type Props = {
    company: Company;
    site: Site;
    topology: TopologyData;
    racks: TopologyRack[];
};

function LinkSwatch({
    kind,
    className,
}: {
    kind: TopologyEdge['kind'];
    className?: string;
}) {
    return (
        <svg
            aria-hidden
            className={cn(
                'h-2 w-6',
                kind === 'documented' && 'text-primary',
                className,
            )}
            viewBox="0 0 24 8"
        >
            <line
                x1="0"
                y1="4"
                x2="24"
                y2="4"
                stroke="currentColor"
                strokeWidth="2"
                strokeDasharray={
                    kind === 'discovered'
                        ? '10 6'
                        : kind === 'partial'
                          ? '2 6'
                          : kind === 'logical'
                            ? '12 4 2 4'
                            : undefined
                }
            />
        </svg>
    );
}

export default function TopologyIndex(props: Props) {
    return <TopologyView key={props.site.id} {...props} />;
}
function TopologyView({ company, site, topology, racks }: Props) {
    const { auth } = usePage().props;
    const route = { company: company.id, site: site.id };
    const [search, setSearch] = useState('');
    const [rack, setRack] = useState('all');
    const [kind, setKind] = useState('all');
    const [selection, setSelection] = useState<TopologySelection>(null);
    const [panelOpen, setPanelOpen] = useState(false);
    const [orientation, setOrientation] = useState<
        TopologyOrientation | 'racks'
    >('vertical');
    const [zoom, setZoom] = useState(1);
    const [refreshing, setRefreshing] = useState(false);
    const [refreshError, setRefreshError] = useState('');
    const canvas = useRef<TopologyCanvasApi>(null);
    const popupAnchor = useRef<PortAnchor | null>(null);
    const popupContent = useRef<HTMLDivElement>(null);
    const diagram = useRef<HTMLElement>(null);
    const popupTitleId = useId();
    const byId = new Map(topology.nodes.map((node) => [node.id, node]));
    const matchingRack = new Set(
        topology.nodes
            .filter(
                (node) =>
                    rack === 'all' ||
                    (rack === 'none'
                        ? node.device_id && !node.rack
                        : node.rack?.id === Number(rack)),
            )
            .map((node) => node.id),
    );
    const matchingEdges = topology.edges.filter(
        (edge) =>
            (kind === 'all' || edge.kind === kind) &&
            (matchingRack.has(edge.source) || matchingRack.has(edge.target)),
    );
    const visibleIds = new Set([
        ...matchingRack,
        ...matchingEdges.flatMap((edge) => [edge.source, edge.target]),
    ]);
    const term = search.trim().toLocaleLowerCase();
    const searchIds = new Set(
        topology.nodes
            .filter(
                (node) =>
                    visibleIds.has(node.id) &&
                    [
                        node.label,
                        node.model,
                        node.identity,
                        node.mac,
                        node.rack?.name,
                    ]
                        .join(' ')
                        .toLocaleLowerCase()
                        .includes(term),
            )
            .map((node) => node.id),
    );
    const edges = term
        ? matchingEdges.filter(
              (edge) =>
                  searchIds.has(edge.source) || searchIds.has(edge.target),
          )
        : matchingEdges;
    const shown = term
        ? new Set([
              ...searchIds,
              ...edges.flatMap((edge) => [edge.source, edge.target]),
          ])
        : visibleIds;
    const nodes = topology.nodes.filter((node) => shown.has(node.id));
    const visibleRacks = racks.filter(
        (item) =>
            (rack === 'all' && !term) ||
            String(item.id) === rack ||
            nodes.some((node) => node.rack?.id === item.id),
    );
    const selectedNode =
        selection?.kind === 'node'
            ? nodes.find((node) => node.id === selection.id)
            : undefined;
    const selectedEdge =
        selection?.kind === 'edge'
            ? edges.find((edge) => edge.id === selection.id)
            : undefined;
    const connected = selectedNode
        ? edges.filter(
              (edge) =>
                  edge.source === selectedNode.id ||
                  edge.target === selectedNode.id,
          )
        : [];
    const selectedModel = selectedNode?.model_id
        ? topology.models[selectedNode.model_id]
        : undefined;
    const mikrotikSource = topology.integrations.find(
        (source) =>
            source.vendor !== 'unifi' &&
            source.device_id === selectedNode?.device_id,
    );
    const unifi = selectedNode?.unifi;
    const deviceHref = selectedNode?.device_id
        ? deviceShow({ ...route, device: selectedNode.device_id })
        : null;
    // Source freshness stays available on the device name without crowding the card.
    const readings = [
        mikrotikSource &&
            `Lettura MikroTik: ${mikrotikSource.seen_at ? formatDateTime(mikrotikSource.seen_at) : 'mai acquisita'}${mikrotikSource.stale ? ' · Non aggiornata' : ''}`,
        unifi &&
            `Lettura UniFi: ${unifi.synced_at ? formatDateTime(unifi.synced_at) : 'mai sincronizzato'}${unifi.stale ? ' · Non aggiornata' : ''}`,
    ].filter(Boolean);
    const nodeFacts: [string, ReactNode][] = selectedNode
        ? (
              [
                  [
                      'IP',
                      selectedNode.ips.length > 0 && (
                          <DeviceIps
                              ips={selectedNode.ips}
                              name={selectedNode.label}
                              className={cn(
                                  'justify-end',
                                  selectedNode.ips.length === 1 && '-mr-2',
                              )}
                          />
                      ),
                  ],
                  [
                      'IP gestione',
                      unifi?.ip_address &&
                          !selectedNode.ips.includes(unifi.ip_address) &&
                          unifi.ip_address,
                  ],
                  ['MAC', selectedNode.mac ?? unifi?.mac_address],
                  ['Firmware', unifi?.firmware_version],
                  [
                      'Rack',
                      selectedNode.rack && (
                          <TextLink
                              href={rackShow({
                                  ...route,
                                  rack: selectedNode.rack.id,
                              })}
                          >
                              {selectedNode.rack.name}
                          </TextLink>
                      ),
                  ],
              ] as [string, ReactNode][]
          ).filter(([, value]) => value)
        : [];
    const latest = topology.integrations
        .map((source) => source.seen_at)
        .filter((value): value is string => !!value)
        .sort()
        .at(-1);
    const stale =
        topology.integrations.some((source) => source.stale) ||
        edges.some((edge) => edge.stale);
    const context = useSiteContext(company, site);
    const edgeLabel = (edge: TopologyEdge) =>
        `${byId.get(edge.source)?.label ?? 'Dispositivo'} ↔ ${byId.get(edge.target)?.label ?? 'Neighbor'}`;
    function select(next: TopologySelection) {
        setPanelOpen(true);
        setSelection(next);
        popupContent.current?.scrollTo({ top: 0 });
        if (next?.kind === 'node') canvas.current?.focus(next.id);
    }
    function edgeButton(edge: TopologyEdge) {
        return (
            <Button
                key={edge.id}
                variant="ghost"
                className="h-auto min-h-14 w-full justify-start px-3 py-3 text-left whitespace-normal"
                onClick={() => select({ kind: 'edge', id: edge.id })}
            >
                <Cable data-icon="inline-start" />
                <span className="min-w-0 flex-1">
                    <span className="block text-sm">{edgeLabel(edge)}</span>
                    <span className="block text-xs text-muted-foreground">
                        {edge.kind === 'documented'
                            ? edge.cable_ids.map((id) => `C-${id}`).join(', ')
                            : linkKinds[edge.kind]}
                        {edge.stale ? ' · Non aggiornato' : ''}
                    </span>
                </span>
                {edge.issues.length > 0 && (
                    <CircleAlert
                        className="text-destructive"
                        aria-label="Discrepanza"
                    />
                )}
            </Button>
        );
    }
    return (
        <>
            <Head title={`Topologia · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <ListPageHeader
                    title="Topologia"
                    icon={Workflow}
                    actions={
                        <Button
                            variant="outline"
                            disabled={refreshing}
                            onClick={() => {
                                setRefreshing(true);
                                setRefreshError('');
                                router.reload({
                                    only: ['topology', 'racks'],
                                    onError: () =>
                                        setRefreshError(
                                            'Impossibile aggiornare la vista. Riprova.',
                                        ),
                                    onNetworkError: () => {
                                        setRefreshError(
                                            'Connessione interrotta. Riprova.',
                                        );
                                        return false;
                                    },
                                    onFinish: () => setRefreshing(false),
                                });
                            }}
                        >
                            <RefreshCw
                                data-icon="inline-start"
                                className={
                                    refreshing
                                        ? 'animate-spin motion-reduce:animate-none'
                                        : ''
                                }
                            />
                            Aggiorna vista
                        </Button>
                    }
                />
                {refreshError && (
                    <Alert variant="destructive">
                        <AlertDescription>{refreshError}</AlertDescription>
                    </Alert>
                )}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="w-full sm:w-80">
                        <InputGroup>
                            <InputGroupAddon>
                                <Search />
                            </InputGroupAddon>
                            <InputGroupInput
                                aria-label="Cerca dispositivi nella topologia"
                                placeholder="Cerca dispositivi…"
                                value={search}
                                onChange={(event) => {
                                    setSearch(event.target.value);
                                    setSelection(null);
                                }}
                            />
                        </InputGroup>
                    </div>
                    <Select
                        value={rack}
                        onValueChange={(value) => {
                            setRack(value);
                            setSelection(null);
                        }}
                    >
                        <SelectTrigger aria-label="Filtra per rack">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem value="all">
                                    Tutti i rack
                                </SelectItem>
                                <SelectItem value="none">Fuori rack</SelectItem>
                                {racks.map((item) => (
                                    <SelectItem
                                        key={item.id}
                                        value={String(item.id)}
                                    >
                                        {item.name}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <Select
                        value={kind}
                        onValueChange={(value) => {
                            setKind(value);
                            setSelection(null);
                        }}
                    >
                        <SelectTrigger aria-label="Filtra collegamenti">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem value="all">
                                    Tutti i collegamenti
                                </SelectItem>
                                {Object.entries(linkKinds).map(
                                    ([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <p
                        className="ml-auto flex items-center gap-2 text-xs text-muted-foreground"
                        role="status"
                    >
                        <Clock3 className="size-4" />
                        {latest
                            ? `Ultima acquisizione ${formatDateTime(latest)}`
                            : 'Nessuna acquisizione'}
                        {stale && (
                            <CircleAlert
                                className="size-4 text-destructive"
                                aria-label="Sono presenti dati non aggiornati"
                            />
                        )}
                    </p>
                </div>
                <Popover
                    modal={false}
                    open={panelOpen}
                    onOpenChange={(open) => {
                        if (!open) {
                            setPanelOpen(false);
                            setSelection(null);
                        }
                    }}
                >
                    <section
                        ref={diagram}
                        className="relative min-w-0 overflow-hidden rounded-xl border bg-background"
                        aria-label="Topologia della sede"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
                            <p className="text-sm text-muted-foreground">
                                {nodes.length} dispositivi{' '}
                                <span className="mx-2 text-border">/</span>{' '}
                                {edges.length} collegamenti
                            </p>
                            <Button
                                className="sr-only focus:not-sr-only"
                                variant="ghost"
                                onClick={(event) => {
                                    const element = event.currentTarget;
                                    const bounds =
                                        element.getBoundingClientRect();
                                    popupAnchor.current = {
                                        contextElement: element,
                                        getBoundingClientRect: () => bounds,
                                    };
                                    setPanelOpen(true);
                                }}
                            >
                                Esplora la topologia da tastiera
                            </Button>
                            <div className="absolute bottom-16 left-1/2 z-10 flex max-w-[calc(100%-1rem)] -translate-x-1/2 items-center gap-1 rounded-xl border bg-background/95 p-1.5 shadow-lg">
                                <ToggleGroup
                                    type="single"
                                    value={orientation}
                                    onValueChange={(value) => {
                                        if (
                                            value === 'horizontal' ||
                                            value === 'vertical' ||
                                            value === 'racks'
                                        ) {
                                            setSelection(null);
                                            setPanelOpen(false);
                                            setOrientation(value);
                                        }
                                    }}
                                    aria-label="Vista topologia"
                                >
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <ToggleGroupItem
                                                value="horizontal"
                                                aria-label="Disposizione orizzontale"
                                            >
                                                <GitFork className="-rotate-90" />
                                            </ToggleGroupItem>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            Disposizione orizzontale
                                        </TooltipContent>
                                    </Tooltip>
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <ToggleGroupItem
                                                value="vertical"
                                                aria-label="Disposizione verticale"
                                            >
                                                <GitFork />
                                            </ToggleGroupItem>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            Disposizione verticale
                                        </TooltipContent>
                                    </Tooltip>
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <ToggleGroupItem
                                                value="racks"
                                                aria-label="Vista rack"
                                            >
                                                <Server />
                                            </ToggleGroupItem>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            Vista rack
                                        </TooltipContent>
                                    </Tooltip>
                                </ToggleGroup>
                                <Separator
                                    orientation="vertical"
                                    className="h-6"
                                />
                                <IconButton
                                    label="Riordina dispositivi"
                                    variant="ghost"
                                    onClick={() => canvas.current?.arrange()}
                                >
                                    <LayoutGrid />
                                </IconButton>
                                <ZoomControls
                                    zoom={zoom}
                                    onZoom={(factor) => canvas.current?.zoom(factor)}
                                    onFit={() => canvas.current?.fit()}
                                    fitLabel="Adatta diagramma"
                                />
                            </div>
                        </div>
                        <div className="h-[72vh] min-h-[32rem] pb-16">
                            {nodes.length ||
                            (orientation === 'racks' && visibleRacks.length) ? (
                                <TopologyCanvas
                                    key={orientation}
                                    nodes={nodes}
                                    models={topology.models}
                                    orientation={
                                        orientation === 'racks'
                                            ? 'horizontal'
                                            : orientation
                                    }
                                    racks={
                                        orientation === 'racks'
                                            ? visibleRacks
                                            : undefined
                                    }
                                    edges={edges}
                                    selection={selection}
                                    onSelect={(next, anchor) => {
                                        if (anchor)
                                            popupAnchor.current = anchor;
                                        setPanelOpen(
                                            !!anchor &&
                                                (next?.kind === 'node' ||
                                                    edges.some(
                                                        (edge) =>
                                                            edge.id ===
                                                                next?.id &&
                                                            edge.kind ===
                                                                'logical',
                                                    )),
                                        );
                                        setSelection(next);
                                    }}
                                    api={canvas}
                                    onZoom={setZoom}
                                    // The details card already lists both ends; one popup at a time.
                                    endpoints={!panelOpen}
                                />
                            ) : (
                                <Empty className="h-full min-h-96">
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            <Workflow />
                                        </EmptyMedia>
                                        <EmptyTitle>
                                            {topology.nodes.length
                                                ? 'Nessun dispositivo trovato'
                                                : 'Nessun dispositivo nella sede'}
                                        </EmptyTitle>
                                    </EmptyHeader>
                                    {topology.nodes.length > 0 && (
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                setSearch('');
                                                setRack('all');
                                                setKind('all');
                                            }}
                                        >
                                            Azzera filtri
                                        </Button>
                                    )}
                                </Empty>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-x-5 gap-y-2 border-t px-4 py-3 text-xs text-muted-foreground">
                            {Object.entries(linkKinds).map(([value, label]) => (
                                <span
                                    key={value}
                                    className="flex items-center gap-2"
                                >
                                    <LinkSwatch
                                        kind={value as TopologyEdge['kind']}
                                    />
                                    {label}
                                </span>
                            ))}
                        </div>
                    </section>
                    <PopoverAnchor virtualRef={popupAnchor} />
                    <PopoverContent
                        ref={popupContent}
                        id="topology-details"
                        side="right"
                        align="start"
                        sideOffset={16}
                        collisionPadding={12}
                        updatePositionStrategy="always"
                        className="flex max-h-[min(36rem,var(--radix-popover-content-available-height))] w-96 max-w-[calc(100vw-2rem)] flex-col overflow-y-auto rounded-xl p-0"
                        aria-labelledby={popupTitleId}
                        aria-describedby={undefined}
                        onInteractOutside={(event) => {
                            // Canvas clicks update the selection; panning and controls remain usable.
                            if (
                                event.target instanceof Node &&
                                (diagram.current?.contains(event.target) ||
                                    (event.target instanceof Element &&
                                        event.target.closest(
                                            '[data-topology-endpoint]',
                                        )))
                            )
                                event.preventDefault();
                        }}
                        onCloseAutoFocus={(event) => {
                            event.preventDefault();
                            if (
                                document.activeElement === document.body ||
                                popupContent.current?.contains(
                                    document.activeElement,
                                )
                            )
                                popupAnchor.current?.contextElement.focus({
                                    preventScroll: true,
                                });
                        }}
                    >
                        <div className="sticky top-0 z-10 flex shrink-0 items-center justify-between border-b bg-popover px-4 py-2">
                            {(selectedNode || selectedEdge) && (
                                <IconButton
                                    label="Torna all’elenco"
                                    variant="ghost"
                                    onClick={() => {
                                        setSelection(null);
                                        setPanelOpen(true);
                                    }}
                                >
                                    <ArrowLeft />
                                </IconButton>
                            )}
                            <PopoverTitle id={popupTitleId}>
                                {selectedNode
                                    ? 'Dispositivo'
                                    : selectedEdge
                                      ? 'Collegamento'
                                      : 'Topologia'}
                            </PopoverTitle>
                            <IconButton
                                label="Chiudi dettagli"
                                variant="ghost"
                                onClick={() => {
                                    setPanelOpen(false);
                                    setSelection(null);
                                }}
                            >
                                <X />
                            </IconButton>
                        </div>
                        {selectedNode || selectedEdge ? (
                            <>
                                {selectedNode && (
                                    <div className="flex flex-col">
                                        <div className="flex flex-col gap-4 p-4">
                                            {(() => {
                                                const image = selectedModel ? (
                                                    <PanelViewer
                                                        panel={
                                                            selectedModel.panel
                                                        }
                                                        assetUrls={
                                                            selectedModel.assetUrls
                                                        }
                                                        compact
                                                    />
                                                ) : (
                                                    <Monitor className="size-8 text-muted-foreground" />
                                                );
                                                const frame =
                                                    'flex min-h-24 items-center justify-center rounded-lg border bg-muted/40 p-3';
                                                // The name is the keyboard link; the image is a pointer shortcut.
                                                return deviceHref ? (
                                                    <Link
                                                        href={deviceHref}
                                                        tabIndex={-1}
                                                        aria-hidden
                                                        className={cn(
                                                            frame,
                                                            'transition-colors hover:bg-muted',
                                                        )}
                                                    >
                                                        {image}
                                                    </Link>
                                                ) : (
                                                    <div className={frame}>
                                                        {image}
                                                    </div>
                                                );
                                            })()}
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    {readings.length ? (
                                                        <Tooltip>
                                                            <TooltipTrigger
                                                                asChild
                                                            >
                                                                <h3 className="text-base font-semibold break-words">
                                                        {deviceHref ? (
                                                            <TextLink
                                                                href={
                                                                    deviceHref
                                                                }
                                                                className="font-semibold"
                                                            >
                                                                {
                                                                    selectedNode.label
                                                                }
                                                            </TextLink>
                                                        ) : (
                                                            selectedNode.label
                                                        )}
                                                    </h3>
                                                            </TooltipTrigger>
                                                            <TooltipContent className="flex flex-col gap-1">
                                                                {readings.map(
                                                                    (
                                                                        reading,
                                                                    ) => (
                                                                        <span
                                                                            key={String(
                                                                                reading,
                                                                            )}
                                                                        >
                                                                            {
                                                                                reading
                                                                            }
                                                                        </span>
                                                                    ),
                                                                )}
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    ) : (
                                                        <h3 className="text-base font-semibold break-words">
                                                        {deviceHref ? (
                                                            <TextLink
                                                                href={
                                                                    deviceHref
                                                                }
                                                                className="font-semibold"
                                                            >
                                                                {
                                                                    selectedNode.label
                                                                }
                                                            </TextLink>
                                                        ) : (
                                                            selectedNode.label
                                                        )}
                                                    </h3>
                                                    )}
                                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                                        {!selectedNode.device_id ? (
                                                            'Non associato all’inventario'
                                                        ) : selectedNode.model_id ? (
                                                            <TextLink
                                                                href={modelIndex(
                                                                    {
                                                                        query: {
                                                                            model: selectedNode.model_id,
                                                                        },
                                                                    },
                                                                )}
                                                                className="font-normal text-muted-foreground hover:text-foreground"
                                                            >
                                                                {
                                                                    selectedNode.model
                                                                }
                                                            </TextLink>
                                                        ) : (
                                                            'Modello non associato'
                                                        )}
                                                    </p>
                                                </div>
                                                {selectedNode.unifi && (
                                                    <Badge
                                                        variant="outline"
                                                        className="shrink-0"
                                                    >
                                                        <span
                                                            aria-hidden="true"
                                                            className={cn(
                                                                'size-1.5 rounded-full bg-muted-foreground',
                                                                !selectedNode
                                                                    .unifi
                                                                    .stale &&
                                                                    selectedNode
                                                                        .unifi
                                                                        .state ===
                                                                        'ONLINE' &&
                                                                    'bg-emerald-500',
                                                                !selectedNode
                                                                    .unifi
                                                                    .stale &&
                                                                    selectedNode
                                                                        .unifi
                                                                        .state ===
                                                                        'OFFLINE' &&
                                                                    'bg-destructive',
                                                            )}
                                                        />
                                                        {unifiState(
                                                            selectedNode.unifi
                                                                .state,
                                                            selectedNode.unifi
                                                                .stale,
                                                        )}
                                                    </Badge>
                                                )}
                                            </div>
                                        </div>
                                        {nodeFacts.length > 0 && (
                                            <dl className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-6 gap-y-2.5 border-t px-4 py-3 text-sm">
                                                {nodeFacts.map(
                                                    ([label, value]) => (
                                                        <Fragment key={label}>
                                                            <dt className="text-muted-foreground">
                                                                {label}
                                                            </dt>
                                                            <dd className="flex justify-end text-right break-words tabular-nums">
                                                                {value}
                                                            </dd>
                                                        </Fragment>
                                                    ),
                                                )}
                                            </dl>
                                        )}
                                        <div className="flex flex-col gap-1 border-t p-2">
                                            <h4 className="flex items-center justify-between px-2 pt-1 pb-1 text-sm font-medium">
                                                Collegamenti
                                                <span className="text-xs font-normal text-muted-foreground tabular-nums">
                                                    {connected.length}
                                                </span>
                                            </h4>
                                            {connected.length ? (
                                                connected.map((edge) => {
                                                    const peer = byId.get(
                                                        edge.source ===
                                                            selectedNode.id
                                                            ? edge.target
                                                            : edge.source,
                                                    );
                                                    const logical =
                                                        edge.kind === 'logical';
                                                    const details = [
                                                        edge.cable_ids.length
                                                            ? edge.cable_ids
                                                                  .map(
                                                                      (id) =>
                                                                          `C-${id}`,
                                                                  )
                                                                  .join(', ')
                                                            : linkKinds[
                                                                  edge.kind
                                                              ],
                                                        edge.stale &&
                                                            'Non aggiornato',
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ');
                                                    return (
                                                        <Button
                                                            key={edge.id}
                                                            variant="ghost"
                                                            className="h-auto w-full justify-between gap-3 px-2 py-2 text-left whitespace-normal"
                                                            onClick={() =>
                                                                select({
                                                                    kind: 'edge',
                                                                    id: edge.id,
                                                                })
                                                            }
                                                        >
                                                            <span className="flex min-w-0 items-center gap-2">
                                                                <LinkSwatch
                                                                    kind={
                                                                        edge.kind
                                                                    }
                                                                    className="shrink-0"
                                                                />
                                                                <span className="min-w-0">
                                                                    <span className="block truncate text-sm">
                                                                        {logical
                                                                            ? topologyPortMessage(
                                                                                  edge,
                                                                                  selectedNode,
                                                                              )
                                                                            : (topologyPortLabel(
                                                                                  edge,
                                                                                  selectedNode,
                                                                                  false,
                                                                              ) ??
                                                                              '—')}
                                                                    </span>
                                                                    <span className="block truncate text-xs font-normal text-muted-foreground">
                                                                        {
                                                                            details
                                                                        }
                                                                    </span>
                                                                </span>
                                                            </span>
                                                            <span className="flex min-w-0 items-center gap-2">
                                                                <span className="min-w-0 text-right">
                                                                    <span className="block truncate text-sm">
                                                                        {peer?.label ??
                                                                            'Dispositivo'}
                                                                    </span>
                                                                    {!logical && (
                                                                        <span className="block truncate text-xs font-normal text-muted-foreground">
                                                                            {(peer &&
                                                                                topologyPortLabel(
                                                                                    edge,
                                                                                    peer,
                                                                                    false,
                                                                                )) ??
                                                                                '—'}
                                                                        </span>
                                                                    )}
                                                                </span>
                                                                {edge.issues
                                                                    .length >
                                                                    0 && (
                                                                    <CircleAlert
                                                                        className="text-destructive"
                                                                        aria-label="Discrepanza"
                                                                    />
                                                                )}
                                                            </span>
                                                        </Button>
                                                    );
                                                })
                                            ) : (
                                                <p className="px-2 pb-2 text-sm text-muted-foreground">
                                                    Nessun collegamento noto
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                )}
                                {selectedEdge && (
                                    <div className="flex flex-col gap-5 p-5">
                                        <Badge
                                            variant="outline"
                                            className="self-start"
                                        >
                                            {linkKinds[selectedEdge.kind]}
                                        </Badge>
                                        <div className="flex flex-col gap-4">
                                            {(
                                                ['source', 'target'] as const
                                            ).map((side) => {
                                                const end =
                                                    side === 'source'
                                                        ? selectedEdge.a
                                                        : selectedEdge.b;
                                                const node = byId.get(
                                                    selectedEdge[side],
                                                );
                                                return (
                                                    <div key={side}>
                                                        <p className="text-sm font-medium break-words">
                                                            {
                                                                byId.get(
                                                                    selectedEdge[
                                                                        side
                                                                    ],
                                                                )?.label
                                                            }
                                                        </p>
                                                        <p className="mt-1 text-sm text-muted-foreground">
                                                            {node
                                                                ? (topologyPortLabel(
                                                                      selectedEdge,
                                                                      node,
                                                                  ) ??
                                                                  topologyPortMessage(
                                                                      selectedEdge,
                                                                      node,
                                                                  ))
                                                                : 'Dispositivo non disponibile'}
                                                            {end?.stale
                                                                ? ' · Non aggiornata'
                                                                : ''}
                                                        </p>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                        {selectedEdge.purpose && (
                                            <p className="text-sm break-words whitespace-pre-wrap">
                                                {selectedEdge.purpose}
                                            </p>
                                        )}
                                        {selectedEdge.issues.map((issue) => (
                                            <Alert
                                                variant="destructive"
                                                key={issue}
                                            >
                                                <CircleAlert />
                                                <AlertDescription>
                                                    {issue}
                                                </AlertDescription>
                                            </Alert>
                                        ))}
                                        {selectedEdge.kind !== 'documented' &&
                                            (selectedEdge.reciprocal ||
                                                selectedEdge.stale) && (
                                                <p className="text-sm text-muted-foreground">
                                                    {[
                                                        selectedEdge.reciprocal &&
                                                            'Rilevazione reciproca',
                                                        selectedEdge.stale &&
                                                            'Dati non aggiornati',
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </p>
                                            )}
                                        {selectedEdge.cable_ids.length > 0 && (
                                            <p className="text-sm">
                                                Cablaggi:{' '}
                                                {selectedEdge.cable_ids
                                                    .map((id) => `C-${id}`)
                                                    .join(', ')}
                                            </p>
                                        )}
                                        {selectedEdge.suggestion &&
                                            auth.canEditIpam && (
                                                <Button asChild>
                                                    <Link
                                                        href={rackShow(
                                                            {
                                                                ...route,
                                                                rack: selectedEdge
                                                                    .suggestion
                                                                    .rack_id,
                                                            },
                                                            {
                                                                query: {
                                                                    topology_edge:
                                                                        selectedEdge.id,
                                                                },
                                                            },
                                                        )}
                                                    >
                                                        <Cable data-icon="inline-start" />
                                                        Verifica nel Rack
                                                    </Link>
                                                </Button>
                                            )}
                                        {selectedEdge.kind === 'documented' &&
                                            (() => {
                                                const rackId =
                                                    byId.get(
                                                        selectedEdge.source,
                                                    )?.rack?.id ??
                                                    byId.get(
                                                        selectedEdge.target,
                                                    )?.rack?.id;
                                                return rackId ? (
                                                    <Button
                                                        variant="outline"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={rackShow({
                                                                ...route,
                                                                rack: rackId,
                                                            })}
                                                        >
                                                            Apri Rack
                                                            <ArrowUpRight data-icon="inline-end" />
                                                        </Link>
                                                    </Button>
                                                ) : null;
                                            })()}
                                        {selectedEdge.evidence.length > 0 && (
                                            <>
                                                <Separator />
                                                <h4 className="text-sm font-medium">
                                                    Osservazioni
                                                </h4>
                                                {selectedEdge.evidence.map(
                                                    (evidence, i) => (
                                                        <div
                                                            key={i}
                                                            className="flex flex-col gap-2 text-sm"
                                                        >
                                                            <p className="font-medium">
                                                                {
                                                                    evidence.source
                                                                }
                                                                <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                                    {evidence.protocols
                                                                        .join(
                                                                            ' / ',
                                                                        )
                                                                        .toUpperCase() ||
                                                                        'Protocollo non disponibile'}
                                                                </span>
                                                            </p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {evidence.seen_at
                                                                    ? formatDateTime(
                                                                          evidence.seen_at,
                                                                      )
                                                                    : 'Data non disponibile'}
                                                                {evidence.state ===
                                                                'missing'
                                                                    ? ' · Non più rilevata'
                                                                    : evidence.stale
                                                                      ? ' · Non aggiornata'
                                                                      : ''}
                                                            </p>
                                                            <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-2 text-xs">
                                                                {evidence.inventory_match && (
                                                                    <>
                                                                        <dt className="text-muted-foreground">
                                                                            Associazione
                                                                        </dt>
                                                                        <dd>
                                                                            {evidence.inventory_match ===
                                                                            'external_id'
                                                                                ? 'Identità UniFi'
                                                                                : evidence.inventory_match ===
                                                                                    'name_model'
                                                                                  ? 'Nome e modello'
                                                                                  : evidence.inventory_match ===
                                                                                      'interface_mac'
                                                                                    ? 'MAC interfaccia'
                                                                                    : 'Nome e MAC'}
                                                                        </dd>
                                                                    </>
                                                                )}
                                                                {selectedEdge.kind !==
                                                                    'logical' && (
                                                                    <>
                                                                        <dt className="text-muted-foreground">
                                                                            Locale
                                                                        </dt>
                                                                        <dd className="break-words">
                                                                            {evidence.local_port ??
                                                                                '—'}
                                                                        </dd>
                                                                        <dt className="text-muted-foreground">
                                                                            Remota
                                                                        </dt>
                                                                        <dd className="break-words">
                                                                            {evidence.remote_port ??
                                                                                '—'}
                                                                        </dd>
                                                                    </>
                                                                )}
                                                                {evidence.chassis_id && (
                                                                    <>
                                                                        <dt className="text-muted-foreground">
                                                                            Chassis
                                                                        </dt>
                                                                        <dd className="break-words">
                                                                            {
                                                                                evidence.chassis_id
                                                                            }
                                                                        </dd>
                                                                    </>
                                                                )}
                                                                <dt className="text-muted-foreground">
                                                                    Sorgente
                                                                </dt>
                                                                <dd className="break-words">
                                                                    {selectedEdge.kind ===
                                                                    'logical'
                                                                        ? 'Uplink UniFi'
                                                                        : evidence.menu}
                                                                </dd>
                                                                {evidence.lldp_seen_at && (
                                                                    <>
                                                                        <dt className="text-muted-foreground">
                                                                            Lettura
                                                                            LLDP
                                                                        </dt>
                                                                        <dd>
                                                                            {formatDateTime(
                                                                                evidence.lldp_seen_at,
                                                                            )}
                                                                        </dd>
                                                                    </>
                                                                )}
                                                            </dl>
                                                        </div>
                                                    ),
                                                )}
                                            </>
                                        )}
                                    </div>
                                )}
                            </>
                        ) : (
                            <Tabs defaultValue="nodes" className="gap-0">
                                <TabsList
                                    className="m-3"
                                    aria-label="Esplora topologia"
                                >
                                    <TabsTrigger value="nodes">
                                        Dispositivi
                                    </TabsTrigger>
                                    <TabsTrigger value="edges">
                                        Collegamenti
                                    </TabsTrigger>
                                    <TabsTrigger value="sources">
                                        Fonti
                                    </TabsTrigger>
                                </TabsList>
                                <TabsContent value="nodes" className="m-0 p-2">
                                    {nodes.map((node) => (
                                        <Button
                                            variant="ghost"
                                            key={node.id}
                                            className="h-auto min-h-14 w-full justify-start px-3 py-3 text-left"
                                            onClick={() =>
                                                select({
                                                    kind: 'node',
                                                    id: node.id,
                                                })
                                            }
                                        >
                                            <Monitor data-icon="inline-start" />
                                            <span className="min-w-0">
                                                <span className="block truncate">
                                                    {node.label}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {node.device_id
                                                        ? (node.rack?.name ??
                                                          node.model)
                                                        : 'Non associato'}
                                                </span>
                                            </span>
                                        </Button>
                                    ))}
                                </TabsContent>
                                <TabsContent value="edges" className="m-0 p-2">
                                    {edges.map(edgeButton)}
                                    {!edges.length && (
                                        <p className="p-3 text-sm text-muted-foreground">
                                            Nessun collegamento noto
                                        </p>
                                    )}
                                </TabsContent>
                                <TabsContent
                                    value="sources"
                                    className="m-0 flex flex-col gap-5 p-5"
                                >
                                    {topology.integrations.map((source) => (
                                        <div
                                            key={`${source.vendor ?? 'mikrotik'}-${source.device_id}`}
                                            className="flex flex-col gap-2"
                                        >
                                            <p className="text-sm font-medium">
                                                {source.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {source.seen_at
                                                    ? formatDateTime(
                                                          source.seen_at,
                                                      )
                                                    : 'Mai acquisita'}
                                                {source.stale
                                                    ? ' · Non aggiornata'
                                                    : ''}
                                            </p>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={
                                                        source.vendor ===
                                                        'unifi'
                                                            ? deviceShow({
                                                                  ...route,
                                                                  device: source.device_id,
                                                              })
                                                            : deviceIntegrations(
                                                                  {
                                                                      ...route,
                                                                      device: source.device_id,
                                                                  },
                                                                  {
                                                                      query: {
                                                                          tab: 'mikrotik',
                                                                      },
                                                                  },
                                                              )
                                                    }
                                                >
                                                    Apri integrazione
                                                    <ArrowUpRight data-icon="inline-end" />
                                                </Link>
                                            </Button>
                                        </div>
                                    ))}
                                    {!topology.integrations.length && (
                                        <p className="text-sm text-muted-foreground">
                                            Nessuna integrazione configurata
                                        </p>
                                    )}
                                </TabsContent>
                            </Tabs>
                        )}
                    </PopoverContent>
                </Popover>
            </div>
        </>
    );
}
TopologyIndex.layout = ({ company, site }: Props) => ({
    breadcrumbs: [
        {
            title: 'Topologia',
            href: index({ company: company.id, site: site.id }),
        },
    ],
});
