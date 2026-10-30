import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    ArrowLeft,
    ArrowDown,
    ArrowUp,
    Archive,
    Check,
    Maximize,
    Minus,
    Plus,
    Save,
    Search,
    Server,
    Settings2,
    Trash2,
    Undo2,
} from 'lucide-react';
import { RackCanvas, type RackCanvasApi } from '@/components/racks/rack-canvas';
import {
    NumberField,
    Choice,
} from '@/components/hardware-editor/editor-fields';
import { IconButton } from '@/components/icon-button';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Separator } from '@/components/ui/separator';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuGroup,
    ContextMenuItem,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import { index, show, update } from '@/routes/companies/sites/racks';
import { show as showDevice } from '@/routes/companies/sites/devices';
import {
    isRackMounted,
    itemBounds,
    itemName,
    layoutError,
    moveItem,
    supportChain,
    unitLabel,
} from '@/lib/rack-layout';
import { newId, type Face } from '@/lib/hardware-editor/document';
import { cn } from '@/lib/utils';
import type { Company, Site } from '@/types/company';
import type { Rack, RackDevice, RackItem } from '@/types/rack';

type Props = {
    company: Company;
    site: Site;
    rack: Rack;
    devices: RackDevice[];
};
type Draft = Pick<Rack, 'name' | 'height_u' | 'numbering' | 'revision'> & {
    items: RackItem[];
    removed_keys: string[];
};
function draftOf(rack: Rack): Draft {
    return {
        name: rack.name,
        height_u: rack.height_u,
        numbering: rack.numbering,
        revision: rack.revision,
        items: rack.items.map(
            ({
                key,
                kind,
                device_id,
                shelf_key,
                position,
                height,
                width,
                x,
            }) => ({
                key,
                kind,
                device_id,
                shelf_key,
                position,
                height,
                width,
                x,
            }),
        ),
        removed_keys: [],
    };
}

export default function RackShow(props: Props) {
    return <RackEditor key={props.rack.id} {...props} />;
}
function RackEditor({ company, site, rack, devices }: Props) {
    const { companyNavigation } = usePage().props;
    const route = { company: company.id, site: site.id, rack: rack.id };
    const context =
        (companyNavigation?.sites.length ?? 0) > 1
            ? `${company.name} · ${site.name}`
            : company.name;
    const form = useForm<Draft>(draftOf(rack));
    const { data, setData, processing, errors, isDirty } = form;
    const [face, setFace] = useState<Face>('front');
    const [zoom, setZoom] = useState(1);
    const canvas = useRef<RackCanvasApi>(null);
    const [selectedKey, setSelectedKey] = useState<string | null>(null);
    const [tab, setTab] = useState(rack.items.length ? 'add' : 'properties');
    const [search, setSearch] = useState('');
    const [supportKey, setSupportKey] = useState('');
    const [error, setError] = useState('');
    const [imageError, setImageError] = useState('');
    const [fieldRevision, setFieldRevision] = useState(0);
    const [leave, setLeave] = useState<(() => void) | null>(null);
    const [discard, setDiscard] = useState(false);
    const allowLeave = useRef(false);
    const selected = data.items.find((item) => item.key === selectedKey);
    const selectedDevice = devices.find(
        (device) => device.id === selected?.device_id,
    );
    const supports = data.items.filter(
        (item) => item.kind === 'shelf' || item.kind === 'device',
    );
    const selectedSupports = supports.filter(
        (item) =>
            item.key !== selectedKey &&
            !supportChain(item, data.items)?.some(
                (support) => support.key === selectedKey,
            ),
    );
    function supportLabel(item: RackItem) {
        return `${itemName(item, devices)} · U ${unitLabel(itemBounds(item, data.items).bottom, data)}`;
    }
    const available = devices.filter(
        (device) =>
            !data.items.some((item) => item.device_id === device.id) &&
            (!device.rack_item || device.rack_item.rack_id === rack.id),
    );
    const matches = available.filter((device) =>
        [
            device.name,
            device.model?.name,
            device.manufacturer.name,
            device.serial_number,
        ]
            .join(' ')
            .toLocaleLowerCase()
            .includes(search.trim().toLocaleLowerCase()),
    );
    const feedback = error || Object.values(errors).join(' ') || imageError;

    useEffect(() => {
        const stop = router.on('before', (event) => {
            if (!isDirty || allowLeave.current) return;
            event.preventDefault();
            const visit = event.detail.visit;
            setLeave(() => () => {
                allowLeave.current = true;
                setLeave(null);
                router.visit(visit.url, {
                    ...visit,
                    preserveState: false,
                    onFinish: () => {
                        allowLeave.current = false;
                    },
                });
            });
        });
        const unload = (event: BeforeUnloadEvent) => {
            if (isDirty && !allowLeave.current) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        const currentUrl = window.location.href;
        const currentState = window.history.state;
        const pop = (event: PopStateEvent) => {
            if (!isDirty || allowLeave.current) return;
            event.stopImmediatePropagation();
            window.history.pushState(currentState, '', currentUrl);
            setLeave(() => () => {
                allowLeave.current = true;
                setLeave(null);
                window.history.back();
            });
        };
        window.addEventListener('beforeunload', unload);
        window.addEventListener('popstate', pop, true);
        return () => {
            stop();
            window.removeEventListener('beforeunload', unload);
            window.removeEventListener('popstate', pop, true);
        };
    }, [isDirty]);

    function select(key: string | null) {
        setSelectedKey(key);
        setTab('properties');
    }
    function change(items: RackItem[], height = data.height_u) {
        const problem = layoutError(items, height, devices);
        if (problem) {
            setError(problem);
            setFieldRevision((value) => value + 1);
            return false;
        }
        setError('');
        form.clearErrors();
        setData((current) => ({
            ...current,
            items,
            height_u: height,
            removed_keys: rack.items
                .filter((item) => !items.some((next) => next.key === item.key))
                .map((item) => item.key),
        }));
        return true;
    }
    function move(key: string, position: number, x?: number) {
        if (!processing) change(moveItem(data.items, key, position, x));
    }
    function patch(patch: Partial<RackItem>) {
        if (selected)
            change(
                moveItem(
                    data.items,
                    selected.key,
                    patch.position ?? selected.position,
                    patch.x,
                ).map((item) =>
                    item.key === selected.key ? { ...item, ...patch } : item,
                ),
            );
    }
    function add(kind: RackItem['kind'], device?: RackDevice) {
        if (processing) return;
        const support =
            device && !isRackMounted(device)
                ? supports.find((item) => item.key === supportKey)
                : null;
        if (device && !isRackMounted(device) && !support) {
            setError('Scegli un ripiano o un dispositivo come appoggio.');
            return;
        }
        const item: RackItem = {
            key: newId(),
            kind,
            device_id: device?.id ?? null,
            shelf_key: support?.key ?? null,
            position: support?.position ?? 0,
            height: device ? (device.model?.rack_height_u ?? 2) : 1,
            width: support ? Math.min(40, support.width) : 100,
            x: support?.x ?? 0,
        };
        // Find the first fitting physical position; never displace existing elements.
        const attempts = support
            ? Math.floor(support.width - item.width) + 1
            : data.height_u;
        for (let position = 0; position < attempts; position++) {
            const candidate = {
                ...item,
                ...(support ? { x: support.x + position } : { position }),
            };
            if (
                !layoutError([...data.items, candidate], data.height_u, devices)
            ) {
                change([...data.items, candidate]);
                select(candidate.key);
                if (kind !== 'drawer') setSupportKey(candidate.key);
                return;
            }
        }
        setError(
            support
                ? 'Spazio insufficiente sopra questo appoggio. Sposta gli elementi presenti o scegli un altro appoggio.'
                : 'Nessuna posizione libera sufficiente. Sposta gli elementi presenti o aumenta l’altezza del rack.',
        );
    }
    function remove() {
        if (!selected || processing) return;
        const supported = data.items.filter(
            (item) => item.shelf_key === selected.key,
        );
        if (supported.length) {
            setError(
                `«${itemName(selected, devices)}» sostiene ${supported.map((item) => `«${itemName(item, devices)}»`).join(', ')}. Sposta o rimuovi prima questi dispositivi dal rack.`,
            );
            setTab('properties');
            return;
        }
        change(data.items.filter((item) => item.key !== selected.key));
        setSelectedKey(null);
    }
    function exitEditor() {
        allowLeave.current = true;
        router.visit(index(route), {
            onFinish: () => {
                allowLeave.current = false;
            },
        });
    }
    function save(afterSave?: () => void) {
        if (processing) return;
        const problem = layoutError(data.items, data.height_u, devices);
        if (problem) {
            setError(problem);
            return;
        }
        allowLeave.current = true;
        let succeeded = false;
        form.put(update.url(route), {
            preserveScroll: true,
            onSuccess: (page) => {
                const saved = draftOf(page.props.rack as Rack);
                form.setData(saved);
                form.setDefaults(saved);
                setError('');
                succeeded = true;
            },
            onFinish: () => {
                allowLeave.current = false;
                if (succeeded) afterSave?.();
            },
        });
    }
    function reset() {
        const saved = draftOf(rack);
        form.setData(saved);
        form.setDefaults(saved);
        form.clearErrors();
        setError('');
        setSelectedKey(null);
        setDiscard(false);
        setFieldRevision((value) => value + 1);
    }
    const positionLabel = selected ? unitLabel(selected.position, data) : 1;
    return (
        <>
            <Head title={`${data.name} · Rack · ${context}`} />
            <div className="flex min-w-0 flex-col gap-4 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                        <IconButton label="Torna ai rack" asChild>
                            <Link href={index(route)}>
                                <ArrowLeft />
                            </Link>
                        </IconButton>
                        <div className="min-w-0">
                            <p className="truncate text-sm text-muted-foreground">
                                {context}
                            </p>
                            <h1 className="truncate text-xl font-semibold tracking-tight">
                                {data.name}
                            </h1>
                        </div>
                        <Badge variant="secondary">{data.height_u}U</Badge>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span
                            className="hidden items-center gap-1 text-xs text-muted-foreground sm:flex"
                            aria-live="polite"
                        >
                            {isDirty ? (
                                'Modifiche da salvare'
                            ) : (
                                <>
                                    <Check className="size-3.5" />
                                    Salvato
                                </>
                            )}
                        </span>
                        <IconButton
                            label="Annulla modifiche"
                            disabled={!isDirty || processing}
                            onClick={() => setDiscard(true)}
                        >
                            <Undo2 />
                        </IconButton>
                        <Button
                            onClick={() => save()}
                            disabled={!isDirty || processing}
                        >
                            <Save data-icon="inline-start" />
                            {processing ? 'Salvataggio…' : 'Salva'}
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => save(exitEditor)}
                            disabled={processing}
                        >
                            Salva ed esci
                        </Button>
                    </div>
                </header>
                {feedback && (
                    <Alert variant="destructive">
                        <AlertDescription>{feedback}</AlertDescription>
                    </Alert>
                )}
                <div className="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_19rem]">
                    <section className="min-w-0 overflow-hidden rounded-xl border">
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-background p-2">
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={face}
                                onValueChange={(value) => {
                                    if (value) setFace(value as Face);
                                }}
                                aria-label="Vista rack"
                            >
                                <ToggleGroupItem value="front">
                                    Frontale
                                </ToggleGroupItem>
                                <ToggleGroupItem value="rear">
                                    Retro
                                </ToggleGroupItem>
                            </ToggleGroup>
                            <div className="flex items-center gap-1">
                                <IconButton
                                    label="Riduci zoom"
                                    onClick={() =>
                                        canvas.current?.zoom(1 / 1.25)
                                    }
                                >
                                    <Minus />
                                </IconButton>
                                <span className="w-12 text-center text-xs text-muted-foreground tabular-nums">
                                    {Math.round(zoom * 100)}%
                                </span>
                                <IconButton
                                    label="Aumenta zoom"
                                    onClick={() => canvas.current?.zoom(1.25)}
                                >
                                    <Plus />
                                </IconButton>
                                <IconButton
                                    label="Adatta rack"
                                    onClick={() => canvas.current?.fit()}
                                >
                                    <Maximize />
                                </IconButton>
                            </div>
                        </div>
                        <ContextMenu>
                            <ContextMenuTrigger
                                render={<div />}
                                tabIndex={0}
                                aria-label="Editor rack. Usa le proprietà per modificare posizione e dimensioni."
                                onKeyDown={(event) => {
                                    if (
                                        event.target !== event.currentTarget ||
                                        processing
                                    )
                                        return;
                                    if (event.key === 'Delete' && selected) {
                                        event.preventDefault();
                                        remove();
                                    }
                                    if (
                                        selected &&
                                        !selected.shelf_key &&
                                        ['ArrowUp', 'ArrowDown'].includes(
                                            event.key,
                                        )
                                    ) {
                                        event.preventDefault();
                                        move(
                                            selected.key,
                                            selected.position +
                                                (event.key === 'ArrowUp'
                                                    ? 1
                                                    : -1),
                                        );
                                    }
                                }}
                            >
                                <RackCanvas
                                    rack={data}
                                    items={data.items}
                                    devices={devices}
                                    face={face}
                                    selected={selectedKey}
                                    api={canvas}
                                    onZoom={setZoom}
                                    onSelect={select}
                                    onMove={move}
                                    onPlace={(items) => {
                                        if (!processing) change(items);
                                    }}
                                    onError={setImageError}
                                    disabled={processing}
                                />
                            </ContextMenuTrigger>
                            <ContextMenuContent>
                                <ContextMenuGroup>
                                    <ContextMenuItem
                                        onClick={() => {
                                            setTab('properties');
                                        }}
                                    >
                                        <Settings2 />
                                        Proprietà
                                    </ContextMenuItem>
                                    {selected && (
                                        <ContextMenuItem
                                            onClick={remove}
                                            disabled={processing}
                                            variant="destructive"
                                        >
                                            <Trash2 />
                                            Rimuovi dal rack
                                        </ContextMenuItem>
                                    )}
                                    <ContextMenuItem
                                        onClick={() => canvas.current?.fit()}
                                    >
                                        <Maximize />
                                        Adatta rack
                                    </ContextMenuItem>
                                </ContextMenuGroup>
                            </ContextMenuContent>
                        </ContextMenu>
                    </section>
                    <aside className="flex min-w-0 flex-col overflow-hidden rounded-xl border bg-background">
                        <Tabs
                            value={tab}
                            onValueChange={setTab}
                            className="gap-0"
                        >
                            <TabsList
                                className="m-3"
                                aria-label="Strumenti rack"
                            >
                                <TabsTrigger value="add">
                                    <Plus />
                                    Aggiungi
                                </TabsTrigger>
                                <TabsTrigger value="properties">
                                    <Settings2 />
                                    Proprietà
                                </TabsTrigger>
                            </TabsList>
                            <TabsContent
                                value="add"
                                className="m-0 flex flex-col gap-4 p-4 pt-1"
                            >
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        className="flex-1"
                                        disabled={processing}
                                        onClick={() => add('shelf')}
                                    >
                                        <Plus />
                                        Ripiano
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="flex-1"
                                        disabled={processing}
                                        onClick={() => add('drawer')}
                                    >
                                        <Archive />
                                        Cassetto
                                    </Button>
                                </div>
                                <Separator />
                                <h2 className="text-sm font-semibold">
                                    Dispositivi disponibili
                                </h2>
                                <InputGroup>
                                    <InputGroupAddon>
                                        <Search />
                                    </InputGroupAddon>
                                    <InputGroupInput
                                        aria-label="Cerca dispositivi"
                                        placeholder="Nome, modello o seriale…"
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                    />
                                </InputGroup>
                                {supports.length > 0 && (
                                    <Choice
                                        label="Appoggio per dispositivi senza staffe"
                                        value={
                                            supports.some(
                                                (item) =>
                                                    item.key === supportKey,
                                            )
                                                ? supportKey
                                                : ''
                                        }
                                        choices={Object.fromEntries(
                                            supports.map((item) => [
                                                item.key,
                                                supportLabel(item),
                                            ]),
                                        )}
                                        onChange={setSupportKey}
                                        disabled={processing}
                                    />
                                )}
                                <div className="flex max-h-80 flex-col gap-2 overflow-y-auto">
                                    {matches.map((device) => (
                                        <button
                                            type="button"
                                            key={device.id}
                                            disabled={processing}
                                            onClick={() =>
                                                add('device', device)
                                            }
                                            className="flex min-h-16 items-center gap-3 rounded-lg border p-3 text-left hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50"
                                        >
                                            <Server className="size-4 shrink-0 text-muted-foreground" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium">
                                                    {device.name}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {device.manufacturer.name} ·{' '}
                                                    {device.model?.name}
                                                </span>
                                            </span>
                                            <Badge variant="outline">
                                                {isRackMounted(device)
                                                    ? `${device.model?.rack_height_u}U`
                                                    : device.model
                                                            ?.rack_height_u
                                                      ? `${device.model.rack_height_u}U · Da appoggiare`
                                                      : 'Da appoggiare'}
                                            </Badge>
                                        </button>
                                    ))}
                                    {!matches.length && (
                                        <Empty className="p-4">
                                            <EmptyHeader>
                                                <EmptyTitle>
                                                    Nessun dispositivo
                                                    disponibile
                                                </EmptyTitle>
                                            </EmptyHeader>
                                        </Empty>
                                    )}
                                </div>
                            </TabsContent>
                            <TabsContent
                                value="properties"
                                className="m-0 p-4 pt-1"
                            >
                                <FieldGroup
                                    key={`${selectedKey}-${fieldRevision}`}
                                    className="gap-4"
                                >
                                    {selected ? (
                                        <>
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <h2 className="text-sm font-semibold">
                                                        {itemName(
                                                            selected,
                                                            devices,
                                                        )}
                                                    </h2>
                                                    <p className="text-xs text-muted-foreground">
                                                        {selected.shelf_key
                                                            ? 'Appoggiato'
                                                            : selected.kind ===
                                                                'shelf'
                                                              ? 'Alette 1U'
                                                              : `${selected.height}U`}
                                                    </p>
                                                </div>
                                                {selectedDevice && (
                                                    <IconButton
                                                        label="Apri dispositivo"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={showDevice({
                                                                ...route,
                                                                device: selectedDevice.id,
                                                            })}
                                                        >
                                                            <Server />
                                                        </Link>
                                                    </IconButton>
                                                )}
                                            </div>
                                            {selected.shelf_key ? (
                                                <>
                                                    <Choice
                                                        label="Appoggio"
                                                        value={
                                                            selected.shelf_key
                                                        }
                                                        choices={Object.fromEntries(
                                                            selectedSupports.map(
                                                                (item) => [
                                                                    item.key,
                                                                    supportLabel(
                                                                        item,
                                                                    ),
                                                                ],
                                                            ),
                                                        )}
                                                        onChange={(key) => {
                                                            const support =
                                                                supports.find(
                                                                    (item) =>
                                                                        item.key ===
                                                                        key,
                                                                );
                                                            if (support)
                                                                patch({
                                                                    shelf_key:
                                                                        key,
                                                                    position:
                                                                        support.position,
                                                                    x: support.x,
                                                                });
                                                        }}
                                                        disabled={processing}
                                                    />
                                                    <NumberField
                                                        label="Altezza grafica (U)"
                                                        value={selected.height}
                                                        min={0.25}
                                                        max={data.height_u}
                                                        step={0.25}
                                                        onChange={(height) =>
                                                            patch({ height })
                                                        }
                                                        disabled={processing}
                                                    />
                                                    <NumberField
                                                        label="Larghezza (%)"
                                                        value={selected.width}
                                                        min={5}
                                                        max={100}
                                                        onChange={(width) =>
                                                            patch({ width })
                                                        }
                                                        disabled={processing}
                                                    />
                                                    <NumberField
                                                        label="Posizione orizzontale (%)"
                                                        value={selected.x}
                                                        min={0}
                                                        max={95}
                                                        onChange={(x) =>
                                                            patch({ x })
                                                        }
                                                        disabled={processing}
                                                    />
                                                </>
                                            ) : (
                                                <>
                                                    <NumberField
                                                        label="Unità alla base"
                                                        value={positionLabel}
                                                        min={1}
                                                        max={data.height_u}
                                                        onChange={(unit) =>
                                                            move(
                                                                selected.key,
                                                                data.numbering ===
                                                                    'bottom-up'
                                                                    ? unit - 1
                                                                    : data.height_u -
                                                                          unit,
                                                            )
                                                        }
                                                        disabled={processing}
                                                    />
                                                    <div className="flex gap-2">
                                                        <Button
                                                            variant="outline"
                                                            className="flex-1"
                                                            disabled={
                                                                processing
                                                            }
                                                            onClick={() =>
                                                                move(
                                                                    selected.key,
                                                                    selected.position +
                                                                        1,
                                                                )
                                                            }
                                                        >
                                                            <ArrowUp />
                                                            Su
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            className="flex-1"
                                                            disabled={
                                                                processing
                                                            }
                                                            onClick={() =>
                                                                move(
                                                                    selected.key,
                                                                    selected.position -
                                                                        1,
                                                                )
                                                            }
                                                        >
                                                            <ArrowDown />
                                                            Giù
                                                        </Button>
                                                    </div>
                                                    {selected.kind ===
                                                        'drawer' && (
                                                        <NumberField
                                                            label="Altezza (U)"
                                                            value={
                                                                selected.height
                                                            }
                                                            min={1}
                                                            max={data.height_u}
                                                            onChange={(
                                                                height,
                                                            ) =>
                                                                patch({
                                                                    height,
                                                                })
                                                            }
                                                            disabled={
                                                                processing
                                                            }
                                                        />
                                                    )}
                                                </>
                                            )}
                                            <Button
                                                variant="outline"
                                                onClick={remove}
                                                disabled={processing}
                                            >
                                                <Trash2 />
                                                Rimuovi dal rack
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                onClick={() => select(null)}
                                            >
                                                Proprietà del rack
                                            </Button>
                                        </>
                                    ) : (
                                        <>
                                            <h2 className="text-sm font-semibold">
                                                Proprietà del rack
                                            </h2>
                                            <Field>
                                                <FieldLabel htmlFor="rack-name">
                                                    Nome
                                                </FieldLabel>
                                                <Input
                                                    id="rack-name"
                                                    value={data.name}
                                                    maxLength={255}
                                                    required
                                                    onChange={(event) =>
                                                        setData(
                                                            'name',
                                                            event.target.value,
                                                        )
                                                    }
                                                    disabled={processing}
                                                />
                                            </Field>
                                            <NumberField
                                                label="Altezza (U)"
                                                value={data.height_u}
                                                min={1}
                                                max={100}
                                                onChange={(height) =>
                                                    change(data.items, height)
                                                }
                                                disabled={processing}
                                            />
                                            <Choice
                                                label="Numerazione U"
                                                value={data.numbering}
                                                choices={{
                                                    'bottom-up':
                                                        'Dal basso verso l’alto',
                                                    'top-down':
                                                        'Dall’alto verso il basso',
                                                }}
                                                onChange={(numbering) =>
                                                    setData(
                                                        'numbering',
                                                        numbering as Rack['numbering'],
                                                    )
                                                }
                                                disabled={processing}
                                            />
                                        </>
                                    )}
                                </FieldGroup>
                            </TabsContent>
                        </Tabs>
                        <Separator />
                        <div className="flex flex-col gap-2 p-4">
                            <div className="flex items-center justify-between">
                                <h2 className="text-sm font-semibold">
                                    Nel rack
                                </h2>
                                <Badge variant="secondary">
                                    {data.items.length}
                                </Badge>
                            </div>
                            <div className="flex max-h-64 flex-col gap-1 overflow-y-auto">
                                {[...data.items]
                                    .sort(
                                        (a, b) =>
                                            itemBounds(b, data.items).bottom -
                                            itemBounds(a, data.items).bottom,
                                    )
                                    .map((item) => (
                                        <button
                                            type="button"
                                            key={item.key}
                                            onClick={() => select(item.key)}
                                            aria-pressed={
                                                selectedKey === item.key
                                            }
                                            className={cn(
                                                'flex min-h-10 items-center gap-2 rounded-md px-2 py-2 text-left text-sm hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring',
                                                selectedKey === item.key &&
                                                    'bg-accent text-accent-foreground',
                                            )}
                                        >
                                            <span className="w-10 shrink-0 text-xs text-muted-foreground tabular-nums">
                                                U{' '}
                                                {unitLabel(
                                                    itemBounds(item, data.items)
                                                        .bottom,
                                                    data,
                                                )}
                                            </span>
                                            <span className="truncate">
                                                {item.shelf_key ? '↳ ' : ''}
                                                {itemName(item, devices)}
                                            </span>
                                        </button>
                                    ))}
                                {!data.items.length && (
                                    <p className="py-2 text-sm text-muted-foreground">
                                        Nessun elemento
                                    </p>
                                )}
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
            <Dialog
                open={!!leave || discard}
                onOpenChange={(open) => {
                    if (!processing && !open) {
                        setLeave(null);
                        setDiscard(false);
                    }
                }}
            >
                <DialogContent
                    className="sm:max-w-2xl"
                    aria-describedby={undefined}
                >
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
                            <Button
                                disabled={processing}
                                onClick={() => save(leave)}
                            >
                                {processing ? 'Salvataggio…' : 'Salva ed esci'}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
RackShow.layout = ({ company, site, rack }: Props) => ({
    breadcrumbs: [
        { title: 'Rack', href: index({ company: company.id, site: site.id }) },
        {
            title: rack.name,
            href: show({ company: company.id, site: site.id, rack: rack.id }),
        },
    ],
});
