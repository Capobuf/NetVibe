import { SaveButton } from '@/components/save-button';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    ArrowLeft,
    ArrowDown,
    ArrowUp,
    Archive,
    Maximize,
    Minus,
    Plus,
    Search,
    Server,
    Settings2,
    Trash2,
    Undo2,
    Cable as CableIcon,
    Eye,
    Hand,
    Pencil,
    Printer,
} from 'lucide-react';
import { RackCableTable } from '@/components/racks/rack-cable-table';
import { RackFloorField } from '@/components/racks/rack-floor-field';
import { CableInspector } from '@/components/racks/cable-inspector';
import { CableCreateDialog } from '@/components/racks/cable-create-dialog';
import { DevicePortsList } from '@/components/racks/device-ports-list';
import {
    cableCode,
    cableError,
    bundleKey,
    devicePorts,
    endKey,
    endLabel,
    normalizeCableType,
    type Cable,
    type CableEnd,
} from '@/lib/rack-cabling';
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
    DialogTrigger,
    DialogClose,
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
import {
    documentation,
    index,
    show,
    update,
} from '@/routes/companies/sites/racks';
import {
    store as storeCable,
    labels as cableLabels,
} from '@/routes/companies/sites/racks/cables';
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
    cables: Cable[];
    cablingRevision: number;
    editing: boolean;
    topologySuggestion: { a: CableEnd; b: CableEnd } | null;
    topologyNotice: string | null;
};
type Draft = Pick<
    Rack,
    'name' | 'floor' | 'height_u' | 'numbering' | 'revision'
> & {
    items: RackItem[];
    removed_keys: string[];
    cables: Cable[];
    cabling_revision: number;
    removed_cable_ids: number[];
};
type Deletion = { index: number } & (
    | { kind: 'item'; item: RackItem }
    | { kind: 'cable'; cable: Cable }
);
function draftOf(rack: Rack, cables: Cable[], cablingRevision: number): Draft {
    return {
        cables,
        cabling_revision: cablingRevision,
        removed_cable_ids: [],
        name: rack.name,
        floor: rack.floor,
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
function RackEditor({
    company,
    site,
    rack,
    devices,
    cables,
    cablingRevision,
    editing: initialEditing,
    topologySuggestion,
    topologyNotice,
}: Props) {
    const { companyNavigation } = usePage().props;
    const route = { company: company.id, site: site.id, rack: rack.id };
    const context =
        (companyNavigation?.sites.length ?? 0) > 1
            ? `${company.name} · ${site.name}`
            : company.name;
    const form = useForm<Draft>(draftOf(rack, cables, cablingRevision));
    const { data, setData, errors } = form;
    const [savingConnection, setSavingConnection] = useState(false);
    const processing = form.processing || savingConnection;
    const [editing, setEditing] = useState(initialEditing);
    const [selectedCableId, setSelectedCableId] = useState<number | null>(null);
    const [focusedBundle, setFocusedBundle] = useState<string | null>(null);
    const [cableDraft, setCableDraft] = useState<Cable | null>(null);
    const [selectedPort, setSelectedPort] = useState<CableEnd | null>(null);
    const [disconnecting, setDisconnecting] = useState<Cable | null>(null);
    const [creatingCable, setCreatingCable] = useState<number | null>(
        topologySuggestion ? -1 : null,
    );
    const [modalDirty, setModalDirty] = useState(false);
    const nextCableId = useRef(topologySuggestion ? -2 : -1);
    const deletions = useRef<Deletion[]>([]);
    const isDirty = form.isDirty || cableDraft !== null || modalDirty;
    const selectedCable = data.cables.find((c) => c.id === selectedCableId);
    const rackDeviceIds = new Set(data.items.map((item) => item.device_id));
    const savedRackDeviceIds = new Set(
        rack.items.map((item) => item.device_id),
    );
    const relevantCables = data.cables.filter(
        (c) =>
            rackDeviceIds.has(c.a.device_id) ||
            rackDeviceIds.has(c.b.device_id) ||
            savedRackDeviceIds.has(c.a.device_id) ||
            savedRackDeviceIds.has(c.b.device_id),
    );
    const focusedCables = data.cables.filter(
        (c) => bundleKey(c) === focusedBundle,
    );
    const [face, setFace] = useState<Face | 'both'>('both');
    const [pan, setPan] = useState(false);
    const [zoom, setZoom] = useState(1);
    const canvas = useRef<RackCanvasApi>(null);
    const [selectedKey, setSelectedKey] = useState<string | null>(null);
    const [tab, setTab] = useState('properties');
    const [search, setSearch] = useState('');
    const [supportKey, setSupportKey] = useState('');
    const [error, setError] = useState('');
    const [imageError, setImageError] = useState('');
    const [fieldRevision, setFieldRevision] = useState(0);
    const [leave, setLeave] = useState<(() => void) | null>(null);
    const [discard, setDiscard] = useState(false);
    const [printingDocument, setPrintingDocument] = useState(false);
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
    const cableDevices = devices.map((device) => ({
        ...device,
        rack_item: rackDeviceIds.has(device.id)
            ? { rack_id: rack.id, rack: { id: rack.id, name: data.name } }
            : device.rack_item?.rack_id === rack.id
              ? null
              : device.rack_item,
    }));
    const feedback =
        error ||
        Object.values(errors).join(' ') ||
        imageError ||
        topologyNotice;

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
        if (cableDraft) return;
        setSelectedCableId(null);
        setFocusedBundle(null);
        setSelectedPort(null);
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
        if (editing && !processing)
            change(moveItem(data.items, key, position, x));
    }
    function patch(patch: Partial<RackItem>) {
        if (editing && selected)
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
        if (!editing || processing) return;
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
        if (!editing || !selected || processing) return;
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
        if (!change(data.items.filter((item) => item.key !== selected.key)))
            return;
        deletions.current.push({
            kind: 'item',
            item: selected,
            index: data.items.indexOf(selected),
        });
        setSelectedKey(null);
    }
    function disconnect(cable: Cable) {
        if (!editing || processing) return;
        deletions.current.push({
            kind: 'cable',
            cable,
            index: data.cables.indexOf(cable),
        });
        setData((current) => ({
            ...current,
            cables: current.cables.filter((item) => item.id !== cable.id),
            removed_cable_ids:
                cable.id > 0
                    ? [...current.removed_cable_ids, cable.id]
                    : current.removed_cable_ids,
        }));
        setDisconnecting(null);
        setSelectedCableId(null);
        setSelectedPort(null);
        setFocusedBundle(null);
        setError('');
    }
    function undoDeletion() {
        const deletion = deletions.current.at(-1);
        if (!deletion || processing || !editing || cableDraft) return;
        if (deletion.kind === 'item') {
            if (!change(data.items.toSpliced(deletion.index, 0, deletion.item)))
                return;
            select(deletion.item.key);
        } else {
            const problem = cableError(deletion.cable, data.cables, devices);
            if (problem) {
                setError(problem);
                return;
            }
            setData((current) => ({
                ...current,
                cables: current.cables.toSpliced(
                    deletion.index,
                    0,
                    deletion.cable,
                ),
                removed_cable_ids: current.removed_cable_ids.filter(
                    (id) => id !== deletion.cable.id,
                ),
            }));
            selectCable(deletion.cable);
            setError('');
        }
        deletions.current.pop();
    }
    function printCable(cable: Cable) {
        if (isDirty) {
            setError(
                'Salva le modifiche al rack e al cablaggio prima di preparare le etichette.',
            );
            return;
        }
        router.visit(cableLabels.url({ ...route, cableId: cable.id }));
    }
    function selectCable(cable: Cable) {
        if (cableDraft) return;
        setSelectedKey(null);
        setSelectedCableId(cable.id);
        setFocusedBundle(bundleKey(cable));
        setSelectedPort(null);
        setTab('cables');
    }
    function selectBundle(key: string | null) {
        if (cableDraft) return;
        setSelectedKey(null);
        setFocusedBundle(key);
        setSelectedCableId(null);
        setTab('cables');
    }
    function startCable(a?: CableEnd, b?: CableEnd) {
        if (!editing || processing) return;
        setSelectedKey(null);
        const draft = normalizeCableType(
            {
                id: nextCableId.current--,
                a: a ?? { device_id: 0, connector_id: '' },
                b: b ?? { device_id: 0, connector_id: '' },
                type: 'copper',
                color: '#2563eb',
                purpose: null,
                name: null,
            },
            devices,
        );
        if (a && b) {
            if (
                !savedRackDeviceIds.has(a.device_id) &&
                !savedRackDeviceIds.has(b.device_id)
            ) {
                setError(
                    'Salva prima la disposizione dei nuovi dispositivi nel rack, poi collega il cavo.',
                );
                return;
            }
            const problem = cableError(draft, data.cables, devices);
            if (problem) {
                setError(problem);
                return;
            }
            setError('');
            setSavingConnection(true);
            allowLeave.current = true;
            router.post(
                storeCable.url(route),
                {
                    ...draft,
                    cabling_revision: data.cabling_revision,
                    removed_cable_ids: data.removed_cable_ids,
                },
                {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        const saved = page.props.cables as Cable[];
                        acceptCreatedCables(
                            saved,
                            page.props.cablingRevision as number,
                        );
                        const created = saved.find(
                            (cable) =>
                                endKey(cable.a) === endKey(a) &&
                                endKey(cable.b) === endKey(b),
                        );
                        setCableDraft(null);
                        setSelectedPort(null);
                        setSelectedCableId(created?.id ?? null);
                        setFocusedBundle(created ? bundleKey(created) : null);
                        setTab('cables');
                    },
                    onError: (errors) =>
                        setError(Object.values(errors).join(' ')),
                    onFinish: () => {
                        setSavingConnection(false);
                        allowLeave.current = false;
                    },
                },
            );
            return;
        }
        setPan(false);
        setCableDraft(draft);
        setSelectedCableId(null);
        setSelectedPort(null);
        setFocusedBundle(null);
        setTab('cables');
        setError('');
    }
    function acceptCreatedCables(savedCables: Cable[], revision: number) {
        const savedIds = new Set(savedCables.map((cable) => cable.id));
        for (const deletion of deletions.current) {
            if (
                deletion.kind === 'cable' &&
                deletion.cable.id > 0 &&
                !savedIds.has(deletion.cable.id)
            ) {
                // A committed deletion must be restored as a new draft, not a stale database ID.
                deletion.cable = {
                    ...deletion.cable,
                    id: nextCableId.current--,
                };
            }
        }
        const added = savedCables.filter(
            (cable) => !cables.some((saved) => saved.id === cable.id),
        );
        form.setData((current) => ({
            ...current,
            cables: [...current.cables, ...added],
            cabling_revision: revision,
            removed_cable_ids: current.removed_cable_ids.filter((id) =>
                savedIds.has(id),
            ),
        }));
        form.setDefaults({ cables: savedCables, cabling_revision: revision });
    }
    function cancelCable() {
        setCableDraft(null);
        setError('');
    }
    function applyCable(cable: Cable) {
        cable = normalizeCableType(cable, devices);
        const problem = cableError(cable, data.cables, devices);
        if (problem) {
            setError(problem);
            return false;
        }
        setData('cables', [
            ...data.cables.filter((c) => c.id !== cable.id),
            cable,
        ]);
        setCableDraft(null);
        setSelectedKey(null);
        setSelectedCableId(cable.id);
        setFocusedBundle(bundleKey(cable));
        setError('');
        return true;
    }
    function pickPort(end: CableEnd) {
        if (processing) return;
        setSelectedKey(null);
        if (cableDraft && editing) {
            const occupied = data.cables.find((c) =>
                [c.a, c.b].some((e) => endKey(e) === endKey(end)),
            );
            if (occupied) {
                setError(
                    `${endLabel(end, devices)} è occupata da ${cableCode(occupied)}. Scollega prima il cavo.`,
                );
                return;
            }
            const side = cableDraft.a.connector_id ? 'b' : 'a';
            const next = normalizeCableType(
                {
                    ...cableDraft,
                    [side]: end,
                },
                devices,
            );
            if (side === 'b') {
                const problem = cableError(next, data.cables, devices);
                if (problem) {
                    setError(problem);
                    return;
                }
            }
            setCableDraft(next);
            setError('');
        } else {
            const cable = data.cables.find((c) =>
                [c.a, c.b].some((e) => endKey(e) === endKey(end)),
            );
            if (cable) selectCable(cable);
            else {
                setSelectedCableId(null);
                setFocusedBundle(null);
                setSelectedPort(end);
                setTab('cables');
            }
        }
    }
    function changeMode(value: string) {
        if (!value || processing) return;
        if (value === 'edit') {
            setEditing(true);
            return;
        }
        if (isDirty) setDiscard(true);
        else {
            setEditing(false);
            if (tab === 'add') setTab('properties');
        }
    }
    useEffect(() => {
        const escape = (event: KeyboardEvent) => {
            if (
                creatingCable ||
                event.key !== 'Escape' ||
                event.defaultPrevented
            )
                return;
            setCableDraft(null);
            setFocusedBundle(null);
            setSelectedCableId(null);
            setSelectedPort(null);
        };
        window.addEventListener('keydown', escape);
        return () => window.removeEventListener('keydown', escape);
    }, [creatingCable]);
    function save(afterSave?: () => void) {
        if (processing) return;
        const savingCables = cableDraft
            ? [...data.cables, cableDraft]
            : data.cables;
        const problem =
            layoutError(data.items, data.height_u, devices) ||
            savingCables
                .map((cable) => cableError(cable, savingCables, devices))
                .find(Boolean);
        if (problem) {
            setError(problem);
            return;
        }
        allowLeave.current = true;
        let succeeded = false;
        form.transform((draft) => ({ ...draft, cables: savingCables }));
        form.put(update.url(route), {
            preserveScroll: true,
            onSuccess: (page) => {
                const saved = draftOf(
                    page.props.rack as Rack,
                    page.props.cables as Cable[],
                    page.props.cablingRevision as number,
                );
                form.setData(saved);
                form.setDefaults(saved);
                deletions.current = [];
                setError('');
                setCableDraft(null);
                setEditing(false);
                const active = cableDraft ?? selectedCable;
                const savedCable =
                    active &&
                    saved.cables.find(
                        (c) =>
                            c.id === active.id ||
                            (endKey(c.a) === endKey(active.a) &&
                                endKey(c.b) === endKey(active.b)),
                    );
                setSelectedCableId(savedCable?.id ?? null);
                setFocusedBundle(
                    savedCable ? bundleKey(savedCable) : focusedBundle,
                );
                if (tab === 'add') setTab('properties');
                succeeded = true;
            },
            onFinish: () => {
                form.transform((draft) => draft);
                allowLeave.current = false;
                if (succeeded) afterSave?.();
            },
        });
    }
    function reset() {
        deletions.current = [];
        const saved = draftOf(rack, cables, cablingRevision);
        form.setData(saved);
        form.setDefaults(saved);
        form.clearErrors();
        setError('');
        setCableDraft(null);
        setSelectedCableId(null);
        setFocusedBundle(null);
        setEditing(false);
        if (tab === 'add') setTab('properties');
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
                            <h1 className="truncate text-2xl font-medium tracking-tight">
                                {data.name}
                            </h1>
                        </div>
                        <Badge variant="secondary">{data.height_u}U</Badge>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            variant="outline"
                            disabled={processing}
                            onClick={() => {
                                setPrintingDocument(true);
                                router.visit(documentation(route));
                            }}
                        >
                            <Printer data-icon="inline-start" />
                            Stampa documentazione
                        </Button>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={editing ? 'edit' : 'view'}
                            onValueChange={changeMode}
                            disabled={processing}
                            aria-label="Modalità workspace"
                        >
                            <ToggleGroupItem value="view">
                                <Eye />
                                Visualizza
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
                                    processing={form.processing}
                                    saved={!isDirty}
                                    disabled={processing}
                                    onClick={() => save()}
                                />
                            </>
                        )}
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
                                type="multiple"
                                variant="outline"
                                value={
                                    face === 'both' ? ['front', 'rear'] : [face]
                                }
                                onValueChange={(values) =>
                                    setFace(
                                        values.length === 1
                                            ? (values[0] as Face)
                                            : 'both',
                                    )
                                }
                                aria-label="Vista rack"
                            >
                                <ToggleGroupItem value="front">
                                    Frontale
                                </ToggleGroupItem>
                                <ToggleGroupItem value="rear">
                                    Retro
                                </ToggleGroupItem>
                            </ToggleGroup>
                            {editing && (
                                <Button
                                    size="sm"
                                    variant={
                                        cableDraft ? 'secondary' : 'outline'
                                    }
                                    disabled={processing}
                                    onClick={() =>
                                        cableDraft
                                            ? cancelCable()
                                            : startCable()
                                    }
                                >
                                    <CableIcon />
                                    {cableDraft
                                        ? 'Annulla collegamento'
                                        : 'Collega'}
                                </Button>
                            )}
                            <div className="flex items-center gap-1">
                                <IconButton
                                    label="Sposta vista"
                                    aria-pressed={pan}
                                    variant={pan ? 'secondary' : 'ghost'}
                                    onClick={() => setPan(!pan)}
                                >
                                    <Hand />
                                </IconButton>
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
                                aria-label="Rack. Porte, dispositivi e cablaggi sono disponibili anche nel pannello e nell’elenco."
                                onPointerDownCapture={(event) =>
                                    event.currentTarget.focus({
                                        preventScroll: true,
                                    })
                                }
                                onKeyDown={(event) => {
                                    if (
                                        event.target !== event.currentTarget ||
                                        processing ||
                                        !editing ||
                                        cableDraft
                                    )
                                        return;
                                    if (
                                        (event.ctrlKey || event.metaKey) &&
                                        !event.shiftKey &&
                                        event.key.toLowerCase() === 'z'
                                    ) {
                                        event.preventDefault();
                                        undoDeletion();
                                        return;
                                    }
                                    if (event.key === 'Delete') {
                                        event.preventDefault();
                                        if (!event.repeat) {
                                            if (selectedCable)
                                                disconnect(selectedCable);
                                            else if (selected) remove();
                                        }
                                        return;
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
                                    pan={pan}
                                    items={data.items}
                                    devices={cableDevices}
                                    face={face}
                                    selected={selectedKey}
                                    api={canvas}
                                    onZoom={setZoom}
                                    onSelect={select}
                                    onMove={move}
                                    onPlace={(items) => {
                                        if (editing && !processing)
                                            change(items);
                                    }}
                                    onError={setImageError}
                                    disabled={processing || !editing}
                                    cables={relevantCables}
                                    allCables={data.cables}
                                    focusedBundle={focusedBundle}
                                    selectedCable={selectedCableId}
                                    pendingCable={cableDraft}
                                    onPort={pickPort}
                                    onConnect={startCable}
                                    onBundle={selectBundle}
                                    onCable={selectCable}
                                    onReconnect={(id, side, end) => {
                                        const cable = data.cables.find(
                                            (c) => c.id === id,
                                        );
                                        if (cable && editing && !processing)
                                            applyCable({
                                                ...cable,
                                                [side]: end,
                                            });
                                    }}
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
                                    {selected && editing && (
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
                    <aside className="flex h-[min(72vh,780px)] min-h-96 min-w-0 flex-col overflow-y-auto rounded-xl border bg-background lg:h-auto lg:min-h-0 lg:[contain:size]">
                        <Tabs
                            value={tab}
                            onValueChange={setTab}
                            className="shrink-0 gap-0"
                        >
                            <TabsList
                                className="m-3"
                                aria-label="Strumenti rack"
                            >
                                {editing && (
                                    <TabsTrigger value="add">
                                        <Plus />
                                        Aggiungi
                                    </TabsTrigger>
                                )}
                                <TabsTrigger value="properties">
                                    <Settings2 />
                                    Proprietà
                                </TabsTrigger>
                                <TabsTrigger value="cables">
                                    <CableIcon />
                                    Porte / cavi
                                </TabsTrigger>
                            </TabsList>
                            <TabsContent value="cables" className="m-0">
                                {cableDraft || selectedCable ? (
                                    <CableInspector
                                        cable={(cableDraft ?? selectedCable)!}
                                        devices={cableDevices}
                                        cables={data.cables}
                                        editing={editing}
                                        pending={!!cableDraft}
                                        disabled={processing}
                                        onChange={(cable) => {
                                            if (cableDraft)
                                                setCableDraft(cable);
                                            else {
                                                setData(
                                                    'cables',
                                                    data.cables.map((c) =>
                                                        c.id === cable.id
                                                            ? cable
                                                            : c,
                                                    ),
                                                );
                                                setFocusedBundle(
                                                    bundleKey(cable),
                                                );
                                            }
                                        }}
                                        onPrint={() => {
                                            if (selectedCable)
                                                printCable(selectedCable);
                                        }}
                                        onConfirm={() => {
                                            if (cableDraft)
                                                applyCable(cableDraft);
                                        }}
                                        onCancel={cancelCable}
                                        onDisconnect={() =>
                                            setDisconnecting(
                                                selectedCable ?? null,
                                            )
                                        }
                                    />
                                ) : (
                                    <div className="flex flex-col gap-3 p-4">
                                        {focusedBundle ? (
                                            <>
                                                <h2 className="text-sm font-medium">
                                                    {focusedBundle
                                                        .split(':')
                                                        .map(
                                                            (id) =>
                                                                devices.find(
                                                                    (device) =>
                                                                        device.id ===
                                                                        Number(
                                                                            id,
                                                                        ),
                                                                )?.name,
                                                        )
                                                        .join(' ↔ ')}{' '}
                                                    · {focusedCables.length}×
                                                </h2>
                                                {focusedCables.map((cable) => (
                                                    <Button
                                                        key={cable.id}
                                                        variant="outline"
                                                        className="h-auto justify-start py-2 text-left whitespace-normal"
                                                        onClick={() =>
                                                            selectCable(cable)
                                                        }
                                                    >
                                                        <span
                                                            className="size-3 shrink-0 rounded-full border"
                                                            style={{
                                                                background:
                                                                    cable.color,
                                                            }}
                                                        />
                                                        <span>
                                                            {cableCode(cable)}
                                                            <span className="block text-xs">
                                                                {endLabel(
                                                                    cable.a,
                                                                    devices,
                                                                )}{' '}
                                                                ↔{' '}
                                                                {endLabel(
                                                                    cable.b,
                                                                    devices,
                                                                )}
                                                            </span>
                                                        </span>
                                                    </Button>
                                                ))}
                                            </>
                                        ) : (
                                            <>
                                                {selectedPort && (
                                                    <p className="text-sm">
                                                        {endLabel(
                                                            selectedPort,
                                                            devices,
                                                        )}{' '}
                                                        · Nessun cavo
                                                        documentato
                                                    </p>
                                                )}
                                                <h2 className="text-sm font-medium">
                                                    {selectedDevice?.name ??
                                                        'Porte dei dispositivi'}
                                                </h2>
                                                {(selectedDevice
                                                    ? [selectedDevice]
                                                    : devices.filter((d) =>
                                                          rackDeviceIds.has(
                                                              d.id,
                                                          ),
                                                      )
                                                ).map((device) => (
                                                    <DevicePortsList
                                                        key={device.id}
                                                        device={device}
                                                        devices={devices}
                                                        cables={data.cables}
                                                        disabled={processing}
                                                        onSelect={pickPort}
                                                    />
                                                ))}
                                            </>
                                        )}
                                    </div>
                                )}
                            </TabsContent>
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
                                <h2 className="text-sm font-medium">
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
                                {editing ? (
                                    <fieldset
                                        disabled={processing}
                                        className="min-w-0"
                                    >
                                        <FieldGroup
                                            key={`${selectedKey}-${fieldRevision}`}
                                            className="gap-4"
                                        >
                                            {selected ? (
                                                <>
                                                    <div className="flex items-start justify-between gap-2">
                                                        <div>
                                                            <h2 className="text-sm font-medium">
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
                                                                    href={showDevice(
                                                                        {
                                                                            ...route,
                                                                            device: selectedDevice.id,
                                                                        },
                                                                    )}
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
                                                                        (
                                                                            item,
                                                                        ) => [
                                                                            item.key,
                                                                            supportLabel(
                                                                                item,
                                                                            ),
                                                                        ],
                                                                    ),
                                                                )}
                                                                onChange={(
                                                                    key,
                                                                ) => {
                                                                    const support =
                                                                        supports.find(
                                                                            (
                                                                                item,
                                                                            ) =>
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
                                                                disabled={
                                                                    processing
                                                                }
                                                            />
                                                            <NumberField
                                                                label="Altezza grafica (U)"
                                                                value={
                                                                    selected.height
                                                                }
                                                                min={0.25}
                                                                max={
                                                                    data.height_u
                                                                }
                                                                step={0.25}
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
                                                            <NumberField
                                                                label="Larghezza (%)"
                                                                value={
                                                                    selected.width
                                                                }
                                                                min={5}
                                                                max={100}
                                                                onChange={(
                                                                    width,
                                                                ) =>
                                                                    patch({
                                                                        width,
                                                                    })
                                                                }
                                                                disabled={
                                                                    processing
                                                                }
                                                            />
                                                            <NumberField
                                                                label="Posizione orizzontale (%)"
                                                                value={
                                                                    selected.x
                                                                }
                                                                min={0}
                                                                max={95}
                                                                onChange={(x) =>
                                                                    patch({ x })
                                                                }
                                                                disabled={
                                                                    processing
                                                                }
                                                            />
                                                        </>
                                                    ) : (
                                                        <>
                                                            <NumberField
                                                                label="Unità alla base"
                                                                value={
                                                                    positionLabel
                                                                }
                                                                min={1}
                                                                max={
                                                                    data.height_u
                                                                }
                                                                onChange={(
                                                                    unit,
                                                                ) =>
                                                                    move(
                                                                        selected.key,
                                                                        data.numbering ===
                                                                            'bottom-up'
                                                                            ? unit -
                                                                                  1
                                                                            : data.height_u -
                                                                                  unit,
                                                                    )
                                                                }
                                                                disabled={
                                                                    processing
                                                                }
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
                                                                    max={
                                                                        data.height_u
                                                                    }
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
                                                        onClick={() =>
                                                            select(null)
                                                        }
                                                    >
                                                        Proprietà del rack
                                                    </Button>
                                                </>
                                            ) : (
                                                <>
                                                    <h2 className="text-sm font-medium">
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
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            disabled={
                                                                processing
                                                            }
                                                        />
                                                    </Field>
                                                    <RackFloorField
                                                        value={data.floor}
                                                        onChange={(floor) =>
                                                            setData(
                                                                'floor',
                                                                floor,
                                                            )
                                                        }
                                                        disabled={processing}
                                                        error={errors.floor}
                                                    />
                                                    <NumberField
                                                        label="Altezza (U)"
                                                        value={data.height_u}
                                                        min={1}
                                                        max={100}
                                                        onChange={(height) =>
                                                            change(
                                                                data.items,
                                                                height,
                                                            )
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
                                    </fieldset>
                                ) : (
                                    <div className="flex flex-col gap-4">
                                        <h2 className="text-sm font-medium">
                                            {selected
                                                ? itemName(selected, devices)
                                                : data.name}
                                        </h2>
                                        <dl className="grid grid-cols-2 gap-2 text-sm">
                                            {!selected && (
                                                <>
                                                    <dt className="text-muted-foreground">
                                                        Piano
                                                    </dt>
                                                    <dd>{data.floor ?? '—'}</dd>
                                                </>
                                            )}
                                            <dt className="text-muted-foreground">
                                                Altezza
                                            </dt>
                                            <dd>
                                                {selected?.height ??
                                                    data.height_u}{' '}
                                                U
                                            </dd>
                                            {selected ? (
                                                <>
                                                    <dt className="text-muted-foreground">
                                                        Posizione
                                                    </dt>
                                                    <dd>
                                                        U{' '}
                                                        {unitLabel(
                                                            itemBounds(
                                                                selected,
                                                                data.items,
                                                            ).bottom,
                                                            data,
                                                        )}
                                                    </dd>
                                                </>
                                            ) : (
                                                <>
                                                    <dt className="text-muted-foreground">
                                                        Numerazione
                                                    </dt>
                                                    <dd>
                                                        {data.numbering ===
                                                        'bottom-up'
                                                            ? 'Dal basso'
                                                            : 'Dall’alto'}
                                                    </dd>
                                                </>
                                            )}
                                        </dl>
                                        <Button
                                            variant="outline"
                                            onClick={() => setTab('cables')}
                                        >
                                            <CableIcon />
                                            {selectedDevice
                                                ? 'Porte e cavi'
                                                : 'Esplora porte'}
                                        </Button>
                                        {selectedDevice && (
                                            <Button variant="outline" asChild>
                                                <Link
                                                    href={showDevice({
                                                        ...route,
                                                        device: selectedDevice.id,
                                                    })}
                                                >
                                                    <Server />
                                                    Apri dispositivo
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </TabsContent>
                        </Tabs>
                        <Separator />
                        <div className="shrink-0 p-4">
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button
                                        variant="outline"
                                        className="w-full justify-between"
                                    >
                                        Nel rack
                                        <Badge variant="secondary">
                                            {data.items.length}
                                        </Badge>
                                    </Button>
                                </DialogTrigger>
                                <DialogContent
                                    aria-describedby={undefined}
                                    onEscapeKeyDown={(event) =>
                                        event.stopPropagation()
                                    }
                                >
                                    <DialogHeader>
                                        <DialogTitle>
                                            Nel rack · {data.name}
                                        </DialogTitle>
                                    </DialogHeader>
                                    <div className="flex flex-col gap-1">
                                        {[...data.items]
                                            .sort(
                                                (a, b) =>
                                                    itemBounds(b, data.items)
                                                        .bottom -
                                                    itemBounds(a, data.items)
                                                        .bottom,
                                            )
                                            .map((item) => (
                                                <DialogClose
                                                    asChild
                                                    key={item.key}
                                                >
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            processing ||
                                                            !!cableDraft
                                                        }
                                                        onClick={() =>
                                                            select(item.key)
                                                        }
                                                        aria-pressed={
                                                            selectedKey ===
                                                            item.key
                                                        }
                                                        className={cn(
                                                            'flex min-h-10 items-center gap-2 rounded-md px-2 py-2 text-left text-sm hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring',
                                                            selectedKey ===
                                                                item.key &&
                                                                'bg-accent text-accent-foreground',
                                                        )}
                                                    >
                                                        <span className="w-10 shrink-0 text-xs text-muted-foreground tabular-nums">
                                                            U{' '}
                                                            {unitLabel(
                                                                itemBounds(
                                                                    item,
                                                                    data.items,
                                                                ).bottom,
                                                                data,
                                                            )}
                                                        </span>
                                                        <span className="truncate">
                                                            {item.shelf_key
                                                                ? '↳ '
                                                                : ''}
                                                            {itemName(
                                                                item,
                                                                devices,
                                                            )}
                                                        </span>
                                                    </button>
                                                </DialogClose>
                                            ))}
                                        {!data.items.length && (
                                            <p className="py-2 text-sm text-muted-foreground">
                                                Nessun elemento
                                            </p>
                                        )}
                                    </div>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </aside>
                </div>
                <RackCableTable
                    cables={relevantCables}
                    devices={devices}
                    selectedCableId={selectedCableId}
                    processing={processing}
                    createDisabled={processing || !!cableDraft}
                    onCreate={() => setCreatingCable(nextCableId.current--)}
                    onRowClick={(cable) =>
                        selectedCableId === cable.id
                            ? selectBundle(null)
                            : selectCable(cable)
                    }
                    onPrint={printCable}
                />
            </div>
            {creatingCable !== null && (
                <CableCreateDialog
                    id={creatingCable}
                    initialEnds={
                        creatingCable === -1
                            ? (topologySuggestion ?? undefined)
                            : undefined
                    }
                    route={route}
                    devices={devices}
                    cables={[
                        ...cables.filter(
                            (cable) =>
                                !data.removed_cable_ids.includes(cable.id),
                        ),
                        ...data.cables,
                    ]}
                    removedCableIds={data.removed_cable_ids}
                    revision={data.cabling_revision}
                    onClose={() => setCreatingCable(null)}
                    onDirtyChange={setModalDirty}
                    onSaving={(saving) => {
                        allowLeave.current = saving;
                    }}
                    onSaved={acceptCreatedCables}
                />
            )}
            <Dialog
                open={!!disconnecting}
                onOpenChange={(open) => {
                    if (!open) setDisconnecting(null);
                }}
            >
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            Scollegare{' '}
                            {disconnecting
                                ? cableCode(disconnecting)
                                : 'il cavo'}
                            ?
                        </DialogTitle>
                    </DialogHeader>
                    {disconnecting && (
                        <p className="text-sm">
                            {endLabel(disconnecting.a, devices)} ↔{' '}
                            {endLabel(disconnecting.b, devices)}. Il
                            collegamento verrà eliminato con Salva; dispositivi,
                            porte e IP saranno conservati.
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDisconnecting(null)}
                        >
                            Annulla
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (!disconnecting) return;
                                disconnect(disconnecting);
                            }}
                        >
                            Scollega
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog
                open={!!leave || discard}
                onOpenChange={(open) => {
                    if (!processing && !open) {
                        setLeave(null);
                        setDiscard(false);
                        setPrintingDocument(false);
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
                                ? modalDirty
                                    ? 'Scartare il nuovo collegamento e le modifiche non salvate?'
                                    : 'Salvare prima di uscire?'
                                : 'Scartare le modifiche non salvate?'}
                        </DialogTitle>
                    </DialogHeader>
                    {leave && printingDocument && (
                        <p className="text-sm">
                            Il PDF userà lo stato salvato. Salva prima di uscire
                            per includere queste modifiche, oppure scartale per
                            stampare la versione precedente.
                        </p>
                    )}
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
                        {leave && !modalDirty && (
                            <SaveButton
                                type="button"
                                processing={form.processing}
                                disabled={processing}
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
RackShow.layout = ({ company, site, rack }: Props) => ({
    breadcrumbs: [
        { title: 'Rack', href: index({ company: company.id, site: site.id }) },
        {
            title: rack.name,
            href: show({ company: company.id, site: site.id, rack: rack.id }),
        },
    ],
});
