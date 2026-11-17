import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Check,
    ChevronsUpDown,
    Crop,
    Ellipsis,
    Eye,
    GripVertical,
    ImageUp,
    Layers,
    LocateFixed,
    Map as MapIcon,
    MapPinOff,
    MapPinPlus,
    Monitor,
    Pencil,
    Plus,
    Ruler,
    Search,
    Server,
    Tag,
    Trash2,
    Undo2,
    Waypoints,
    X,
} from 'lucide-react';
import {
    Fragment,
    useRef,
    useState,
    type KeyboardEvent,
    type ReactNode,
} from 'react';
import { DeviceIps } from '@/components/devices/device-ips';
import {
    SnipeItImport,
    type SnipeItImportData,
} from '@/components/devices/snipe-it-import';
import { FloorContextMenu } from '@/components/floor-plans/floor-context-menu';
import { FloorDeviceDialog } from '@/components/floor-plans/floor-device-dialog';
import {
    FloorLayers,
    type FloorLayer,
} from '@/components/floor-plans/floor-layers';
import {
    FloorDeleteDialog,
    FloorDialog,
    FloorPlansDialog,
} from '@/components/floor-plans/floor-dialogs';
import {
    FLOOR_PLAN_DRAG_TYPE,
    FloorPlanCanvas,
    type FloorPlanCanvasApi,
    type FloorPlanLine,
    type FloorPlanMode,
} from '@/components/floor-plans/floor-plan-canvas';
import type { QuickDeviceCallbacks } from '@/components/racks/quick-device-dialog';
import { IconButton } from '@/components/icon-button';
import { SaveButton } from '@/components/save-button';
import TextLink from '@/components/text-link';
import { LinkSwatch } from '@/components/topology/link-swatch';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ContextMenu, ContextMenuTrigger } from '@/components/ui/context-menu';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyContent,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Toggle } from '@/components/ui/toggle';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { ZoomControls } from '@/components/zoom-controls';
import { useLeaveGuard } from '@/hooks/use-leave-guard';
import { useSiteContext } from '@/hooks/use-site-context';
import { floorLinks, type FloorLinkRow } from '@/lib/floor-links';
import { canRestack, nextName, restack } from '@/lib/floor-plan';
import type { LayerOrderAction } from '@/lib/hardware-editor/document';
import { cn, formatDateTime } from '@/lib/utils';
import {
    show as deviceShow,
    store as storeDevice,
} from '@/routes/companies/sites/devices';
import { index, layout, show } from '@/routes/companies/sites/floors';
import { show as rackShow } from '@/routes/companies/sites/racks';
import type { Company, Site } from '@/types/company';
import type { Manufacturer, ModelType } from '@/types/device';
import {
    itemKey,
    type Floor,
    type FloorCrop,
    type FloorDevice,
    type FloorImage,
    type FloorItem,
    type FloorLinks,
    type FloorMarker,
    type FloorModel,
    type FloorPlacement,
    type FloorRack,
    type FloorListItem,
} from '@/types/floor';

type Props = {
    company: Company;
    site: Site;
    editing: boolean;
    select: string | null;
    showLinks: boolean;
    floors: FloorListItem[];
    floor: Floor | null;
    elsewhere: FloorPlacement[];
    devices: FloorDevice[];
    racks: FloorRack[];
    /** Deferred: undefined while loading, null for a floor without plan. */
    links?: FloorLinks | null;
    models: FloorModel[];
    manufacturers: Manufacturer[];
    types: ModelType[];
    snipeIt: boolean;
    snipeItImport?: SnipeItImportData | null;
};
type FloorProps = Props & { floor: Floor };
type Action = 'create' | 'edit' | 'image' | 'delete';

type Kind = FloorMarker['kind'];
type Entry = {
    key: string;
    kind: Kind;
    label: string;
    detail: string;
    /** Layer group: the model type, or Rack. */
    group: string;
    terms: string;
    device?: FloorDevice;
    rack?: FloorRack;
};

type Point = { x: number; y: number };
type LayoutData = {
    items: FloorItem[];
    crop: FloorCrop | null;
    scale: number | null;
};
/** What Copy or Cut put aside; module state, so it survives moving to another floor. */
type FloorClipboard = {
    mode: 'copy' | 'cut';
    key: string;
    item: FloorItem;
    label: string;
    modelId: number | null;
};
let clipboard: FloorClipboard | null = null;

export default function Floors(props: Props) {
    if (!props.floor) return <NoFloor {...props} />;
    return props.floor.image ? (
        <FloorEditor
            key={`${props.floor.id}:${props.floor.image.url}`}
            {...props}
            floor={props.floor}
            image={props.floor.image}
        />
    ) : (
        <FloorWithoutPlan key={props.floor.id} {...props} floor={props.floor} />
    );
}

function Header({
    context,
    children,
}: {
    context: string;
    children?: ReactNode;
}) {
    return (
        <header className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-secondary text-secondary-foreground">
                    <MapIcon className="size-5" aria-hidden="true" />
                </span>
                <div className="min-w-0">
                    <p className="truncate text-sm text-muted-foreground">
                        {context}
                    </p>
                    <h1 className="truncate text-2xl font-medium tracking-tight">
                        Planimetrie
                    </h1>
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">{children}</div>
        </header>
    );
}

/** Floor switcher and floor actions; the edit controls sit in between. */
function FloorMenu({
    company,
    site,
    floors,
    floor,
    editing = false,
    onAction,
    children,
}: {
    company: Company;
    site: Site;
    floors: FloorListItem[];
    floor: Floor;
    editing?: boolean;
    onAction: (action: Action) => void;
    children?: ReactNode;
}) {
    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="outline"
                        aria-label={`Piano ${floor.code}`}
                    >
                        <Layers className="text-muted-foreground" />
                        <span className="text-muted-foreground">Piano</span>
                        <span className="font-semibold">{floor.code}</span>
                        <ChevronsUpDown className="text-muted-foreground" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-48">
                    <DropdownMenuRadioGroup
                        value={String(floor.id)}
                        onValueChange={(value) =>
                            router.visit(
                                show({
                                    company: company.id,
                                    site: site.id,
                                    floor: Number(value),
                                }),
                            )
                        }
                    >
                        {floors.map((item) => (
                            <DropdownMenuRadioItem
                                key={item.id}
                                value={String(item.id)}
                            >
                                {item.code}
                            </DropdownMenuRadioItem>
                        ))}
                    </DropdownMenuRadioGroup>
                    <DropdownMenuSeparator />
                    <DropdownMenuGroup>
                        <DropdownMenuItem
                            disabled={editing}
                            onSelect={() => onAction('create')}
                        >
                            <Plus />
                            Nuovo piano
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                </DropdownMenuContent>
            </DropdownMenu>
            {children}
            {!editing && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <IconButton label={`Azioni piano ${floor.code}`}>
                            <Ellipsis />
                        </IconButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={() => onAction('image')}>
                            <ImageUp />
                            {floor.image
                                ? 'Sostituisci planimetria'
                                : 'Carica planimetria'}
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => onAction('edit')}>
                            <Pencil />
                            Modifica piano
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => onAction('delete')}
                        >
                            <Trash2 />
                            Elimina piano
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </>
    );
}

function FloorDialogs({
    company,
    site,
    floors,
    floor,
    action,
    onClose,
}: {
    company: Company;
    site: Site;
    floors: FloorListItem[];
    floor?: Floor;
    action: Action | null;
    onClose: () => void;
}) {
    const route = { company: company.id, site: site.id };
    const props = (kind: Action) => ({
        ...route,
        open: action === kind,
        onOpenChange: (open: boolean) => {
            if (!open) onClose();
        },
    });
    return (
        <>
            {action === 'create' && <FloorDialog {...props('create')} />}
            {floor && action === 'edit' && (
                <FloorDialog {...props('edit')} floor={floor} />
            )}
            {action === 'image' && (
                <FloorPlansDialog
                    {...props('image')}
                    floors={floors}
                    floor={floor}
                />
            )}
            {floor && <FloorDeleteDialog {...props('delete')} floor={floor} />}
        </>
    );
}

function NoFloor({ company, site, floors }: Props) {
    const context = useSiteContext(company, site);
    const [action, setAction] = useState<Action | null>(null);
    return (
        <>
            <Head title={`Planimetrie · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <Header context={context} />
                <Empty className="min-h-[60vh] rounded-xl border">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <Layers />
                        </EmptyMedia>
                        <EmptyTitle>Nessun piano</EmptyTitle>
                    </EmptyHeader>
                    <EmptyContent className="flex-row flex-wrap justify-center">
                        <Button onClick={() => setAction('create')}>
                            <Plus />
                            Nuovo piano
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => setAction('image')}
                        >
                            <ImageUp />
                            Carica planimetrie
                        </Button>
                    </EmptyContent>
                </Empty>
            </div>
            <FloorDialogs
                company={company}
                site={site}
                floors={floors}
                action={action}
                onClose={() => setAction(null)}
            />
        </>
    );
}

function FloorWithoutPlan({ company, site, floors, floor }: FloorProps) {
    const context = useSiteContext(company, site);
    const [action, setAction] = useState<Action | null>(null);
    return (
        <>
            <Head title={`${floor.code} · Planimetrie · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <Header context={context}>
                    <FloorMenu
                        company={company}
                        site={site}
                        floors={floors}
                        floor={floor}
                        onAction={setAction}
                    />
                </Header>
                <Empty className="min-h-[60vh] rounded-xl border">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <MapIcon />
                        </EmptyMedia>
                        <EmptyTitle>Nessuna planimetria</EmptyTitle>
                    </EmptyHeader>
                    <EmptyContent>
                        <Button onClick={() => setAction('image')}>
                            <ImageUp />
                            Carica planimetria
                        </Button>
                    </EmptyContent>
                </Empty>
            </div>
            <FloorDialogs
                company={company}
                site={site}
                floors={floors}
                floor={floor}
                action={action}
                onClose={() => setAction(null)}
            />
        </>
    );
}

function FloorEditor({
    company,
    site,
    editing: initialEditing,
    select,
    showLinks: initialShowLinks,
    floors,
    floor,
    image,
    elsewhere,
    devices,
    racks,
    links,
    models,
    manufacturers,
    types,
    snipeIt,
    snipeItImport,
}: FloorProps & { image: FloorImage }) {
    const route = { company: company.id, site: site.id };
    const context = useSiteContext(company, site);
    const { auth } = usePage().props;
    const form = useForm<LayoutData>({
        items: floor.items,
        crop: floor.crop,
        scale: floor.scale,
    });
    const { items, crop, scale } = form.data;
    const processing = form.processing;
    const isDirty = form.isDirty;
    const [editing, setEditing] = useState(initialEditing);
    const [selected, setSelected] = useState<string | null>(select);
    const [showLinks, setShowLinks] = useState(initialShowLinks);
    const [search, setSearch] = useState('');
    const [kinds, setKinds] = useState<Kind[]>(['device', 'rack']);
    const [labels, setLabels] = useState(true);
    const [tab, setTab] = useState(floor.items.length ? 'layers' : 'unplaced');
    const [zoom, setZoom] = useState(1);
    const [error, setError] = useState('');
    const [discard, setDiscard] = useState(false);
    const [action, setAction] = useState<Action | null>(null);
    const [mode, setMode] = useState<FloorPlanMode>('normal');
    const [cropDraft, setCropDraft] = useState<FloorCrop | null>(null);
    // Pixels between two points of a known distance, waiting for the metres.
    const [measured, setMeasured] = useState<number | null>(null);
    const [creating, setCreating] = useState<
        (Point & { modelId: number | null }) | null
    >(null);
    const [contextKey, setContextKey] = useState<string | null>(null);
    const contextPoint = useRef<Point>({ x: 0.5, y: 0.5 });
    // The footprint asked for before the floor had a scale, drawn once the scale is set.
    const pendingFootprint = useRef<string | null>(null);
    const [pasteable, setPasteable] = useState(clipboard);
    const canvas = useRef<FloorPlanCanvasApi>(null);
    // Creating devices, importing them and loading deferred data do not leave the floor.
    const { leave, setLeave, allowLeave } = useLeaveGuard(isDirty, {
        ignore: (visit) => visit.method !== 'get' || visit.only.length > 0,
    });

    const placed = new Set(items.map(itemKey));
    const others = new Map<string, string[]>();
    for (const placement of elsewhere) {
        const key = itemKey(placement);
        others.set(key, [...(others.get(key) ?? []), placement.floor]);
    }
    const rackDevices = new Map<number, FloorDevice[]>();
    for (const device of devices)
        if (device.rack)
            rackDevices.set(device.rack.id, [
                ...(rackDevices.get(device.rack.id) ?? []),
                device,
            ]);
    // Rack-mounted devices are located through their rack, unless they already have a position of their own.
    const entries: Entry[] = [
        ...racks.map((rack) => ({
            key: `r-${rack.id}`,
            kind: 'rack' as const,
            label: rack.name,
            group: 'Rack',
            detail: [
                `${rack.height_u}U`,
                rack.floor,
                `${rack.devices_count} dispositivi`,
            ]
                .filter(Boolean)
                .join(' · '),
            terms: [
                rack.name,
                rack.floor,
                ...(rackDevices.get(rack.id) ?? []).flatMap((device) => [
                    device.name,
                    ...device.ips,
                ]),
            ].join(' '),
            rack,
        })),
        ...devices
            .filter((device) => !device.rack || placed.has(`d-${device.id}`))
            .map((device) => ({
                key: `d-${device.id}`,
                kind: 'device' as const,
                label: device.name,
                group: device.type ?? 'Senza tipo',
                detail:
                    [device.manufacturer, device.model]
                        .filter(Boolean)
                        .join(' · ') || 'Dispositivo',
                terms: [
                    device.name,
                    device.manufacturer,
                    device.model,
                    ...device.ips,
                ].join(' '),
                device,
            })),
    ];
    const byKey = new Map(entries.map((entry) => [entry.key, entry]));
    const term = search.trim().toLocaleLowerCase();
    const matches = entries.filter(
        (entry) =>
            kinds.includes(entry.kind) &&
            entry.terms.toLocaleLowerCase().includes(term),
    );
    const unplaced = matches.filter((entry) => !placed.has(entry.key));
    // Catalog models become new elements; listed when searching, to keep the list short.
    const modelMatches = term
        ? models
              .filter((model) =>
                  [model.name, model.manufacturer.name, model.model_type?.name]
                      .join(' ')
                      .toLocaleLowerCase()
                      .includes(term),
              )
              .slice(0, 8)
        : [];
    const markers: FloorMarker[] = items.flatMap((item) => {
        const entry = byKey.get(itemKey(item));
        return entry && !item.hidden && kinds.includes(entry.kind)
            ? [
                  {
                      key: entry.key,
                      kind: entry.kind,
                      label: entry.label,
                      x: item.x,
                      y: item.y,
                      locked: item.locked,
                      footprint:
                          item.width && item.height && scale
                              ? {
                                    width: item.width * scale,
                                    height: item.height * scale,
                                }
                              : null,
                  },
              ]
            : [];
    });
    const layers: FloorLayer[] = items.flatMap((item) => {
        const entry = byKey.get(itemKey(item));
        return entry && entry.terms.toLocaleLowerCase().includes(term)
            ? [
                  {
                      key: entry.key,
                      kind: entry.kind,
                      label: entry.label,
                      detail: entry.detail,
                      group: entry.group,
                      hidden: item.hidden,
                      locked: item.locked,
                  },
              ]
            : [];
    });
    const itemOf = (key: string) => items.find((item) => itemKey(item) === key);
    const activeItem = selected ? itemOf(selected) : undefined;
    const active = selected ? byKey.get(selected) : undefined;
    const feedback = error || Object.values(form.errors).join(' ');

    const deviceById = new Map(devices.map((device) => [device.id, device]));
    const floorIds = new Map(floors.map((item) => [item.code, item.id]));
    /** The marker that locates a device on this floor: its own, or its rack's. */
    function markerOf(id: number): string | null {
        if (placed.has(`d-${id}`)) return `d-${id}`;
        const rack = deviceById.get(id)?.rack;
        return rack && placed.has(`r-${rack.id}`) ? `r-${rack.id}` : null;
    }
    /** Another floor holding the device or its rack, with the key to select there. */
    function elsewhereOf(id: number): { code: string; key: string } | null {
        const own = others.get(`d-${id}`)?.[0];
        if (own) return { code: own, key: `d-${id}` };
        const rack = deviceById.get(id)?.rack;
        const code = rack && others.get(`r-${rack.id}`)?.[0];
        return code ? { code, key: `r-${rack.id}` } : null;
    }
    // A rack speaks for its devices that have no position of their own on this floor.
    const activeIds = new Set(
        active?.device
            ? [active.device.id]
            : active?.rack
              ? (rackDevices.get(active.rack.id) ?? [])
                    .filter((device) => !placed.has(`d-${device.id}`))
                    .map((device) => device.id)
              : [],
    );
    const linked =
        links && active && placed.has(active.key)
            ? floorLinks(links, activeIds)
            : null;
    const lines = new Map<string, FloorPlanLine>();
    if (active && linked && showLinks) {
        for (const row of linked.rows) {
            const to =
                row.peer.device_id !== null
                    ? markerOf(row.peer.device_id)
                    : null;
            if (!to || to === active.key) continue;
            const current = lines.get(to);
            lines.set(to, {
                key: to,
                from: active.key,
                to,
                documented: !!current?.documented || row.kind === 'documented',
                stale: (current?.stale ?? true) && row.stale,
            });
        }
    }
    const highlighted =
        active && linked && showLinks
            ? new Set([active.key, ...lines.keys()])
            : term
              ? new Set(matches.map((entry) => entry.key))
              : null;

    function setItems(next: FloorItem[]) {
        setError('');
        form.clearErrors();
        form.setData('items', next);
    }
    /** Changes saved with the layout: any of them opens the edit mode. */
    function patch(keys: string[], change: Partial<FloorItem>) {
        if (processing) return;
        setEditing(true);
        setItems(
            items.map((item) =>
                keys.includes(itemKey(item)) ? { ...item, ...change } : item,
            ),
        );
    }
    function place(
        key: string,
        x: number,
        y: number,
        footprint?: Pick<FloorItem, 'width' | 'height'>,
    ) {
        if (processing) return;
        const id = Number(key.slice(2));
        setItems([
            ...items.filter((item) => itemKey(item) !== key),
            {
                device_id: key.startsWith('d-') ? id : null,
                rack_id: key.startsWith('r-') ? id : null,
                x,
                y,
                hidden: false,
                locked: false,
                width: footprint?.width ?? null,
                height: footprint?.height ?? null,
            },
        ]);
        setSelected(key);
    }
    function move(key: string, x: number, y: number) {
        if (!editing || processing || itemOf(key)?.locked) return;
        setItems(
            items.map((item) =>
                itemKey(item) === key ? { ...item, x, y } : item,
            ),
        );
    }
    function remove(key: string) {
        if (processing || itemOf(key)?.locked) return;
        setEditing(true);
        setItems(items.filter((item) => itemKey(item) !== key));
        setSelected(null);
    }
    function hide(keys: string[], hidden: boolean) {
        patch(keys, { hidden });
        if (hidden && selected && keys.includes(selected)) setSelected(null);
    }
    function restackItem(key: string, order: LayerOrderAction) {
        if (processing) return;
        setEditing(true);
        setItems(restack(items, key, order));
    }
    function toggleFootprint(key: string) {
        const item = itemOf(key);
        if (!item || item.locked) return;
        if (item.width) {
            patch([key], { width: null, height: null });
            return;
        }
        // A footprint is a real size: the plan needs a scale first.
        if (!scale) {
            setError(
                'Imposta prima la scala del piano misurando una distanza nota.',
            );
            startMeasure();
            pendingFootprint.current = key;
            return;
        }
        patch([key], { width: 1, height: 1 });
        setSelected(key);
    }
    function resize(
        key: string,
        width: number,
        height: number,
        x: number,
        y: number,
    ) {
        if (!scale) return;
        const metres = (pixels: number) =>
            Math.min(
                500,
                Math.max(0.1, Math.round((pixels / scale) * 100) / 100),
            );
        patch([key], { width: metres(width), height: metres(height), x, y });
    }
    function copy(key: string) {
        const entry = byKey.get(key);
        const item = itemOf(key);
        if (!entry?.device?.model_id || !item) return;
        clipboard = {
            mode: 'copy',
            key,
            item,
            label: entry.label,
            modelId: entry.device.model_id,
        };
        setPasteable(clipboard);
    }
    function cut(key: string) {
        const entry = byKey.get(key);
        const item = itemOf(key);
        if (!entry || !item || item.locked || processing) return;
        clipboard = {
            mode: 'cut',
            key,
            item,
            label: entry.label,
            modelId: entry.device?.model_id ?? null,
        };
        setPasteable(clipboard);
        remove(key);
    }
    function paste(point: Point) {
        const source = clipboard;
        if (!source || processing) return;
        setEditing(true);
        if (source.mode === 'cut') {
            clipboard = null;
            setPasteable(null);
            place(source.key, point.x, point.y, source.item);
            return;
        }
        // Copy and paste makes another device of the same model, next in the series.
        const taken = new Set(
            devices.map((device) => device.name.toLocaleLowerCase()),
        );
        createDevice(
            source.modelId!,
            nextName(source.label, taken),
            point,
            source.item,
        );
    }
    function createDevice(
        modelId: number,
        name: string,
        point: Point,
        footprint?: Pick<FloorItem, 'width' | 'height'>,
        callbacks?: QuickDeviceCallbacks,
    ) {
        router.post(
            storeDevice.url(route),
            { name, device_model_id: modelId, inline: true },
            {
                preserveState: true,
                preserveScroll: true,
                errorBag: 'floorDevice',
                onSuccess: (page) => {
                    const created = page.flash.createdDevice as
                        | { id: number }
                        | undefined;
                    if (!created) return;
                    setCreating(null);
                    setEditing(true);
                    place(`d-${created.id}`, point.x, point.y, footprint);
                },
                onError: (errors) =>
                    callbacks
                        ? callbacks.onError(errors)
                        : setError(Object.values(errors).join(' ')),
                onFinish: () => callbacks?.onFinish(),
            },
        );
    }
    function openCreate(modelId: number | null, point?: Point) {
        setCreating({
            modelId,
            ...(point ?? canvas.current?.center() ?? { x: 0.5, y: 0.5 }),
        });
    }
    function startCrop() {
        if (processing) return;
        setEditing(true);
        setSelected(null);
        setCropDraft(crop ?? { x: 0, y: 0, width: 1, height: 1 });
        setMode('crop');
    }
    function applyCrop() {
        // A crop covering the whole image is no crop.
        const whole =
            !cropDraft || (cropDraft.width > 0.999 && cropDraft.height > 0.999);
        form.setData('crop', whole ? null : cropDraft);
        setMode('normal');
    }
    function resetCrop() {
        setEditing(true);
        form.setData('crop', null);
        setMode('normal');
    }
    function startMeasure() {
        if (processing) return;
        setEditing(true);
        setSelected(null);
        setMode('measure');
    }
    function cancelMode() {
        pendingFootprint.current = null;
        setMode('normal');
    }
    function applyScale(metres: number) {
        if (!measured || !(metres > 0)) return;
        const pending = pendingFootprint.current;
        pendingFootprint.current = null;
        setError('');
        form.setData({
            ...form.data,
            scale: measured / metres,
            items: items.map((item) =>
                itemKey(item) === pending && !item.width
                    ? { ...item, width: 1, height: 1 }
                    : item,
            ),
        });
        if (pending) setSelected(pending);
        setMeasured(null);
    }
    function shortcuts(event: KeyboardEvent<HTMLDivElement>) {
        if (event.key === 'Escape' && mode !== 'normal') {
            cancelMode();
            return;
        }
        if (!(event.ctrlKey || event.metaKey) || mode !== 'normal') return;
        const key = event.key.toLowerCase();
        if (key === 'c' && selected) copy(selected);
        else if (key === 'x' && selected) cut(selected);
        else if (key === 'v' && canvas.current) paste(canvas.current.pointer());
        else return;
        event.preventDefault();
    }
    function placeAtCenter(key: string) {
        const center = canvas.current?.center();
        if (!center) return;
        setEditing(true);
        place(key, center.x, center.y);
    }
    function locate(entry: Entry) {
        setSelected(entry.key);
        canvas.current?.focus(entry.key);
    }
    function changeMode(value: string) {
        if (!value || processing) return;
        setMode('normal');
        if (value === 'edit') setEditing(true);
        else if (isDirty) setDiscard(true);
        else setEditing(false);
    }
    function reset() {
        form.setData({
            items: floor.items,
            crop: floor.crop,
            scale: floor.scale,
        });
        form.clearErrors();
        setError('');
        setSelected(null);
        setMode('normal');
        setEditing(false);
        setDiscard(false);
    }
    function save(afterSave?: () => void) {
        if (processing) return;
        allowLeave.current = true;
        let succeeded = false;
        form.transform((data) => ({ ...data, revision: floor.revision }));
        form.put(layout.url({ ...route, floor: floor.id }), {
            preserveScroll: true,
            onSuccess: (page) => {
                const saved = page.props.floor as Floor;
                const data = {
                    items: saved.items,
                    crop: saved.crop,
                    scale: saved.scale,
                };
                form.setDefaults(data);
                form.setData(data);
                setMode('normal');
                setEditing(false);
                succeeded = true;
            },
            onFinish: () => {
                allowLeave.current = false;
                if (succeeded) afterSave?.();
            },
        });
    }

    function entryRow(entry: Entry) {
        const Icon = entry.kind === 'rack' ? Server : Monitor;
        const elsewhereCodes = others.get(entry.key);
        const content = (
            <>
                <Icon className="size-4 shrink-0 text-muted-foreground" />
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                        {entry.label}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {entry.detail}
                    </span>
                </span>
                {elsewhereCodes && (
                    <Badge variant="outline" className="shrink-0">
                        {elsewhereCodes.join(', ')}
                    </Badge>
                )}
            </>
        );
        return (
            <li
                key={entry.key}
                draggable={editing && !processing}
                onDragStart={(event) => {
                    event.dataTransfer.setData(FLOOR_PLAN_DRAG_TYPE, entry.key);
                    event.dataTransfer.effectAllowed = 'move';
                }}
                className={cn(
                    'flex items-center gap-2 rounded-lg border bg-background py-1.5 pr-1.5 pl-2 transition-colors',
                    editing &&
                        'cursor-grab border-dashed hover:border-primary/50 hover:bg-accent active:cursor-grabbing',
                )}
            >
                {editing && (
                    <GripVertical
                        className="size-4 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                )}
                {content}
                <IconButton
                    label={`Posiziona ${entry.label} al centro della vista`}
                    variant="ghost"
                    className="size-8"
                    disabled={processing}
                    onClick={() => placeAtCenter(entry.key)}
                >
                    <MapPinPlus />
                </IconButton>
            </li>
        );
    }

    const rowClass =
        'flex w-full items-start gap-3 rounded-md px-2 py-2 text-left transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring';
    function linkRow(row: FloorLinkRow) {
        const peerId = row.peer.device_id;
        const peerName =
            peerId !== null
                ? (deviceById.get(peerId)?.name ?? 'Dispositivo')
                : row.peer.label || 'Neighbor non identificato';
        const local = [
            active?.rack ? deviceById.get(row.local.device_id)?.name : null,
            row.local.port,
        ]
            .filter(Boolean)
            .join(' · ');
        // Who reported it, and when it was last seen.
        const names = [...new Set(row.sources.map((item) => item.source))];
        const seen = row.sources
            .map((item) => item.seen_at)
            .filter((value): value is string => !!value)
            .sort()
            .at(-1);
        const sources = names.length
            ? [names.join(', '), seen && formatDateTime(seen)]
                  .filter(Boolean)
                  .join(' · ')
            : '';
        const detail = [
            local,
            row.kind === 'documented' ? 'Documentato' : 'Rilevato',
            row.basis,
            sources &&
                (row.kind === 'documented'
                    ? `confermato da ${sources}`
                    : sources),
            row.stale && 'Non aggiornato',
        ]
            .filter(Boolean)
            .join(' · ');
        const here = peerId !== null ? markerOf(peerId) : null;
        const away = peerId !== null && !here ? elsewhereOf(peerId) : null;
        const content = (
            <>
                <LinkSwatch kind={row.kind} className="mt-1.5 shrink-0" />
                <span className="min-w-0 flex-1">
                    <span className="block text-sm break-words">
                        {peerName}
                        {row.peer.port && (
                            <span className="text-muted-foreground">
                                {' · '}
                                {row.peer.port}
                            </span>
                        )}
                    </span>
                    <span className="block text-xs break-words text-muted-foreground">
                        {detail}
                    </span>
                    {row.issues.map((issue) => (
                        <span
                            key={issue}
                            className="block text-xs break-words text-destructive"
                        >
                            {issue}
                        </span>
                    ))}
                </span>
                {here ? (
                    <span className="mt-0.5 shrink-0 text-muted-foreground">
                        <LocateFixed className="size-4" aria-hidden />
                        <span className="sr-only">Sul piano</span>
                    </span>
                ) : away ? (
                    <Badge variant="outline" className="shrink-0">
                        {away.code}
                    </Badge>
                ) : peerId !== null ? (
                    <Badge variant="secondary" className="shrink-0">
                        Non posizionato
                    </Badge>
                ) : null}
            </>
        );
        return (
            <li key={row.id}>
                {here ? (
                    <button
                        type="button"
                        className={rowClass}
                        onClick={() => {
                            const entry = byKey.get(here);
                            if (entry) locate(entry);
                        }}
                    >
                        {content}
                    </button>
                ) : away ? (
                    <Link
                        className={rowClass}
                        href={show(
                            { ...route, floor: floorIds.get(away.code)! },
                            {
                                query: {
                                    select: away.key,
                                    ...(showLinks ? { links: 1 } : {}),
                                },
                            },
                        )}
                    >
                        {content}
                    </Link>
                ) : peerId !== null ? (
                    <Link
                        className={rowClass}
                        href={deviceShow({ ...route, device: peerId })}
                    >
                        {content}
                    </Link>
                ) : (
                    <div className="flex items-start gap-3 px-2 py-2">
                        {content}
                    </div>
                )}
            </li>
        );
    }

    const facts: [string, ReactNode][] = active
        ? (
              [
                  [
                      'IP',
                      active.device?.ips.length ? (
                          <DeviceIps
                              ips={active.device.ips}
                              name={active.label}
                              className="justify-end"
                          />
                      ) : null,
                  ],
                  [
                      'Rack',
                      active.device?.rack && (
                          <TextLink
                              href={rackShow({
                                  ...route,
                                  rack: active.device.rack.id,
                              })}
                          >
                              {active.device.rack.name}
                          </TextLink>
                      ),
                  ],
                  ['Altezza', active.rack && `${active.rack.height_u}U`],
                  ['Piano del rack', active.rack?.floor],
                  ['Anche sul piano', others.get(active.key)?.join(', ')],
              ] as [string, ReactNode][]
          ).filter(([, value]) => value)
        : [];
    const activeRackDevices = active?.rack
        ? (rackDevices.get(active.rack.id) ?? [])
        : [];
    const hrefOf = (entry: Entry) =>
        entry.device
            ? deviceShow({ ...route, device: entry.device.id })
            : rackShow({ ...route, rack: entry.rack!.id });
    const activeHref = active ? hrefOf(active) : null;
    const contextEntry = contextKey ? byKey.get(contextKey) : undefined;
    const contextItem = contextKey ? itemOf(contextKey) : undefined;

    return (
        <>
            <Head title={`${floor.code} · Planimetrie · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-4 p-4 md:p-6">
                <Header context={context}>
                    <FloorMenu
                        company={company}
                        site={site}
                        floors={floors}
                        floor={floor}
                        editing={editing}
                        onAction={setAction}
                    >
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={editing ? 'edit' : 'view'}
                            onValueChange={changeMode}
                            disabled={processing}
                            aria-label="Modalità planimetria"
                        >
                            <ToggleGroupItem value="view">
                                <Eye />
                                Esplora
                            </ToggleGroupItem>
                            <ToggleGroupItem value="edit">
                                <Pencil />
                                Modifica
                            </ToggleGroupItem>
                        </ToggleGroup>
                        {editing && (
                            <>
                                <Button
                                    variant="outline"
                                    disabled={processing}
                                    onClick={() => changeMode('view')}
                                >
                                    <Undo2 />
                                    Annulla
                                </Button>
                                <SaveButton
                                    type="button"
                                    processing={processing}
                                    saved={!isDirty}
                                    onClick={() => save()}
                                />
                            </>
                        )}
                    </FloorMenu>
                </Header>
                {feedback && (
                    <Alert variant="destructive">
                        <AlertDescription>{feedback}</AlertDescription>
                    </Alert>
                )}
                <div className="grid min-w-0 gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
                    <aside className="order-2 flex max-h-[72vh] min-h-0 min-w-0 flex-col overflow-hidden rounded-xl border bg-background lg:order-1 lg:max-h-none lg:[contain:size]">
                        <div className="border-b p-3">
                            <InputGroup>
                                <InputGroupAddon>
                                    <Search />
                                </InputGroupAddon>
                                <InputGroupInput
                                    aria-label="Cerca dispositivi e rack"
                                    placeholder="Nome, modello o IP…"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </InputGroup>
                        </div>
                        <Tabs
                            value={tab}
                            onValueChange={setTab}
                            className="min-h-0 flex-1 gap-0"
                        >
                            <TabsList
                                className="mx-3 mt-3 grid grid-cols-2"
                                aria-label="Elementi della sede"
                            >
                                <TabsTrigger value="unplaced">
                                    Da posizionare
                                    <Badge
                                        variant="secondary"
                                        className="tabular-nums"
                                    >
                                        {unplaced.length}
                                    </Badge>
                                </TabsTrigger>
                                <TabsTrigger value="layers">
                                    Livelli
                                    <Badge
                                        variant="secondary"
                                        className="tabular-nums"
                                    >
                                        {items.length}
                                    </Badge>
                                </TabsTrigger>
                            </TabsList>
                            <TabsContent
                                value="unplaced"
                                className="m-0 flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-3"
                            >
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                        onClick={() => openCreate(null)}
                                    >
                                        <Plus />
                                        Nuovo dispositivo
                                    </Button>
                                    {snipeIt && (
                                        <SnipeItImport
                                            company={company}
                                            site={site}
                                            data={snipeItImport}
                                            label="Importa da Snipe-IT"
                                            size="sm"
                                        />
                                    )}
                                </div>
                                {unplaced.length ? (
                                    <ul className="flex flex-col gap-2">
                                        {unplaced.map(entryRow)}
                                    </ul>
                                ) : (
                                    <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                                        {term
                                            ? 'Nessun elemento trovato'
                                            : entries.length
                                              ? 'Tutto è posizionato'
                                              : 'Nessun dispositivo o rack nella sede'}
                                    </p>
                                )}
                                {modelMatches.length > 0 && (
                                    <section
                                        className="flex flex-col gap-2"
                                        aria-label="Nuovo dispositivo da modello"
                                    >
                                        <h3 className="text-xs font-medium text-muted-foreground">
                                            Nuovo dispositivo da modello
                                        </h3>
                                        <ul className="flex flex-col gap-2">
                                            {modelMatches.map((model) => (
                                                <li
                                                    key={model.id}
                                                    draggable={
                                                        editing && !processing
                                                    }
                                                    onDragStart={(event) => {
                                                        event.dataTransfer.setData(
                                                            FLOOR_PLAN_DRAG_TYPE,
                                                            `m-${model.id}`,
                                                        );
                                                        event.dataTransfer.effectAllowed =
                                                            'copy';
                                                    }}
                                                    className={cn(
                                                        'flex items-center gap-2 rounded-lg border border-dashed bg-background py-1.5 pr-1.5 pl-2 transition-colors',
                                                        editing &&
                                                            'cursor-grab hover:border-primary/50 hover:bg-accent active:cursor-grabbing',
                                                    )}
                                                >
                                                    {editing ? (
                                                        <GripVertical
                                                            className="size-4 shrink-0 text-muted-foreground"
                                                            aria-hidden
                                                        />
                                                    ) : (
                                                        <Plus
                                                            className="size-4 shrink-0 text-muted-foreground"
                                                            aria-hidden
                                                        />
                                                    )}
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-sm font-medium">
                                                            {model.name}
                                                        </span>
                                                        <span className="block truncate text-xs text-muted-foreground">
                                                            {[
                                                                model
                                                                    .manufacturer
                                                                    .name,
                                                                model.model_type
                                                                    ?.name,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' · ')}
                                                        </span>
                                                    </span>
                                                    <IconButton
                                                        label={`Nuovo ${model.name} al centro della vista`}
                                                        variant="ghost"
                                                        className="size-8"
                                                        disabled={processing}
                                                        onClick={() =>
                                                            openCreate(model.id)
                                                        }
                                                    >
                                                        <MapPinPlus />
                                                    </IconButton>
                                                </li>
                                            ))}
                                        </ul>
                                    </section>
                                )}
                            </TabsContent>
                            <TabsContent
                                value="layers"
                                className="m-0 min-h-0 flex-1 overflow-y-auto p-2"
                            >
                                {layers.length ? (
                                    <FloorLayers
                                        layers={layers}
                                        selected={selected}
                                        disabled={processing}
                                        onLocate={(key) => {
                                            const entry = byKey.get(key);
                                            if (entry) locate(entry);
                                        }}
                                        onHidden={hide}
                                        onLocked={(keys, locked) =>
                                            patch(keys, { locked })
                                        }
                                        onRestack={restackItem}
                                        canRestack={(key, order) =>
                                            canRestack(items, key, order)
                                        }
                                    />
                                ) : (
                                    <p className="px-2 py-6 text-center text-sm text-muted-foreground">
                                        {term
                                            ? 'Nessun risultato'
                                            : 'Nessun elemento sul piano'}
                                    </p>
                                )}
                            </TabsContent>
                        </Tabs>
                    </aside>
                    <section
                        className="relative order-1 flex min-w-0 flex-col overflow-hidden rounded-xl border bg-background lg:order-2"
                        aria-label={`Planimetria del piano ${floor.code}`}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b p-2">
                            <div className="flex items-center gap-2">
                                <ToggleGroup
                                    type="multiple"
                                    variant="outline"
                                    size="sm"
                                    value={kinds}
                                    onValueChange={(value) =>
                                        setKinds(value as Kind[])
                                    }
                                    aria-label="Elementi visibili"
                                >
                                    <ToggleGroupItem value="device">
                                        <Monitor />
                                        Dispositivi
                                    </ToggleGroupItem>
                                    <ToggleGroupItem value="rack">
                                        <Server />
                                        Rack
                                    </ToggleGroupItem>
                                </ToggleGroup>
                                <Toggle
                                    variant="outline"
                                    size="sm"
                                    pressed={labels}
                                    onPressedChange={setLabels}
                                    aria-label="Mostra i nomi"
                                >
                                    <Tag />
                                    Nomi
                                </Toggle>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={processing || mode !== 'normal'}
                                    onClick={startCrop}
                                >
                                    <Crop />
                                    Ritaglia
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={processing || mode !== 'normal'}
                                    onClick={startMeasure}
                                >
                                    <Ruler />
                                    Scala
                                </Button>
                            </div>
                            <div className="flex items-center gap-1">
                                <ZoomControls
                                    zoom={zoom}
                                    onZoom={(factor) =>
                                        canvas.current?.zoom(factor)
                                    }
                                    onFit={() => canvas.current?.fit()}
                                    fitLabel="Adatta planimetria"
                                />
                            </div>
                        </div>
                        <div className="relative h-[72vh] min-h-[28rem]">
                            <ContextMenu disabled={mode !== 'normal'}>
                                <ContextMenuTrigger
                                    className="h-full w-full"
                                    onKeyDown={shortcuts}
                                >
                                    <FloorPlanCanvas
                                        imageUrl={image.url}
                                        width={image.width}
                                        height={image.height}
                                        crop={crop}
                                        scale={scale}
                                        markers={markers}
                                        selected={selected}
                                        highlighted={highlighted}
                                        lines={[...lines.values()]}
                                        labels={labels}
                                        editing={editing && !processing}
                                        mode={mode}
                                        cropDraft={cropDraft}
                                        onCropDraft={setCropDraft}
                                        onMeasure={(a, b) => {
                                            const pixels = Math.hypot(
                                                b.x - a.x,
                                                b.y - a.y,
                                            );
                                            setMode('normal');
                                            if (pixels >= 5)
                                                setMeasured(pixels);
                                        }}
                                        onSelect={setSelected}
                                        onMove={move}
                                        onDrop={(key, x, y) =>
                                            key.startsWith('m-')
                                                ? openCreate(
                                                      Number(key.slice(2)),
                                                      {
                                                          x,
                                                          y,
                                                      },
                                                  )
                                                : place(key, x, y)
                                        }
                                        onRemove={remove}
                                        onResize={resize}
                                        onContext={(key, point) => {
                                            contextPoint.current = point;
                                            setContextKey(key);
                                            if (key) setSelected(key);
                                        }}
                                        onZoom={setZoom}
                                        onError={setError}
                                        api={canvas}
                                    />
                                </ContextMenuTrigger>
                                <FloorContextMenu
                                    item={
                                        contextEntry && contextItem
                                            ? {
                                                  label: contextEntry.label,
                                                  locked: contextItem.locked,
                                                  footprint:
                                                      !!contextItem.width,
                                                  copyable:
                                                      !!contextEntry.device
                                                          ?.model_id,
                                              }
                                            : null
                                    }
                                    canPaste={!!pasteable}
                                    pasteLabel={
                                        pasteable
                                            ? `Incolla «${pasteable.label}»`
                                            : 'Incolla'
                                    }
                                    showLinks={showLinks}
                                    cropped={!!crop}
                                    canRestack={(order) =>
                                        !!contextKey &&
                                        canRestack(items, contextKey, order)
                                    }
                                    onShowLinks={() => setShowLinks(!showLinks)}
                                    onOpen={() => {
                                        if (contextEntry)
                                            router.visit(hrefOf(contextEntry));
                                    }}
                                    onCopy={() =>
                                        contextKey && copy(contextKey)
                                    }
                                    onCut={() => contextKey && cut(contextKey)}
                                    onPaste={() => paste(contextPoint.current)}
                                    onRestack={(order) =>
                                        contextKey &&
                                        restackItem(contextKey, order)
                                    }
                                    onFootprint={() =>
                                        contextKey &&
                                        toggleFootprint(contextKey)
                                    }
                                    onHide={() =>
                                        contextKey && hide([contextKey], true)
                                    }
                                    onLock={() =>
                                        contextKey &&
                                        contextItem &&
                                        patch([contextKey], {
                                            locked: !contextItem.locked,
                                        })
                                    }
                                    onRemove={() =>
                                        contextKey && remove(contextKey)
                                    }
                                    onNewDevice={() =>
                                        openCreate(null, contextPoint.current)
                                    }
                                    onCrop={startCrop}
                                    onResetCrop={resetCrop}
                                    onScale={startMeasure}
                                    onFit={() => canvas.current?.fit()}
                                />
                            </ContextMenu>
                            {mode !== 'normal' && (
                                <div
                                    role="status"
                                    className="absolute top-3 left-1/2 z-10 flex max-w-[calc(100%-1.5rem)] -translate-x-1/2 flex-wrap items-center justify-center gap-2 rounded-lg border bg-popover px-3 py-2 text-popover-foreground shadow-lg motion-safe:animate-in motion-safe:fade-in"
                                >
                                    {mode === 'crop' ? (
                                        <Crop className="size-4 text-muted-foreground" />
                                    ) : (
                                        <Ruler className="size-4 text-muted-foreground" />
                                    )}
                                    <span className="text-sm">
                                        {mode === 'crop'
                                            ? 'Ritaglia planimetria'
                                            : 'Clicca i due estremi di una misura nota'}
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={cancelMode}
                                    >
                                        Annulla
                                    </Button>
                                    {mode === 'crop' && (
                                        <Button size="sm" onClick={applyCrop}>
                                            <Check />
                                            Applica
                                        </Button>
                                    )}
                                </div>
                            )}
                            {active &&
                                placed.has(active.key) &&
                                mode === 'normal' && (
                                    <aside
                                        key={active.key}
                                        aria-label={`Dettagli ${active.label}`}
                                        className="absolute top-3 right-3 z-10 flex max-h-[calc(100%-1.5rem)] w-80 max-w-[calc(100%-1.5rem)] flex-col overflow-hidden rounded-xl border bg-popover text-popover-foreground shadow-lg motion-safe:animate-in motion-safe:duration-200 motion-safe:fade-in motion-safe:slide-in-from-right-2"
                                    >
                                        <div className="flex items-start gap-3 border-b p-4">
                                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                                                {active.kind === 'rack' ? (
                                                    <Server className="size-4" />
                                                ) : (
                                                    <Monitor className="size-4" />
                                                )}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <h2 className="text-base font-semibold break-words">
                                                    {activeHref ? (
                                                        <TextLink
                                                            href={activeHref}
                                                            className="font-semibold"
                                                        >
                                                            {active.label}
                                                        </TextLink>
                                                    ) : (
                                                        active.label
                                                    )}
                                                </h2>
                                                <p className="text-sm text-muted-foreground">
                                                    {active.kind === 'rack'
                                                        ? 'Rack'
                                                        : active.detail}
                                                </p>
                                            </div>
                                            <IconButton
                                                label="Chiudi dettagli"
                                                variant="ghost"
                                                className="size-8"
                                                onClick={() =>
                                                    setSelected(null)
                                                }
                                            >
                                                <X />
                                            </IconButton>
                                        </div>
                                        <div className="min-h-0 overflow-y-auto">
                                            {facts.length > 0 && (
                                                <dl className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-6 gap-y-2.5 px-4 py-3 text-sm">
                                                    {facts.map(
                                                        ([label, value]) => (
                                                            <Fragment
                                                                key={label}
                                                            >
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
                                            {active.rack && (
                                                <div className="flex flex-col gap-1 border-t p-2">
                                                    <h3 className="flex items-center justify-between px-2 py-1 text-sm font-medium">
                                                        Dispositivi
                                                        <span className="text-xs font-normal text-muted-foreground tabular-nums">
                                                            {
                                                                activeRackDevices.length
                                                            }
                                                        </span>
                                                    </h3>
                                                    {activeRackDevices.length ? (
                                                        <ul>
                                                            {activeRackDevices.map(
                                                                (device) => (
                                                                    <li
                                                                        key={
                                                                            device.id
                                                                        }
                                                                    >
                                                                        <Link
                                                                            href={deviceShow(
                                                                                {
                                                                                    ...route,
                                                                                    device: device.id,
                                                                                },
                                                                            )}
                                                                            className="flex items-center justify-between gap-3 rounded-md px-2 py-1.5 text-sm transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
                                                                        >
                                                                            <span className="truncate">
                                                                                {
                                                                                    device.name
                                                                                }
                                                                            </span>
                                                                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                                                {
                                                                                    device
                                                                                        .ips[0]
                                                                                }
                                                                            </span>
                                                                        </Link>
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
                                                    ) : (
                                                        <p className="px-2 pb-2 text-sm text-muted-foreground">
                                                            Nessun dispositivo
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                            <section
                                                className="flex flex-col gap-1 border-t p-2"
                                                aria-label="Collegamenti"
                                            >
                                                <div className="flex items-center justify-between gap-2 px-2 py-1">
                                                    <h3 className="text-sm font-medium">
                                                        Collegamenti
                                                    </h3>
                                                    <Toggle
                                                        variant="outline"
                                                        size="sm"
                                                        pressed={showLinks}
                                                        onPressedChange={
                                                            setShowLinks
                                                        }
                                                        disabled={
                                                            !linked?.rows.length
                                                        }
                                                    >
                                                        <Waypoints />
                                                        Mostra collegamenti
                                                    </Toggle>
                                                </div>
                                                {!linked ? (
                                                    <div className="flex flex-col gap-2 px-2 pb-2">
                                                        <Skeleton className="h-10" />
                                                        <Skeleton className="h-10" />
                                                    </div>
                                                ) : linked.rows.length ||
                                                  linked.hosts.length ? (
                                                    <ul>
                                                        {linked.rows.map(
                                                            linkRow,
                                                        )}
                                                        {linked.hosts.map(
                                                            (host) => (
                                                                <li
                                                                    key={
                                                                        host.device_id
                                                                    }
                                                                >
                                                                    <Link
                                                                        className={
                                                                            rowClass
                                                                        }
                                                                        href={deviceShow(
                                                                            {
                                                                                ...route,
                                                                                device: host.device_id,
                                                                            },
                                                                            {
                                                                                query: {
                                                                                    tab: 'observed',
                                                                                },
                                                                            },
                                                                        )}
                                                                    >
                                                                        <LinkSwatch
                                                                            kind="partial"
                                                                            className="mt-1.5 shrink-0"
                                                                        />
                                                                        <span className="min-w-0 flex-1">
                                                                            <span className="block text-sm">
                                                                                {host.count ===
                                                                                1
                                                                                    ? '1 MAC non censito'
                                                                                    : `${host.count} MAC non censiti`}
                                                                            </span>
                                                                            <span className="block text-xs break-words text-muted-foreground">
                                                                                {[
                                                                                    active.rack
                                                                                        ? deviceById.get(
                                                                                              host.device_id,
                                                                                          )
                                                                                              ?.name
                                                                                        : null,
                                                                                    host
                                                                                        .ports
                                                                                        .length ===
                                                                                    1
                                                                                        ? (host
                                                                                              .ports[0] ??
                                                                                          'Wi-Fi')
                                                                                        : `${host.ports.length} porte`,
                                                                                    'Rilevato',
                                                                                ]
                                                                                    .filter(
                                                                                        Boolean,
                                                                                    )
                                                                                    .join(
                                                                                        ' · ',
                                                                                    )}
                                                                            </span>
                                                                        </span>
                                                                    </Link>
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                ) : (
                                                    <p className="px-2 pb-2 text-sm text-muted-foreground">
                                                        Nessun collegamento
                                                    </p>
                                                )}
                                            </section>
                                            {editing && activeItem && (
                                                <section
                                                    className="flex flex-col gap-2 border-t p-4"
                                                    aria-label="Ingombro"
                                                >
                                                    <div className="flex items-center justify-between gap-2">
                                                        <h3 className="text-sm font-medium">
                                                            Ingombro
                                                        </h3>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            disabled={
                                                                processing ||
                                                                activeItem.locked
                                                            }
                                                            onClick={() =>
                                                                toggleFootprint(
                                                                    active.key,
                                                                )
                                                            }
                                                        >
                                                            {activeItem.width
                                                                ? 'Rimuovi'
                                                                : 'Disegna'}
                                                        </Button>
                                                    </div>
                                                    {activeItem.width &&
                                                        activeItem.height && (
                                                            <div className="grid grid-cols-2 gap-3">
                                                                {(
                                                                    [
                                                                        [
                                                                            'width',
                                                                            'Larghezza (m)',
                                                                        ],
                                                                        [
                                                                            'height',
                                                                            'Profondità (m)',
                                                                        ],
                                                                    ] as const
                                                                ).map(
                                                                    ([
                                                                        field,
                                                                        label,
                                                                    ]) => (
                                                                        <Field
                                                                            key={
                                                                                field
                                                                            }
                                                                        >
                                                                            <FieldLabel
                                                                                htmlFor={`footprint-${field}`}
                                                                            >
                                                                                {
                                                                                    label
                                                                                }
                                                                            </FieldLabel>
                                                                            <Input
                                                                                // Remounted when the handles change the size.
                                                                                key={`${active.key}:${activeItem[field]}`}
                                                                                id={`footprint-${field}`}
                                                                                type="number"
                                                                                inputMode="decimal"
                                                                                min={
                                                                                    0.1
                                                                                }
                                                                                max={
                                                                                    500
                                                                                }
                                                                                step={
                                                                                    0.1
                                                                                }
                                                                                defaultValue={
                                                                                    activeItem[
                                                                                        field
                                                                                    ] ??
                                                                                    ''
                                                                                }
                                                                                disabled={
                                                                                    processing ||
                                                                                    activeItem.locked
                                                                                }
                                                                                onBlur={(
                                                                                    event,
                                                                                ) => {
                                                                                    const value =
                                                                                        event
                                                                                            .target
                                                                                            .valueAsNumber;
                                                                                    if (
                                                                                        value >=
                                                                                            0.1 &&
                                                                                        value <=
                                                                                            500 &&
                                                                                        value !==
                                                                                            activeItem[
                                                                                                field
                                                                                            ]
                                                                                    )
                                                                                        patch(
                                                                                            [
                                                                                                active.key,
                                                                                            ],
                                                                                            {
                                                                                                [field]:
                                                                                                    value,
                                                                                            },
                                                                                        );
                                                                                }}
                                                                                onKeyDown={(
                                                                                    event,
                                                                                ) => {
                                                                                    if (
                                                                                        event.key ===
                                                                                        'Enter'
                                                                                    )
                                                                                        event.currentTarget.blur();
                                                                                }}
                                                                            />
                                                                        </Field>
                                                                    ),
                                                                )}
                                                            </div>
                                                        )}
                                                </section>
                                            )}
                                        </div>
                                        {editing && (
                                            <div className="border-t p-3">
                                                <Button
                                                    variant="outline"
                                                    className="w-full text-destructive hover:text-destructive"
                                                    disabled={
                                                        processing ||
                                                        !!activeItem?.locked
                                                    }
                                                    onClick={() =>
                                                        remove(active.key)
                                                    }
                                                >
                                                    <MapPinOff />
                                                    Rimuovi dal piano
                                                </Button>
                                            </div>
                                        )}
                                    </aside>
                                )}
                        </div>
                    </section>
                </div>
            </div>
            <FloorDialogs
                company={company}
                site={site}
                floors={floors}
                floor={floor}
                action={action}
                onClose={() => setAction(null)}
            />
            <FloorDeviceDialog
                key={
                    creating
                        ? `${creating.modelId}:${creating.x}:${creating.y}`
                        : 'closed'
                }
                open={!!creating}
                initialModel={creating?.modelId ?? null}
                models={models}
                manufacturers={manufacturers}
                types={types}
                canCreateModel={auth.user.is_superadmin}
                onClose={() => setCreating(null)}
                onCreate={(modelId, name, callbacks) => {
                    if (creating)
                        createDevice(
                            modelId,
                            name,
                            creating,
                            undefined,
                            callbacks,
                        );
                }}
            />
            <Dialog
                open={measured !== null}
                onOpenChange={(open) => {
                    if (open) return;
                    pendingFootprint.current = null;
                    setMeasured(null);
                }}
            >
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>Scala del piano</DialogTitle>
                    </DialogHeader>
                    <form
                        className="flex flex-col gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            applyScale(
                                Number(
                                    new FormData(event.currentTarget).get(
                                        'metres',
                                    ),
                                ),
                            );
                        }}
                    >
                        <Field>
                            <FieldLabel htmlFor="floor-scale-metres">
                                Distanza reale (m)
                            </FieldLabel>
                            <Input
                                id="floor-scale-metres"
                                name="metres"
                                type="number"
                                inputMode="decimal"
                                min={0.01}
                                step={0.01}
                                required
                                autoFocus
                            />
                        </Field>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    pendingFootprint.current = null;
                                    setMeasured(null);
                                }}
                            >
                                Annulla
                            </Button>
                            <Button type="submit">
                                <Check />
                                Applica scala
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <Dialog
                open={!!leave || discard}
                onOpenChange={(open) => {
                    if (!processing && !open) {
                        setLeave(null);
                        setDiscard(false);
                    }
                }}
            >
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            {leave
                                ? 'Salvare prima di uscire?'
                                : 'Scartare le modifiche non salvate?'}
                        </DialogTitle>
                    </DialogHeader>
                    {feedback && (
                        <Alert variant="destructive">
                            <AlertDescription>{feedback}</AlertDescription>
                        </Alert>
                    )}
                    <DialogFooter>
                        <Button
                            variant="destructive"
                            disabled={processing}
                            onClick={() => (leave ? leave() : reset())}
                        >
                            Scarta modifiche
                        </Button>
                        {leave && (
                            <SaveButton
                                type="button"
                                processing={processing}
                                onClick={() => save(leave)}
                            >
                                Salva ed esci
                            </SaveButton>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

Floors.layout = ({ company, site, floor }: Props) => ({
    breadcrumbs: [
        {
            title: 'Planimetrie',
            href: index({ company: company.id, site: site.id }),
        },
        ...(floor
            ? [
                  {
                      title: `Piano ${floor.code}`,
                      href: show({
                          company: company.id,
                          site: site.id,
                          floor: floor.id,
                      }),
                  },
              ]
            : []),
    ],
});
