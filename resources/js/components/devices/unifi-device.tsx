import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { CircleAlert, EthernetPort, Info, Zap } from 'lucide-react';
import {
    adopt,
    port as associatePort,
} from '@/actions/App/Http/Controllers/UnifiInventoryController';
import { UnifiMark } from '@/components/unifi-mark';
import { IconButton } from '@/components/icon-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    Popover,
    PopoverContent,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import {
    unifiState,
    type UnifiDeviceDetail,
    type UnifiPort,
} from '@/components/unifi/types';
import { formatDateTime } from '@/lib/utils';
import { interfaceName, type DeviceInterface } from '@/types/device';

const poeStates: Record<string, string> = {
    UP: 'Attivo',
    DOWN: 'Inattivo',
    LIMITED: 'Limitato',
    UNKNOWN: 'Non disponibile',
};

export function UnifiDeviceSummary({ source }: { source: UnifiDeviceDetail }) {
    const form = useForm({
        revision: source.revision ?? '',
        field: '',
        action: '',
    });
    function resolve(field: 'name' | 'model', action: 'update' | 'keep') {
        form.transform(() => ({ revision: source.revision, field, action }));
        form.submit(
            adopt({
                unifiConnection: source.connection_id,
                unifiDevice: source.id,
            }),
            {
                preserveScroll: true,
            },
        );
    }
    return (
        <section
            aria-label="Rilevato da UniFi"
            className="flex flex-col gap-3 rounded-xl border p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <UnifiMark className="size-4" />
                    <span className="text-sm font-medium">UniFi</span>
                    <Badge variant={source.stale ? 'outline' : 'secondary'}>
                        {unifiState(source.observed.state, source.stale)}
                    </Badge>
                </div>
                <span className="text-xs text-muted-foreground">
                    {source.synced_at
                        ? `Ultima lettura: ${formatDateTime(source.synced_at)}`
                        : 'Mai sincronizzato'}
                </span>
            </div>
            <dl className="flex flex-wrap gap-x-8 gap-y-3 text-sm">
                {[
                    ['Nome rilevato', source.observed.name],
                    ['Modello', source.observed.model],
                    ['IP gestione', source.observed.ip_address],
                    ['MAC', source.observed.mac_address],
                    ['Firmware', source.observed.firmware_version],
                ]
                    .filter(([, value]) => value)
                    .map(([label, value]) => (
                        <div key={label} className="flex flex-col gap-1">
                            <dt className="text-xs text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="break-all">{value}</dd>
                        </div>
                    ))}
                {source.differences.length ? (
                    <div className="flex items-end">
                        <dt className="sr-only">Differenze</dt>
                        <dd>
                            <Popover>
                                <PopoverTrigger asChild>
                                    <Button variant="outline" size="sm">
                                        <CircleAlert data-icon="inline-start" />
                                        Confronta dati
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-96 max-w-[calc(100vw-2rem)]">
                                    <PopoverHeader>
                                        <PopoverTitle>
                                            Dati rilevati da UniFi
                                        </PopoverTitle>
                                    </PopoverHeader>
                                    <div className="flex flex-col gap-4 pt-4">
                                        {source.differences.map(
                                            (difference) => (
                                                <section
                                                    key={difference.field}
                                                    className="flex flex-col gap-2"
                                                >
                                                    <h4 className="text-sm font-medium">
                                                        {difference.field ===
                                                        'name'
                                                            ? 'Nome'
                                                            : 'Modello'}
                                                    </h4>
                                                    <dl className="grid grid-cols-2 gap-2 text-sm">
                                                        <dt className="text-muted-foreground">
                                                            Dato NetVibe
                                                        </dt>
                                                        <dd className="break-words">
                                                            {difference.local ??
                                                                '—'}
                                                        </dd>
                                                        <dt className="text-muted-foreground">
                                                            Rilevato da UniFi
                                                        </dt>
                                                        <dd className="break-words">
                                                            {difference.observed ??
                                                                '—'}
                                                        </dd>
                                                    </dl>
                                                    {difference.maintained ? (
                                                        <p className="text-xs text-muted-foreground">
                                                            Valore NetVibe
                                                            mantenuto
                                                        </p>
                                                    ) : null}
                                                    {source.revision &&
                                                    difference.can_update ? (
                                                        <div className="flex gap-2">
                                                            <Button
                                                                size="sm"
                                                                disabled={
                                                                    form.processing ||
                                                                    source.stale
                                                                }
                                                                onClick={() =>
                                                                    resolve(
                                                                        difference.field,
                                                                        'update',
                                                                    )
                                                                }
                                                            >
                                                                Aggiorna
                                                            </Button>
                                                            {!difference.maintained ? (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={
                                                                        form.processing
                                                                    }
                                                                    onClick={() =>
                                                                        resolve(
                                                                            difference.field,
                                                                            'keep',
                                                                        )
                                                                    }
                                                                >
                                                                    Mantieni
                                                                </Button>
                                                            ) : null}
                                                        </div>
                                                    ) : null}
                                                </section>
                                            ),
                                        )}
                                        {source.snipe_owned ? (
                                            <p className="text-xs text-muted-foreground">
                                                Nome e modello gestiti da
                                                Snipe-IT
                                            </p>
                                        ) : null}
                                        {form.hasErrors ? (
                                            <Alert variant="destructive">
                                                <AlertDescription>
                                                    {Object.values(
                                                        form.errors,
                                                    ).join(' ')}
                                                </AlertDescription>
                                            </Alert>
                                        ) : null}
                                    </div>
                                </PopoverContent>
                            </Popover>
                        </dd>
                    </div>
                ) : null}
            </dl>
        </section>
    );
}

export function UnifiPorts({
    source,
    ports,
    interfaces,
    onInterface,
}: {
    source: UnifiDeviceDetail;
    ports: UnifiPort[];
    interfaces: DeviceInterface[];
    onInterface: (id: number) => void;
}) {
    const [selectedPort, setSelectedPort] = useState<number | null>(null);
    if (!ports.length) return null;
    return (
        <section
            aria-label="Porte rilevate da UniFi"
            className="flex flex-col gap-3"
        >
            <header className="flex flex-wrap items-baseline gap-2">
                <h2 className="text-sm font-medium">Porte</h2>
                <span className="text-xs text-muted-foreground">
                    Vista logica
                </span>
            </header>
            <div className="flex flex-wrap gap-2">
                {[...ports]
                    .sort((a, b) => a.idx - b.idx)
                    .map((port) => (
                        <Popover
                            key={port.idx}
                            open={selectedPort === port.idx}
                            onOpenChange={(open) =>
                                setSelectedPort(open ? port.idx : null)
                            }
                        >
                            <PopoverTrigger asChild>
                                <Button
                                    variant={
                                        !source.stale &&
                                        !port.missing &&
                                        port.state === 'UP'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                    className="h-auto min-w-20 flex-col gap-1 px-3 py-2"
                                    aria-label={`Porta ${port.idx}, ${port.connector ?? 'connettore non disponibile'}, ${port.missing ? 'Non osservata' : unifiState(port.state, source.stale)}`}
                                >
                                    <span className="flex items-center gap-1.5">
                                        <EthernetPort />
                                        <span className="tabular-nums">
                                            {port.idx}
                                        </span>
                                        {port.poe?.enabled ? (
                                            <Zap aria-label="PoE abilitato" />
                                        ) : null}
                                    </span>
                                    <span className="text-xs">
                                        {port.missing
                                            ? 'Non osservata'
                                            : unifiState(
                                                  port.state,
                                                  source.stale,
                                              )}
                                    </span>
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent
                                className="w-80 max-w-[calc(100vw-2rem)]"
                                aria-label={`Porta ${port.idx}`}
                                onOpenAutoFocus={(event) => {
                                    event.preventDefault();
                                    if (event.target instanceof HTMLElement)
                                        event.target.focus({ preventScroll: true });
                                }}
                            >
                                <PopoverHeader className="flex-row items-center gap-2">
                                    <PopoverTitle>
                                        Porta {port.idx}
                                    </PopoverTitle>
                                    <UnifiPortInfo source={source} />
                                </PopoverHeader>
                                <div className="pt-3">
                                    <UnifiPortDetails
                                        source={source}
                                        port={port}
                                        interfaces={interfaces}
                                        onInterface={(id) => {
                                            setSelectedPort(null);
                                            onInterface(id);
                                        }}
                                    />
                                </div>
                            </PopoverContent>
                        </Popover>
                    ))}
            </div>
        </section>
    );
}

export function UnifiPortInfo({ source }: { source: UnifiDeviceDetail }) {
    return (
        <IconButton
            label={source.synced_at
                ? `Ultima lettura: ${formatDateTime(source.synced_at)}`
                : 'Mai sincronizzato'}
            variant="ghost"
            className="size-8 shrink-0 text-muted-foreground"
        >
            <Info />
        </IconButton>
    );
}

export function UnifiPortDetails({
    source,
    port,
    interfaces,
    onInterface,
}: {
    source: UnifiDeviceDetail;
    port: UnifiPort;
    interfaces: DeviceInterface[];
    onInterface?: (id: number) => void;
}) {
    const form = useForm({
        revision: source.revision ?? '',
        idx: port.idx,
        device_interface_id: '',
    });
    const local = interfaces.find(
        (item) => item.id === port.device_interface_id,
    );
    const available = interfaces.filter(
        (item) =>
            item.kind === 'physical' &&
            !source.ports.some(
                (other) => other.device_interface_id === item.id,
            ),
    );
    return (
        <div className="flex flex-col gap-3">
            <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                <dt className="text-muted-foreground">Stato</dt>
                <dd>
                    {port.missing
                        ? 'Non osservata'
                        : unifiState(port.state, source.stale)}
                </dd>
                <dt className="text-muted-foreground">Connettore</dt>
                <dd>{port.connector ?? '—'}</dd>
                {port.speed_mbps !== null ? (
                    <>
                        <dt className="text-muted-foreground">Velocità</dt>
                        <dd>{port.speed_mbps} Mbps</dd>
                    </>
                ) : null}
                {port.max_speed_mbps !== null ? (
                    <>
                        <dt className="text-muted-foreground">Massima</dt>
                        <dd>{port.max_speed_mbps} Mbps</dd>
                    </>
                ) : null}
                {port.poe ? (
                    <>
                        <dt className="text-muted-foreground">PoE</dt>
                        <dd className="flex flex-col items-start gap-1.5">
                            <Badge variant="secondary">
                                <Zap />
                                {port.poe.enabled === true
                                    ? 'Abilitato'
                                    : port.poe.enabled === false
                                      ? 'Disabilitato'
                                      : 'Non disponibile'}
                            </Badge>
                            <span className="text-xs text-muted-foreground">
                                {[
                                    port.poe.standard,
                                    port.poe.type != null ? `Tipo ${port.poe.type}` : null,
                                ].filter(Boolean).join(' · ')}
                            </span>
                        </dd>
                        <dt className="text-muted-foreground">Stato PoE</dt>
                        <dd>
                            {poeStates[port.poe.state ?? 'UNKNOWN'] ?? port.poe.state}
                        </dd>
                    </>
                ) : null}
            </dl>
            {local && onInterface ? (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => onInterface(local.id)}
                >
                    {interfaceName(local)}
                </Button>
            ) : null}
            {!port.device_interface_id && source.revision ? (
                <>
                    <Separator />
                    <Field>
                        <FieldLabel
                            htmlFor={`unifi-port-${source.id}-${port.idx}`}
                        >
                            Interfaccia NetVibe
                        </FieldLabel>
                        <Select
                            value={form.data.device_interface_id}
                            onValueChange={(value) =>
                                form.setData('device_interface_id', value)
                            }
                        >
                            <SelectTrigger
                                id={`unifi-port-${source.id}-${port.idx}`}
                            >
                                <SelectValue placeholder="Seleziona interfaccia" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectItem value="new">
                                        Crea interfaccia
                                    </SelectItem>
                                    {available.map((item) => (
                                        <SelectItem
                                            key={item.id}
                                            value={String(item.id)}
                                        >
                                            {interfaceName(item)}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </Field>
                    <Button
                        size="sm"
                        disabled={
                            form.processing ||
                            !form.data.device_interface_id ||
                            source.stale ||
                            port.missing
                        }
                        onClick={() => {
                            form.transform((data) => ({
                                ...data,
                                revision: source.revision,
                                device_interface_id:
                                    data.device_interface_id === 'new'
                                        ? null
                                        : Number(data.device_interface_id),
                            }));
                            form.submit(
                                associatePort({
                                    unifiConnection: source.connection_id,
                                    unifiDevice: source.id,
                                }),
                                { preserveScroll: true },
                            );
                        }}
                    >
                        {form.processing ? <Spinner /> : null}
                        {form.data.device_interface_id === 'new'
                            ? 'Crea interfaccia'
                            : 'Collega'}
                    </Button>
                    {form.hasErrors ? (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {Object.values(form.errors).join(' ')}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                </>
            ) : null}
        </div>
    );
}
