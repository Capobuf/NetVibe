import { router, useForm } from '@inertiajs/react';
import {
    useId,
    useRef,
    useState,
    type ComponentProps,
    type FormEvent,
} from 'react';
import { Plus, Unplug } from 'lucide-react';
import { CableFields, EndpointPicker } from '@/components/racks/cable-inspector';
import { CablePreview } from '@/components/racks/cable-preview';
import { IconButton } from '@/components/icon-button';
import InputError from '@/components/input-error';
import { SaveButton } from '@/components/save-button';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { connectorTypes, type Port } from '@/lib/hardware-editor/document';
import {
    cableError,
    connectionTypes,
    normalizeCableType,
    type Cable,
    type CableEnd,
} from '@/lib/rack-cabling';
import { store as storeCable, destroy } from '@/routes/companies/sites/cables';
import {
    show as deviceShow,
    store as storeDevice,
} from '@/routes/companies/sites/devices';
import type { RackDevice } from '@/types/rack';

/** A documented cable on one port of the device, with the port at its other end. */
export type DeviceLink = {
    id: number;
    type: Cable['type'];
    color: string;
    name: string | null;
    connector_id: string;
    peer: {
        device_id: number;
        name: string | null;
        interface_id: number | null;
        port: string;
    };
};
/** The device and port that LLDP or CDP report beyond a local port. */
export type Adjacency = CableEnd & { protocols: string[]; stale: boolean };
export const adjacencySource = (adjacency: Adjacency) =>
    `Rilevato con ${adjacency.protocols
        .filter((protocol) => protocol !== 'mndp')
        .map((protocol) => protocol.toUpperCase())
        .join(' e ')}${adjacency.stale ? ' · non aggiornato' : ''}`;
export type LinkModel = {
    id: number;
    name: string;
    manufacturer: { id: number; name: string };
    model_type: { id: number; name: string } | null;
    cable_types: Cable['type'][];
};
export type Cabling = {
    cables: Cable[];
    devices: RackDevice[];
    models: LinkModel[];
};
type Route = { company: number; site: number };
type CloseFocus = ComponentProps<typeof DialogContent>['onCloseAutoFocus'];

// A bare number reads as a position on the panel.
export const portTitle = (port: Port) =>
    port.label
        ? /^\d+$/.test(port.label)
            ? `Porta ${port.label}`
            : port.label
        : connectorTypes[port.type].name;

/** The documented cable on a port, next to what the integrations observe on it. */
export function PortLink({
    route,
    link,
    canEdit,
    onUnlink,
}: {
    route: Route;
    link: DeviceLink;
    canEdit: boolean;
    onUnlink: (link: DeviceLink) => void;
}) {
    return (
        <section
            aria-label="Collegamento documentato"
            className="flex flex-col gap-3"
        >
            <h3 className="text-sm font-medium">Collegamento documentato</h3>
            <div className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2">
                <span className="flex min-w-0 items-center gap-2.5">
                    <span
                        aria-hidden
                        className="size-2.5 shrink-0 rounded-full border"
                        style={{ background: link.color }}
                    />
                    <span className="min-w-0">
                        <span className="block truncate text-sm">
                            <TextLink
                                href={deviceShow(
                                    { ...route, device: link.peer.device_id },
                                    link.peer.interface_id
                                        ? {
                                              query: {
                                                  interface:
                                                      link.peer.interface_id,
                                              },
                                          }
                                        : undefined,
                                )}
                            >
                                {link.peer.name ?? 'Dispositivo'}
                            </TextLink>
                        </span>
                        <span className="block truncate text-xs text-muted-foreground tabular-nums">
                            {[link.peer.port, `C-${link.id}`, link.name]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    </span>
                </span>
                {canEdit && (
                    <IconButton
                        label={`Scollega C-${link.id}`}
                        variant="ghost"
                        className="size-8 shrink-0"
                        onClick={() => onUnlink(link)}
                    >
                        <Unplug />
                    </IconButton>
                )}
            </div>
        </section>
    );
}

export function UnlinkDialog({
    route,
    link,
    local,
    revision,
    onClose,
    onCloseAutoFocus,
}: {
    route: Route;
    link: DeviceLink;
    local: string;
    revision: number;
    onClose: () => void;
    onCloseAutoFocus?: CloseFocus;
}) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !processing) onClose();
            }}
        >
            <DialogContent
                aria-describedby={undefined}
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <DialogHeader>
                    <DialogTitle>Scollegare C-{link.id}?</DialogTitle>
                </DialogHeader>
                <p className="text-sm">
                    {local} ↔ {link.peer.name ?? 'Dispositivo'} ·{' '}
                    {link.peer.port}
                </p>
                <InputError message={error} />
                <DialogFooter>
                    <Button
                        variant="outline"
                        disabled={processing}
                        onClick={onClose}
                    >
                        Annulla
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        onClick={() =>
                            router.delete(
                                destroy.url({ ...route, cableId: link.id }),
                                {
                                    data: { cabling_revision: revision },
                                    only: ['links', 'cablingRevision'],
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onSuccess: onClose,
                                    onError: (errors) =>
                                        setError(
                                            Object.values(errors).join(' '),
                                        ),
                                    onFinish: () => setProcessing(false),
                                },
                            )
                        }
                    >
                        {processing ? <Spinner /> : <Unplug />}
                        Scollega
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Documents the cable from one port: to a device observed on it, to an existing intermediate device,
 * or to one created here from a catalog model. Nothing is chosen for the operator.
 */
export function PortLinkDialog({
    route,
    device,
    port,
    cabling,
    revision,
    suggestedIds,
    destination,
    source,
    reload = ['links', 'cablingRevision', 'cabling'],
    onClose,
    onSaved,
    onCloseAutoFocus,
}: {
    route: Route;
    device: { id: number; name: string };
    port: Port;
    cabling?: Cabling;
    revision: number;
    suggestedIds: number[];
    // Prefilled from what integrations report; still confirmed by the operator.
    destination?: CableEnd;
    // Where the prefilled destination comes from, shown while it is kept.
    source?: string;
    // Page props that change once the cable is saved.
    reload?: string[];
    onClose: () => void;
    onSaved: () => void;
    onCloseAutoFocus?: CloseFocus;
}) {
    const id = useId();
    const types = connectionTypes(port.type);
    const form = useForm<Cable>({
        id: -1,
        a: { device_id: device.id, connector_id: port.id },
        b: destination ?? { device_id: 0, connector_id: '' },
        type: types[0] ?? 'copper',
        color: '#2563eb',
        purpose: null,
        name: null,
    });
    const [error, setError] = useState('');
    const [imageError, setImageError] = useState('');
    const [discard, setDiscard] = useState(false);
    const [creating, setCreating] = useState(false);
    const [model, setModel] = useState<LinkModel | null>(null);
    const [name, setName] = useState('');
    const [createErrors, setCreateErrors] = useState<Record<string, string>>(
        {},
    );
    const [createProcessing, setCreateProcessing] = useState(false);
    const devices = cabling?.devices ?? [];
    const problem = cabling
        ? cableError(form.data, cabling.cables, devices)
        : null;
    const feedback =
        error ||
        Object.values(form.errors).join(' ') ||
        (form.data.b.connector_id ? problem : null) ||
        imageError;
    const models =
        cabling?.models.filter((item) =>
            item.cable_types.some((type) => types.includes(type)),
        ) ?? [];
    function change(cable: Cable) {
        form.setData(normalizeCableType(cable, devices));
        form.clearErrors();
        setError('');
    }
    function selectDestination(end: CableEnd) {
        change({ ...form.data, b: end });
    }
    function close() {
        if (form.processing || createProcessing) return;
        if (form.isDirty || creating) setDiscard(true);
        else onClose();
    }
    function submit(event: FormEvent) {
        event.preventDefault();
        if (problem) {
            setError(problem);
            return;
        }
        form.transform((data) => ({ ...data, cabling_revision: revision }));
        form.post(storeCable.url(route), {
            only: reload,
            preserveScroll: true,
            onSuccess: onSaved,
        });
    }
    function createDevice(event: FormEvent) {
        event.preventDefault();
        if (!model || createProcessing) return;
        router.post(
            storeDevice.url(route),
            { name, device_model_id: model.id, inline: true },
            {
                only: ['cabling'],
                preserveState: true,
                preserveScroll: true,
                errorBag: 'linkDevice',
                onStart: () => {
                    setCreateProcessing(true);
                    setCreateErrors({});
                },
                onSuccess: (page) => {
                    const created = page.flash.createdDevice as
                        | { id: number }
                        | undefined;
                    if (created)
                        selectDestination({
                            device_id: created.id,
                            connector_id: '',
                        });
                    setCreating(false);
                    setModel(null);
                    setName('');
                },
                onError: setCreateErrors,
                onFinish: () => setCreateProcessing(false),
            },
        );
    }
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) close();
                }}
            >
                <DialogContent
                    className="sm:max-w-3xl"
                    onCloseAutoFocus={onCloseAutoFocus}
                >
                    <DialogHeader className="border-b pb-4">
                        <DialogTitle>Documenta collegamento</DialogTitle>
                        <DialogDescription>
                            {device.name} · {portTitle(port)}
                        </DialogDescription>
                    </DialogHeader>
                    {!cabling ? (
                        <div
                            className="flex min-h-48 items-center justify-center"
                            role="status"
                            aria-label="Caricamento dispositivi"
                        >
                            <Spinner className="size-6" />
                        </div>
                    ) : (
                        <div className="flex min-w-0 flex-col gap-5">
                            <section className="min-w-0 space-y-4 rounded-xl border bg-muted/20 p-4">
                                <div className="flex min-h-8 items-center justify-between gap-3">
                                    <h3 className="flex min-w-0 flex-wrap items-center gap-2 text-base font-medium">
                                        {creating
                                            ? 'Nuovo dispositivo'
                                            : 'Destinazione'}
                                        {!creating &&
                                            source &&
                                            destination &&
                                            form.data.b.device_id ===
                                                destination.device_id &&
                                            form.data.b.connector_id ===
                                                destination.connector_id && (
                                                <Badge
                                                    variant="outline"
                                                    className="font-normal"
                                                >
                                                    {source}
                                                </Badge>
                                            )}
                                    </h3>
                                    {!creating && models.length > 0 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={form.processing}
                                            onClick={() => setCreating(true)}
                                        >
                                            <Plus data-icon="inline-start" />
                                            Nuovo dispositivo
                                        </Button>
                                    )}
                                </div>
                                {creating ? (
                                    <form onSubmit={createDevice}>
                                        <FieldGroup className="gap-3">
                                            <Field
                                                data-invalid={
                                                    !!createErrors.device_model_id
                                                }
                                            >
                                                <FieldLabel
                                                    htmlFor={`${id}-model`}
                                                >
                                                    Modello
                                                </FieldLabel>
                                                <ModelPicker
                                                    id={`${id}-model`}
                                                    models={models}
                                                    value={model}
                                                    disabled={createProcessing}
                                                    onChange={setModel}
                                                />
                                                <InputError
                                                    message={
                                                        createErrors.device_model_id
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                data-invalid={
                                                    !!createErrors.name
                                                }
                                            >
                                                <FieldLabel
                                                    htmlFor={`${id}-name`}
                                                >
                                                    Nome
                                                </FieldLabel>
                                                <Input
                                                    id={`${id}-name`}
                                                    value={name}
                                                    maxLength={255}
                                                    required
                                                    disabled={createProcessing}
                                                    aria-invalid={
                                                        !!createErrors.name
                                                    }
                                                    onChange={(event) =>
                                                        setName(
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                                <InputError
                                                    message={createErrors.name}
                                                />
                                            </Field>
                                            <InputError
                                                message={Object.entries(
                                                    createErrors,
                                                )
                                                    .filter(
                                                        ([field]) =>
                                                            field !== 'name' &&
                                                            field !==
                                                                'device_model_id',
                                                    )
                                                    .map(([, message]) => message)
                                                    .join(' ')}
                                            />
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    disabled={createProcessing}
                                                    onClick={() => {
                                                        setCreating(false);
                                                        setCreateErrors({});
                                                    }}
                                                >
                                                    Annulla
                                                </Button>
                                                <SaveButton
                                                    processing={
                                                        createProcessing
                                                    }
                                                    disabled={!model}
                                                >
                                                    Crea dispositivo
                                                </SaveButton>
                                            </div>
                                        </FieldGroup>
                                    </form>
                                ) : (
                                    <EndpointPicker
                                        label="Destinazione"
                                        value={form.data.b}
                                        devices={devices}
                                        cables={cabling.cables}
                                        cableId={-1}
                                        disabled={form.processing}
                                        otherEnd={form.data.a}
                                        suggested={{
                                            label: 'Rilevati su questa porta',
                                            ids: suggestedIds,
                                        }}
                                        onChange={selectDestination}
                                    />
                                )}
                            </section>
                            <form
                                className="flex min-w-0 flex-col gap-5"
                                onSubmit={submit}
                            >
                                <CableFields
                                    cable={form.data}
                                    devices={devices}
                                    disabled={form.processing}
                                    onChange={change}
                                    className="grid items-start gap-5 sm:grid-cols-2"
                                />
                                {feedback && (
                                    <Alert variant="destructive">
                                        <AlertDescription>
                                            {feedback}
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {form.data.b.device_id > 0 && (
                                    <CablePreview
                                        cable={form.data}
                                        devices={devices}
                                        connected={!problem}
                                        onError={setImageError}
                                    />
                                )}
                                <DialogFooter className="border-t pt-4">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={form.processing}
                                        onClick={close}
                                    >
                                        Annulla
                                    </Button>
                                    <SaveButton
                                        processing={form.processing}
                                        disabled={creating}
                                    >
                                        Salva collegamento
                                    </SaveButton>
                                </DialogFooter>
                            </form>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
            <Dialog open={discard} onOpenChange={setDiscard}>
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>Scartare il collegamento?</DialogTitle>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDiscard(false)}
                        >
                            Continua
                        </Button>
                        <Button variant="destructive" onClick={onClose}>
                            Scarta
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function ModelPicker({
    id,
    models,
    value,
    disabled,
    onChange,
}: {
    id: string;
    models: LinkModel[];
    value: LinkModel | null;
    disabled: boolean;
    onChange: (model: LinkModel | null) => void;
}) {
    const label = (model: LinkModel) =>
        `${model.manufacturer.name} · ${model.name}`;
    // Keep the popup inside the dialog's focus and pointer boundary.
    const container = useRef<HTMLDivElement>(null);
    return (
        <div ref={container}>
            <Combobox
                items={models}
                value={value}
                disabled={disabled}
                openOnInputClick
                itemToStringLabel={label}
                isItemEqualToValue={(a, b) => a.id === b.id}
                onValueChange={onChange}
            >
                <ComboboxInput
                    id={id}
                    placeholder="Cerca modello…"
                    showClear
                    disabled={disabled}
                    autoFocus
                />
                <ComboboxContent container={container}>
                    <ComboboxEmpty>Nessun modello</ComboboxEmpty>
                    <ComboboxList>
                        {(model: LinkModel) => (
                            <ComboboxItem key={model.id} value={model}>
                                <span className="min-w-0 flex-1 truncate">
                                    {label(model)}
                                </span>
                                {model.model_type && (
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {model.model_type.name}
                                    </span>
                                )}
                            </ComboboxItem>
                        )}
                    </ComboboxList>
                </ComboboxContent>
            </Combobox>
        </div>
    );
}
