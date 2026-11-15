import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ChevronsUpDown,
    Ellipsis,
    Eye,
    GripVertical,
    Map as MapIcon,
    MapPinOff,
    MapPinPlus,
    Monitor,
    Pencil,
    Plus,
    Search,
    Server,
    Tag,
    Trash2,
    Undo2,
    X,
} from 'lucide-react';
import { Fragment, useRef, useState, type ReactNode } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import { DeviceIps } from '@/components/devices/device-ips';
import {
    FLOOR_PLAN_DRAG_TYPE,
    FloorPlanCanvas,
    type FloorPlanCanvasApi,
} from '@/components/floor-plans/floor-plan-canvas';
import { FloorPlanForm } from '@/components/floor-plans/floor-plan-form';
import { IconButton } from '@/components/icon-button';
import { SaveButton } from '@/components/save-button';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Toggle } from '@/components/ui/toggle';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { ZoomControls } from '@/components/zoom-controls';
import { useLeaveGuard } from '@/hooks/use-leave-guard';
import { useSiteContext } from '@/hooks/use-site-context';
import { cn } from '@/lib/utils';
import {
    index as deviceIndex,
    show as deviceShow,
} from '@/routes/companies/sites/devices';
import {
    destroy,
    index,
    layout,
    show,
} from '@/routes/companies/sites/floor-plans';
import {
    index as rackIndex,
    show as rackShow,
} from '@/routes/companies/sites/racks';
import type { Company, Site } from '@/types/company';
import {
    itemKey,
    type FloorPlan,
    type FloorPlanDevice,
    type FloorPlanItem,
    type FloorPlanMarker,
    type FloorPlanPlacement,
    type FloorPlanRack,
    type FloorPlanSummary,
} from '@/types/floor-plan';

type Props = {
    company: Company;
    site: Site;
    editing: boolean;
    plans: FloorPlanSummary[];
    plan: FloorPlan | null;
    elsewhere: FloorPlanPlacement[];
    devices: FloorPlanDevice[];
    racks: FloorPlanRack[];
};

type Kind = FloorPlanMarker['kind'];
type Entry = {
    key: string;
    kind: Kind;
    label: string;
    detail: string;
    terms: string;
    device?: FloorPlanDevice;
    rack?: FloorPlanRack;
};

const planLabel = (plan: FloorPlanSummary) =>
    plan.floor ? `${plan.name} · ${plan.floor}` : plan.name;

export default function FloorPlans(props: Props) {
    return props.plan ? (
        <FloorPlanEditor
            key={`${props.plan.id}:${props.plan.image_url}`}
            {...props}
            plan={props.plan}
        />
    ) : (
        <NoFloorPlan {...props} />
    );
}

function Header({
    context,
    title,
    children,
}: {
    context: string;
    title: ReactNode;
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
                        {title}
                    </h1>
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">{children}</div>
        </header>
    );
}

function NoFloorPlan({ company, site }: Props) {
    const context = useSiteContext(company, site);
    const [creating, setCreating] = useState(false);
    return (
        <>
            <Head title={`Planimetrie · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <Header context={context} title="Planimetrie" />
                <Empty className="min-h-[60vh] rounded-xl border border-dashed">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <MapIcon />
                        </EmptyMedia>
                        <EmptyTitle>Nessuna planimetria</EmptyTitle>
                    </EmptyHeader>
                    <EmptyContent>
                        <Button onClick={() => setCreating(true)}>
                            <Plus />
                            Nuova planimetria
                        </Button>
                    </EmptyContent>
                </Empty>
            </div>
            <FloorPlanForm
                company={company.id}
                site={site.id}
                open={creating}
                onOpenChange={setCreating}
            />
        </>
    );
}

function FloorPlanEditor({
    company,
    site,
    editing: initialEditing,
    plans,
    plan,
    elsewhere,
    devices,
    racks,
}: Props & { plan: FloorPlan }) {
    const route = { company: company.id, site: site.id };
    const context = useSiteContext(company, site);
    const form = useForm<{ items: FloorPlanItem[] }>({ items: plan.items });
    const items = form.data.items;
    const processing = form.processing;
    const isDirty = form.isDirty;
    const [editing, setEditing] = useState(initialEditing);
    const [selected, setSelected] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [kinds, setKinds] = useState<Kind[]>(['device', 'rack']);
    const [labels, setLabels] = useState(true);
    const [tab, setTab] = useState(
        initialEditing && !plan.items.length ? 'unplaced' : 'placed',
    );
    const [zoom, setZoom] = useState(1);
    const [error, setError] = useState('');
    const [discard, setDiscard] = useState(false);
    const [formOpen, setFormOpen] = useState<'create' | 'edit' | null>(null);
    const [deleting, setDeleting] = useState(false);
    const canvas = useRef<FloorPlanCanvasApi>(null);
    const { leave, setLeave, allowLeave } = useLeaveGuard(isDirty);

    const placed = new Set(items.map(itemKey));
    const others = new Map<string, string[]>();
    for (const placement of elsewhere) {
        const key = itemKey(placement);
        others.set(key, [...(others.get(key) ?? []), placement.plan]);
    }
    const rackDevices = new Map<number, FloorPlanDevice[]>();
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
    const onPlan = matches.filter((entry) => placed.has(entry.key));
    const markers: FloorPlanMarker[] = items.flatMap((item) => {
        const entry = byKey.get(itemKey(item));
        return entry && kinds.includes(entry.kind)
            ? [{ ...item, key: entry.key, kind: entry.kind, label: entry.label }]
            : [];
    });
    const active = selected ? byKey.get(selected) : undefined;
    const feedback = error || Object.values(form.errors).join(' ');

    function setItems(next: FloorPlanItem[]) {
        setError('');
        form.clearErrors();
        form.setData('items', next);
    }
    function place(key: string, x: number, y: number) {
        if (processing) return;
        const id = Number(key.slice(2));
        setItems([
            ...items.filter((item) => itemKey(item) !== key),
            {
                device_id: key.startsWith('d-') ? id : null,
                rack_id: key.startsWith('r-') ? id : null,
                x,
                y,
            },
        ]);
        setSelected(key);
    }
    function move(key: string, x: number, y: number) {
        if (!editing || processing) return;
        setItems(
            items.map((item) =>
                itemKey(item) === key ? { ...item, x, y } : item,
            ),
        );
    }
    function remove(key: string) {
        if (!editing || processing) return;
        setItems(items.filter((item) => itemKey(item) !== key));
        setSelected(null);
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
        if (value === 'edit') setEditing(true);
        else if (isDirty) setDiscard(true);
        else setEditing(false);
    }
    function reset() {
        form.setData('items', plan.items);
        form.clearErrors();
        setError('');
        setSelected(null);
        setEditing(false);
        setDiscard(false);
    }
    function save(afterSave?: () => void) {
        if (processing) return;
        allowLeave.current = true;
        let succeeded = false;
        form.transform((data) => ({ ...data, revision: plan.revision }));
        form.put(layout.url({ ...route, floorPlan: plan.id }), {
            preserveScroll: true,
            onSuccess: (page) => {
                const saved = (page.props.plan as FloorPlan).items;
                form.setDefaults({ items: saved });
                form.setData('items', saved);
                setEditing(false);
                succeeded = true;
            },
            onFinish: () => {
                allowLeave.current = false;
                if (succeeded) afterSave?.();
            },
        });
    }

    function entryRow(entry: Entry, onPlanList: boolean) {
        const Icon = entry.kind === 'rack' ? Server : Monitor;
        const elsewhereNames = others.get(entry.key);
        const content = (
            <>
                <Icon className="size-4 shrink-0 text-muted-foreground" />
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                        {entry.label}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {entry.detail}
                        {elsewhereNames && ` · ${elsewhereNames.join(', ')}`}
                    </span>
                </span>
            </>
        );
        if (onPlanList)
            return (
                <li key={entry.key}>
                    <button
                        type="button"
                        onClick={() => locate(entry)}
                        aria-current={selected === entry.key || undefined}
                        className={cn(
                            'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring',
                            selected === entry.key &&
                                'bg-accent ring-1 ring-primary/40',
                        )}
                    >
                        {content}
                    </button>
                </li>
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
                  ['Piano', active.rack?.floor],
                  ['Anche su', others.get(active.key)?.join(', ')],
              ] as [string, ReactNode][]
          ).filter(([, value]) => value)
        : [];
    const activeRackDevices = active?.rack
        ? (rackDevices.get(active.rack.id) ?? [])
        : [];
    const activeHref = active?.device
        ? deviceShow({ ...route, device: active.device.id })
        : active?.rack
          ? rackShow({ ...route, rack: active.rack.id })
          : null;

    return (
        <>
            <Head title={`${plan.name} · Planimetrie · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-4 p-4 md:p-6">
                <Header context={context} title="Planimetrie">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="outline"
                                className="max-w-64"
                                aria-label={`Planimetria: ${planLabel(plan)}`}
                            >
                                <span className="truncate">{plan.name}</span>
                                {plan.floor && (
                                    <Badge variant="secondary">
                                        {plan.floor}
                                    </Badge>
                                )}
                                <ChevronsUpDown className="text-muted-foreground" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-64">
                            <DropdownMenuRadioGroup
                                value={String(plan.id)}
                                onValueChange={(value) =>
                                    router.visit(
                                        show({
                                            ...route,
                                            floorPlan: Number(value),
                                        }),
                                    )
                                }
                            >
                                {plans.map((item) => (
                                    <DropdownMenuRadioItem
                                        key={item.id}
                                        value={String(item.id)}
                                    >
                                        <span className="truncate">
                                            {planLabel(item)}
                                        </span>
                                    </DropdownMenuRadioItem>
                                ))}
                            </DropdownMenuRadioGroup>
                            <DropdownMenuSeparator />
                            <DropdownMenuGroup>
                                <DropdownMenuItem
                                    disabled={editing}
                                    onSelect={() => setFormOpen('create')}
                                >
                                    <Plus />
                                    Nuova planimetria
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>
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
                    {editing ? (
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
                    ) : (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <IconButton label="Azioni planimetria">
                                    <Ellipsis />
                                </IconButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    onSelect={() => setFormOpen('edit')}
                                >
                                    <Pencil />
                                    Modifica nome e immagine
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => setDeleting(true)}
                                >
                                    <Trash2 />
                                    Elimina planimetria
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </Header>
                {feedback && (
                    <Alert variant="destructive">
                        <AlertDescription>{feedback}</AlertDescription>
                    </Alert>
                )}
                <div className="grid min-w-0 gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
                    <aside className="order-2 flex max-h-[72vh] min-h-0 min-w-0 flex-col overflow-hidden rounded-xl border bg-background lg:order-1 lg:max-h-none lg:[contain:size]">
                        <div className="flex flex-col gap-3 border-b p-3">
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
                        {entries.length ? (
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
                                    <TabsTrigger value="placed">
                                        Sul piano
                                        <Badge
                                            variant="secondary"
                                            className="tabular-nums"
                                        >
                                            {onPlan.length}
                                        </Badge>
                                    </TabsTrigger>
                                </TabsList>
                                {(
                                    [
                                        ['unplaced', unplaced],
                                        ['placed', onPlan],
                                    ] as const
                                ).map(([value, list]) => (
                                    <TabsContent
                                        key={value}
                                        value={value}
                                        className="m-0 min-h-0 flex-1 overflow-y-auto p-3"
                                    >
                                        {list.length ? (
                                            <ul
                                                className={cn(
                                                    'flex flex-col',
                                                    value === 'unplaced'
                                                        ? 'gap-2'
                                                        : 'gap-0.5',
                                                )}
                                            >
                                                {list.map((entry) =>
                                                    entryRow(
                                                        entry,
                                                        value === 'placed',
                                                    ),
                                                )}
                                            </ul>
                                        ) : (
                                            <p className="px-2 py-6 text-center text-sm text-muted-foreground">
                                                {term
                                                    ? 'Nessun risultato'
                                                    : value === 'placed'
                                                      ? 'Nessun elemento sul piano'
                                                      : 'Tutto è posizionato'}
                                            </p>
                                        )}
                                    </TabsContent>
                                ))}
                            </Tabs>
                        ) : (
                            <Empty className="p-6">
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Nessun dispositivo o rack nella sede
                                    </EmptyTitle>
                                </EmptyHeader>
                                <EmptyContent className="flex-row justify-center">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={deviceIndex(route)}>
                                            <Monitor />
                                            Dispositivi
                                        </Link>
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={rackIndex(route)}>
                                            <Server />
                                            Rack
                                        </Link>
                                    </Button>
                                </EmptyContent>
                            </Empty>
                        )}
                    </aside>
                    <section
                        className="relative order-1 flex min-w-0 flex-col overflow-hidden rounded-xl border bg-background lg:order-2"
                        aria-label={`Planimetria ${plan.name}`}
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
                            <FloorPlanCanvas
                                imageUrl={plan.image_url}
                                width={plan.image_width}
                                height={plan.image_height}
                                markers={markers}
                                selected={selected}
                                highlighted={
                                    term
                                        ? new Set(
                                              matches.map((entry) => entry.key),
                                          )
                                        : null
                                }
                                labels={labels}
                                editing={editing && !processing}
                                onSelect={setSelected}
                                onMove={move}
                                onDrop={place}
                                onRemove={remove}
                                onZoom={setZoom}
                                onError={setError}
                                api={canvas}
                            />
                            {active && placed.has(active.key) && (
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
                                            onClick={() => setSelected(null)}
                                        >
                                            <X />
                                        </IconButton>
                                    </div>
                                    <div className="min-h-0 overflow-y-auto">
                                        {facts.length > 0 && (
                                            <dl className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-6 gap-y-2.5 px-4 py-3 text-sm">
                                                {facts.map(([label, value]) => (
                                                    <Fragment key={label}>
                                                        <dt className="text-muted-foreground">
                                                            {label}
                                                        </dt>
                                                        <dd className="flex justify-end text-right break-words tabular-nums">
                                                            {value}
                                                        </dd>
                                                    </Fragment>
                                                ))}
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
                                    </div>
                                    {editing && (
                                        <div className="border-t p-3">
                                            <Button
                                                variant="outline"
                                                className="w-full text-destructive hover:text-destructive"
                                                disabled={processing}
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
            {formOpen && (
                <FloorPlanForm
                    company={company.id}
                    site={site.id}
                    plan={formOpen === 'edit' ? plan : undefined}
                    open
                    onOpenChange={(open) => {
                        if (!open) setFormOpen(null);
                    }}
                />
            )}
            <Dialog open={deleting} onOpenChange={setDeleting}>
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>Elimina {plan.name}</DialogTitle>
                    </DialogHeader>
                    <DeletionConfirmation
                        action={destroy.form({ ...route, floorPlan: plan.id })}
                        onCancel={() => setDeleting(false)}
                        onSuccess={() => setDeleting(false)}
                        submitLabel="Elimina planimetria"
                        fields={() => (
                            <input
                                type="hidden"
                                name="revision"
                                value={plan.revision}
                            />
                        )}
                    >
                        Verranno eliminate l’immagine e {plan.items.length}{' '}
                        posizioni. Dispositivi e rack resteranno documentati.
                    </DeletionConfirmation>
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

FloorPlans.layout = ({ company, site, plan }: Props) => ({
    breadcrumbs: [
        {
            title: 'Planimetrie',
            href: index({ company: company.id, site: site.id }),
        },
        ...(plan
            ? [
                  {
                      title: plan.name,
                      href: show({
                          company: company.id,
                          site: site.id,
                          floorPlan: plan.id,
                      }),
                  },
              ]
            : []),
    ],
});
