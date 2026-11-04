import {
    UnifiObservationIndicator,
    type UnifiObservation,
} from '@/components/networks/unifi-observation';
import { SaveButton } from '@/components/save-button';
import { MikrotikSourcesLoader } from '@/components/devices/mikrotik-sources-loader';
import { SnipeItIp } from '@/components/networks/snipe-it-ip';
import {
    MikrotikSourcesDialog,
    MikrotikIndicator,
    type MikrotikSource,
} from '@/components/networks/mikrotik-source';
import { Form, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Network,
    Pencil,
    Plus,
    Server,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { type ComponentProps, useId, useState } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import { CopyValue } from '@/components/copy-value';
import { DeviceAssociation } from '@/components/devices/device-association';
import { IpAssignmentFields } from '@/components/devices/ip-assignment-fields';
import { MacConfirmation } from '@/components/devices/mac-confirmation';
import { IconButton } from '@/components/icon-button';
import InputError from '@/components/input-error';
import IpMetadataFields from '@/components/networks/ip-metadata-fields';
import { TagBadge } from '@/components/networks/tag-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetDescription,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { ipv4Number } from '@/lib/ipv4';
import {
    destroy,
    store,
    update,
} from '@/routes/companies/sites/subnets/ip-addresses';
import type { Device } from '@/types/device';
import type { ExcludedRange, IpAddress, IpStatus, Tag } from '@/types/network';

function observedValue(
    sources: (MikrotikSource | UnifiObservation)[],
    field: 'mac' | 'hostname_observed',
) {
    const values = new Map<string, string>();
    for (const source of sources) {
        if (
            source.state !== 'current' ||
            source.stale ||
            ('active' in source.data && source.data.active === false)
        )
            continue;
        const value = source.data[field]?.trim();
        if (!value) continue;
        const normalized =
            field === 'mac' ? value.replaceAll('-', ':').toUpperCase() : value;
        values.set(normalized.toLowerCase(), normalized);
    }
    return values.size === 1 ? [...values.values()][0] : '';
}

export default function IpAddressForm({
    companyId,
    siteId,
    subnetId,
    ipAddress,
    excludedRanges,
    statuses,
    tags,
    suggestedIp,
    open: controlledOpen,
    onOpenChange,
    onCloseAutoFocus,
    onCreated,
    assignment,
    devices: providedDevices,
    embedded = false,
}: {
    companyId: number;
    siteId: number;
    subnetId: number;
    ipAddress?: IpAddress;
    excludedRanges: ExcludedRange[];
    statuses: IpStatus[];
    tags: Tag[];
    suggestedIp?: string;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    onCloseAutoFocus?: ComponentProps<typeof SheetContent>['onCloseAutoFocus'];
    onCreated?: (ip: string) => void;
    assignment?: { deviceId: number; interfaceId?: number; label: string };
    devices?: Device[];
    embedded?: boolean;
}) {
    const {
        component,
        props: { auth, devices: pageDevices },
    } = usePage<{ devices?: Device[] }>();
    const { mikrotikSources, unifiObservations = [] } = usePage<{
        mikrotikSources?: MikrotikSource[];
        unifiObservations?: UnifiObservation[];
    }>().props;
    const loadingSources =
        component === 'devices/show' && mikrotikSources === undefined;
    const devices = providedDevices ?? pageDevices ?? [];
    const canEdit = auth.canEditIpam;
    const initialInterface = devices
        .find((device) => device.id === assignment?.deviceId)
        ?.interfaces.find((item) => item.id === assignment?.interfaceId);
    const initialMac = ipAddress
        ? (ipAddress.mac_address ?? '')
        : (initialInterface?.mac_address ?? null);
    const initialHostname = ipAddress ? (ipAddress.hostname ?? '') : null;
    const [internalOpen, setInternalOpen] = useState(false);
    const open = controlledOpen ?? internalOpen;
    const setOpen = onOpenChange ?? setInternalOpen;
    const [deleting, setDeleting] = useState(false);
    const [releasing, setReleasing] = useState(false);
    const [editing, setEditing] = useState(!ipAddress);
    const [ip, setIp] = useState(ipAddress?.ip ?? suggestedIp ?? '');
    const [macInput, setMac] = useState(initialMac);
    const [hostnameInput, setHostname] = useState(initialHostname);
    const sources = (mikrotikSources ?? []).filter(
        (source) =>
            source.ip === ip ||
            (ipAddress && source.ip_address_id === ipAddress.id),
    );
    const unifi = unifiObservations.filter(
        (source) =>
            source.ip === ip ||
            (ipAddress && source.ip_address_id === ipAddress.id),
    );
    const observations = [...sources, ...unifi];
    const mac = macInput ?? observedValue(observations, 'mac');
    const hostname =
        hostnameInput ?? observedValue(observations, 'hostname_observed');
    const address = ipv4Number(ip);
    const excluded =
        address !== null &&
        excludedRanges.some(
            (range) =>
                ipv4Number(range.start_ip)! <= address &&
                ipv4Number(range.end_ip)! >= address,
        );
    const id = useId();
    const route = { company: companyId, site: siteId, subnet: subnetId };
    function startEditing() {
        setReleasing(false);
        setIp(ipAddress?.ip ?? suggestedIp ?? '');
        setMac(initialMac);
        setHostname(initialHostname);
        setEditing(true);
    }
    const content = (
        <div
            className={
                embedded
                    ? 'flex flex-col gap-6'
                    : 'flex flex-1 flex-col gap-6 p-6'
            }
        >
            <MikrotikSourcesLoader />
            {ipAddress && !editing ? (
                <>
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="flex items-center gap-2 text-sm font-medium">
                            <Network
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Indirizzo
                        </h3>
                        {canEdit && (
                            <IconButton
                                label={`Modifica IP ${ipAddress.ip}`}
                                onClick={startEditing}
                            >
                                <Pencil />
                            </IconButton>
                        )}
                    </div>
                    <dl className="grid gap-5 text-sm sm:grid-cols-2">
                        {(
                            [
                                ['IPv4', ipAddress.ip],
                                ['Hostname', ipAddress.hostname],
                                ['MAC', ipAddress.mac_address],
                                ['Descrizione', ipAddress.description],
                            ] as const
                        ).map(([label, value]) => (
                            <div
                                key={label}
                                className={
                                    label === 'Descrizione'
                                        ? 'min-w-0 sm:col-span-2'
                                        : 'min-w-0'
                                }
                            >
                                <dt className="mb-1 text-muted-foreground">
                                    {label}
                                </dt>
                                <dd>
                                    {value ? (
                                        <CopyValue
                                            value={value}
                                            className="-ml-2"
                                        />
                                    ) : (
                                        '—'
                                    )}
                                    {label === 'IPv4' ? (
                                        <UnifiObservationIndicator
                                            observations={unifi}
                                            company={companyId}
                                            site={siteId}
                                        />
                                    ) : null}
                                    {label === 'IPv4' || label === 'MAC' ? (
                                        <MikrotikIndicator
                                            sources={sources}
                                            field={
                                                label === 'MAC' ? 'mac' : 'ip'
                                            }
                                            mac={ipAddress.mac_address}
                                        />
                                    ) : null}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <SnipeItIp
                        source={ipAddress.snipe_it}
                        mikrotikSources={sources}
                    />
                    <Separator />
                    <section className="flex flex-col gap-3">
                        <h3 className="flex items-center gap-2 text-sm font-medium">
                            <Server
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Dispositivo / Interfaccia
                        </h3>
                        <div className="text-sm">
                            {ipAddress.device ? (
                                <DeviceAssociation
                                    company={companyId}
                                    site={siteId}
                                    device={ipAddress.device}
                                    item={ipAddress.device_interface}
                                    label={ipAddress.assignment_label}
                                />
                            ) : (
                                '—'
                            )}
                        </div>
                    </section>
                    <Separator />
                    <dl className="grid gap-5 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="mb-2 text-muted-foreground">
                                Stato
                            </dt>
                            <dd>
                                {ipAddress.status ? (
                                    <Badge variant="secondary">
                                        <CopyValue
                                            value={ipAddress.status.name}
                                            className="h-auto p-0"
                                        />
                                    </Badge>
                                ) : (
                                    '—'
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="mb-2 text-muted-foreground">Tag</dt>
                            <dd className="flex flex-wrap gap-1">
                                {ipAddress.tags.length
                                    ? ipAddress.tags.map((tag) => (
                                          <TagBadge
                                              key={tag.id}
                                              color={tag.color}
                                          >
                                              <CopyValue
                                                  value={tag.name}
                                                  className="h-auto p-0"
                                              />
                                          </TagBadge>
                                      ))
                                    : '—'}
                            </dd>
                        </div>
                    </dl>
                    {ipAddress.mikrotik_edit_mode ? (
                        <Badge variant="outline">
                            {ipAddress.mikrotik_edit_mode === 'manual'
                                ? 'Valore manuale mantenuto'
                                : ipAddress.mikrotik_matched_at
                                  ? 'Modifica prevista riscontrata'
                                  : 'Modifica prevista'}
                        </Badge>
                    ) : null}
                    {excluded && (
                        <p className="text-sm text-muted-foreground">
                            Escluso dai suggerimenti
                        </p>
                    )}
                </>
            ) : (
                <div hidden={deleting}>
                    <Form
                        {...(ipAddress
                            ? update.form({
                                  ...route,
                                  ipAddress: ipAddress.id,
                              })
                            : store.form(route))}
                        onSuccess={() => {
                            if (!ipAddress) onCreated?.(ip);
                            setOpen(false);
                        }}
                        onBefore={() => {
                            if (releasing) {
                                setDeleting(true);
                                return false;
                            }
                            return canEdit && editing && !loadingSources;
                        }}
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <FieldGroup>
                                <div className="flex items-center justify-between gap-3">
                                    <h3 className="flex items-center gap-2 text-sm font-medium">
                                        <Network
                                            className="size-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        Indirizzo
                                    </h3>
                                    <div className="flex flex-wrap gap-2">
                                        <MikrotikSourcesDialog
                                            sources={sources}
                                        />
                                        <UnifiObservationIndicator
                                            observations={unifi}
                                            company={companyId}
                                            site={siteId}
                                        >
                                            UniFi
                                        </UnifiObservationIndicator>
                                    </div>
                                </div>
                                {excluded && (
                                    <Alert>
                                        <TriangleAlert />
                                        <AlertTitle>
                                            IP in un intervallo escluso
                                        </AlertTitle>
                                    </Alert>
                                )}
                                <div className="grid gap-5 sm:grid-cols-2">
                                    {(
                                        [
                                            {
                                                name: 'ip',
                                                label: 'IPv4',
                                                maxLength: 15,
                                            },
                                            {
                                                name: 'hostname',
                                                label: 'Hostname',
                                                maxLength: 255,
                                            },
                                            {
                                                name: 'mac_address',
                                                label: 'MAC',
                                                maxLength: 17,
                                            },
                                        ] as const
                                    ).map((field) => (
                                        <Field
                                            key={field.name}
                                            data-invalid={!!errors[field.name]}
                                        >
                                            <FieldLabel
                                                htmlFor={`${id}-${field.name}`}
                                            >
                                                {field.label}
                                                {field.name === 'ip'
                                                    ? ' *'
                                                    : ''}
                                            </FieldLabel>
                                            <Input
                                                id={`${id}-${field.name}`}
                                                name={field.name}
                                                maxLength={field.maxLength}
                                                required={field.name === 'ip'}
                                                autoFocus={
                                                    field.name === 'ip' &&
                                                    !embedded
                                                }
                                                autoComplete="off"
                                                disabled={
                                                    processing || !canEdit
                                                }
                                                value={
                                                    field.name === 'ip'
                                                        ? ip
                                                        : field.name ===
                                                            'mac_address'
                                                          ? mac
                                                          : hostname
                                                }
                                                onChange={(event) => {
                                                    const value =
                                                        event.target.value;
                                                    if (field.name === 'ip')
                                                        setIp(value);
                                                    else if (
                                                        field.name ===
                                                        'mac_address'
                                                    )
                                                        setMac(value);
                                                    else setHostname(value);
                                                }}
                                                aria-invalid={
                                                    !!errors[field.name]
                                                }
                                                aria-describedby={`${id}-${field.name}-error`}
                                            />
                                            <InputError
                                                id={`${id}-${field.name}-error`}
                                                message={errors[field.name]}
                                            />
                                        </Field>
                                    ))}
                                </div>
                                <Field data-invalid={!!errors.description}>
                                    <FieldLabel htmlFor={`${id}-description`}>
                                        Descrizione
                                    </FieldLabel>
                                    <Textarea
                                        id={`${id}-description`}
                                        name="description"
                                        defaultValue={
                                            ipAddress?.description ?? ''
                                        }
                                        rows={4}
                                        maxLength={2000}
                                        disabled={processing || !canEdit}
                                        aria-invalid={!!errors.description}
                                        aria-describedby={`${id}-description-error`}
                                    />
                                    <InputError
                                        id={`${id}-description-error`}
                                        message={errors.description}
                                    />
                                </Field>
                                <Separator />
                                <h3 className="flex items-center gap-2 text-sm font-medium">
                                    <Server
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    Dispositivo / Interfaccia
                                </h3>
                                <IpAssignmentFields
                                    devices={devices}
                                    ipAddress={
                                        ipAddress ?? {
                                            device_id: assignment?.deviceId,
                                            device_interface_id:
                                                assignment?.interfaceId,
                                        }
                                    }
                                    disabled={processing || !canEdit}
                                    errors={errors}
                                    onAssignmentChange={(
                                        deviceId,
                                        interfaceId,
                                    ) =>
                                        setReleasing(
                                            Boolean(
                                                ipAddress?.device_id &&
                                                (!deviceId ||
                                                    (deviceId ===
                                                        ipAddress.device_id &&
                                                        ipAddress.device_interface_id &&
                                                        !interfaceId)),
                                            ),
                                        )
                                    }
                                    onInterfaceChange={(item) => {
                                        if (!mac && item?.mac_address)
                                            setMac(item.mac_address);
                                    }}
                                />
                                {releasing && (
                                    <Alert variant="destructive">
                                        <TriangleAlert />
                                        <AlertTitle>
                                            La rimozione dell’associazione
                                            libera l’IP
                                        </AlertTitle>
                                        <AlertDescription>
                                            Il record di {ipAddress?.ip} verrà
                                            eliminato con hostname, descrizione,
                                            MAC, stato e associazioni ai tag. Le
                                            altre modifiche al form non verranno
                                            salvate. Gli indirizzi esclusi
                                            restano esclusi dai suggerimenti.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <Separator />
                                <div className="grid items-start gap-5 sm:grid-cols-2">
                                    <IpMetadataFields
                                        statuses={statuses}
                                        tags={tags}
                                        ipAddress={ipAddress}
                                        disabled={processing}
                                        readOnly={!canEdit}
                                        errors={errors}
                                    />
                                </div>
                                <MacConfirmation errors={errors} />
                                <SheetFooter className="flex-row flex-wrap justify-end px-0 pb-0">
                                    {ipAddress && canEdit && (
                                        <IconButton
                                            label="Elimina dati IP"
                                            variant="destructive"
                                            className="mr-auto"
                                            disabled={processing}
                                            onClick={() => setDeleting(true)}
                                        >
                                            <Trash2 />
                                        </IconButton>
                                    )}
                                    {embedded && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={processing}
                                            onClick={() =>
                                                ipAddress
                                                    ? setEditing(false)
                                                    : setOpen(false)
                                            }
                                        >
                                            Annulla
                                        </Button>
                                    )}
                                    {canEdit &&
                                        (releasing ? (
                                            <Button
                                                type="button"
                                                variant="destructive"
                                                disabled={processing}
                                                onClick={() =>
                                                    setDeleting(true)
                                                }
                                            >
                                                <Trash2 />
                                                Libera IP…
                                            </Button>
                                        ) : (
                                            <SaveButton
                                                processing={processing}
                                                disabled={loadingSources}
                                            >
                                                {errors.mac_confirmation
                                                    ? 'Sostituisci MAC'
                                                    : 'Salva'}
                                            </SaveButton>
                                        ))}
                                </SheetFooter>
                            </FieldGroup>
                        )}
                    </Form>
                </div>
            )}
            {deleting && ipAddress && canEdit && editing && (
                <DeletionConfirmation
                    action={destroy.form({
                        ...route,
                        ipAddress: ipAddress.id,
                    })}
                    onCancel={() => {
                        setDeleting(false);
                        requestAnimationFrame(() =>
                            document.getElementById(`${id}-ip`)?.focus(),
                        );
                    }}
                    onSuccess={() => setOpen(false)}
                    submitLabel="Libera IP"
                >
                    <p>
                        Verrà eliminato il record di{' '}
                        <strong>{ipAddress.ip}</strong> con descrizione,
                        hostname, MAC, stato, associazioni ai tag e al
                        dispositivo/interfaccia. L’indirizzo resterà escluso dai
                        suggerimenti se presente in un intervallo escluso o
                        nelle ultime osservazioni valide. Le modifiche al form
                        non verranno salvate.
                    </p>
                </DeletionConfirmation>
            )}
        </div>
    );
    if (embedded) return content;
    return (
        <Sheet
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                if (value) {
                    setDeleting(false);
                    setReleasing(false);
                    setEditing(!ipAddress);
                    setIp(ipAddress?.ip ?? suggestedIp ?? '');
                    setMac(initialMac);
                    setHostname(initialHostname);
                }
            }}
        >
            {controlledOpen === undefined && (
                <SheetTrigger asChild>
                    <Button
                        variant={suggestedIp ? 'secondary' : 'default'}
                        aria-label={
                            suggestedIp
                                ? `Aggiungi IP ${suggestedIp}`
                                : 'Aggiungi IP'
                        }
                    >
                        {suggestedIp ? (
                            <>
                                Usa questo IP
                                <ArrowRight data-icon="inline-end" />
                            </>
                        ) : (
                            <>
                                <Plus data-icon="inline-start" />
                                Aggiungi IP
                            </>
                        )}
                    </Button>
                </SheetTrigger>
            )}
            <SheetContent
                overlayClassName="bg-black/30"
                className="w-full gap-0 overflow-y-auto sm:max-w-xl"
                onCloseAutoFocus={onCloseAutoFocus}
                aria-describedby={assignment ? `${id}-assignment` : undefined}
            >
                <SheetHeader className="gap-2 border-b px-6 py-5 pr-12">
                    <SheetTitle>
                        {deleting
                            ? `Libera IP ${ipAddress?.ip}`
                            : ipAddress
                              ? `${editing ? 'Modifica IP' : 'IP'} ${ipAddress.ip}`
                              : 'Nuovo IP'}
                    </SheetTitle>
                    {assignment && (
                        <SheetDescription id={`${id}-assignment`}>
                            {assignment.label}
                        </SheetDescription>
                    )}
                </SheetHeader>
                {content}
            </SheetContent>
        </Sheet>
    );
}
