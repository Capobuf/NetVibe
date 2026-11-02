import { SaveButton } from '@/components/save-button';
import { MacConfirmation } from '@/components/devices/mac-confirmation';
import { Form } from '@inertiajs/react';
import { useId, useState, type ComponentProps, type ReactNode } from 'react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
    DialogClose,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import InputError from '@/components/input-error';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import {
    store,
    update,
    destroy,
} from '@/routes/companies/sites/devices/interfaces';
import {
    interfaceName,
    mikrotikInterfaceName,
    type DeviceInterface,
    type MikrotikInterface,
} from '@/types/device';
import { MikrotikInterfaceBadge } from '@/components/devices/mikrotik-interface-badge';
import type { Port } from '@/lib/hardware-editor/document';

export function InterfaceForm({
    company,
    site,
    device,
    item,
    ports,
    mikrotikInterfaces = [],
    linkedIps = [],
    open: controlledOpen,
    onOpenChange,
    onCloseAutoFocus,
    description,
    children,
}: {
    company: number;
    site: number;
    device: number;
    item?: DeviceInterface;
    ports: Port[];
    mikrotikInterfaces?: MikrotikInterface[];
    linkedIps?: { id: number; ip: string }[];
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    onCloseAutoFocus?: ComponentProps<typeof DialogContent>['onCloseAutoFocus'];
    description?: string;
    children?: ReactNode;
}) {
    const [internalOpen, setInternalOpen] = useState(false);
    const open = controlledOpen ?? internalOpen;
    const [confirmingMac, setConfirmingMac] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const id = useId();
    const [sourceId, setSourceId] = useState(
        String(item?.mikrotik?.id ?? 'none'),
    );
    const port = ports.find((port) => port.id === item?.connector_id);
    const compatible = (source: MikrotikInterface) =>
        !item?.connector_id || source.port_type === port?.type;
    const sources = mikrotikInterfaces.filter(
        (source) =>
            compatible(source) &&
            (!source.device_interface_id ||
                source.device_interface_id === item?.id),
    );
    if (
        item?.mikrotik &&
        !sources.some((source) => source.id === item.mikrotik?.id)
    )
        sources.push(item.mikrotik);
    const source = sources.find((source) => String(source.id) === sourceId);
    function setOpen(value: boolean) {
        (onOpenChange ?? setInternalOpen)(value);
        setRemoving(false);
        setConfirmingMac(false);
        setSourceId(String(item?.mikrotik?.id ?? 'none'));
    }
    const [removing, setRemoving] = useState(false);
    const route = { company, site, device };
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {controlledOpen === undefined && (
                <DialogTrigger asChild>
                    <Button variant="outline" size="sm">
                        {item ? (
                            <Pencil data-icon="inline-start" />
                        ) : (
                            <Plus data-icon="inline-start" />
                        )}
                        {item ? 'Modifica interfaccia' : 'Nuova interfaccia'}
                    </Button>
                </DialogTrigger>
            )}
            <DialogContent
                className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl"
                aria-describedby={
                    description && !removing ? `${id}-context` : undefined
                }
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <DialogHeader>
                    <DialogTitle>
                        {removing
                            ? 'Elimina interfaccia'
                            : item
                              ? interfaceName(item, ports)
                              : 'Nuova interfaccia'}
                    </DialogTitle>
                    {description && !removing && (
                        <DialogDescription id={`${id}-context`}>
                            {description}
                        </DialogDescription>
                    )}
                </DialogHeader>
                {removing && item ? (
                    <DeletionConfirmation
                        action={destroy.form({ ...route, interface: item.id })}
                        onCancel={() => setRemoving(false)}
                        onSuccess={() => setOpen(false)}
                        submitLabel="Elimina interfaccia e libera IP"
                        confirmationKey={JSON.stringify(linkedIps)}
                        fields={() =>
                            linkedIps.map((ip) => (
                                <input
                                    key={ip.id}
                                    type="hidden"
                                    name="ip_ids[]"
                                    value={ip.id}
                                />
                            ))
                        }
                    >
                        <p>
                            L’interfaccia verrà eliminata insieme ai{' '}
                            {linkedIps.length} record IP collegati
                            {linkedIps.length > 0 &&
                                ` (${linkedIps.map((ip) => ip.ip).join(', ')})`}
                            . Verranno rimossi hostname, descrizione, MAC, stato
                            e associazioni ai tag di questi IP. Gli indirizzi
                            torneranno liberi; quelli esclusi resteranno esclusi
                            dai suggerimenti.
                        </p>
                    </DeletionConfirmation>
                ) : (
                    <>
                        <Form
                            id={`${id}-form`}
                            errorBag="interface"
                            onStart={() => setSubmitting(true)}
                            onFinish={() => setSubmitting(false)}
                            onError={(errors) =>
                                setConfirmingMac(!!errors.mac_confirmation)
                            }
                            {...(item
                                ? update.form({ ...route, interface: item.id })
                                : store.form(route))}
                            onSuccess={() => setOpen(false)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                                    {item?.kind === 'physical' &&
                                        sources.length > 0 && (
                                            <Field
                                                className="sm:col-span-2"
                                                data-invalid={
                                                    !!errors.mikrotik_source_id
                                                }
                                            >
                                                <FieldLabel
                                                    htmlFor={`${id}-mikrotik`}
                                                >
                                                    Interfaccia MikroTik
                                                </FieldLabel>
                                                <input
                                                    type="hidden"
                                                    name="mikrotik_source_id"
                                                    value={
                                                        sourceId === 'none'
                                                            ? ''
                                                            : sourceId
                                                    }
                                                />
                                                <Select
                                                    value={sourceId}
                                                    onValueChange={setSourceId}
                                                    disabled={processing}
                                                >
                                                    <SelectTrigger
                                                        id={`${id}-mikrotik`}
                                                        className="w-full"
                                                        aria-invalid={
                                                            !!errors.mikrotik_source_id
                                                        }
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectGroup>
                                                            <SelectItem value="none">
                                                                Nessuna
                                                                associazione
                                                            </SelectItem>
                                                            {sources.map(
                                                                (candidate) => (
                                                                    <SelectItem
                                                                        key={
                                                                            candidate.id
                                                                        }
                                                                        value={String(
                                                                            candidate.id,
                                                                        )}
                                                                        disabled={
                                                                            !compatible(
                                                                                candidate,
                                                                            ) ||
                                                                            ((candidate.stale ||
                                                                                !!candidate.problem) &&
                                                                                candidate.id !==
                                                                                    item
                                                                                        .mikrotik
                                                                                        ?.id)
                                                                        }
                                                                    >
                                                                        {mikrotikInterfaceName(
                                                                            candidate,
                                                                        )}
                                                                        {!compatible(
                                                                            candidate,
                                                                        )
                                                                            ? ' (incompatibile)'
                                                                            : null}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectGroup>
                                                    </SelectContent>
                                                </Select>
                                                {source && (
                                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                                        <MikrotikInterfaceBadge
                                                            source={source}
                                                        />
                                                        <span className="tabular-nums">
                                                            {source.mac_address ??
                                                                '—'}
                                                        </span>
                                                    </div>
                                                )}
                                                <InputError
                                                    message={
                                                        errors.mikrotik_source_id
                                                    }
                                                />
                                            </Field>
                                        )}
                                    <Field
                                        data-invalid={!!errors.name}
                                        className={
                                            !item?.connector_id
                                                ? 'sm:col-span-2'
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor={`${id}-name`}>
                                            {item?.connector_id
                                                ? 'Alias locale'
                                                : 'Nome *'}
                                        </FieldLabel>
                                        <Input
                                            id={`${id}-name`}
                                            name="name"
                                            defaultValue={item?.name ?? ''}
                                            placeholder={
                                                item?.connector_id
                                                    ? source?.name ||
                                                      interfaceName(
                                                          {
                                                              ...item,
                                                              name: null,
                                                          },
                                                          ports,
                                                      )
                                                    : undefined
                                            }
                                            maxLength={255}
                                            required={!item?.connector_id}
                                            autoFocus
                                            disabled={processing}
                                            aria-invalid={!!errors.name}
                                        />
                                        <InputError message={errors.name} />
                                    </Field>
                                    {!item?.connector_id && (
                                        <Field>
                                            <FieldLabel htmlFor={`${id}-kind`}>
                                                Tipo
                                            </FieldLabel>
                                            <Select
                                                name="kind"
                                                defaultValue={
                                                    item?.kind ?? 'physical'
                                                }
                                                disabled={processing}
                                            >
                                                <SelectTrigger
                                                    id={`${id}-kind`}
                                                    className="w-full"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectGroup>
                                                        <SelectItem value="physical">
                                                            Fisica
                                                        </SelectItem>
                                                        <SelectItem value="logical">
                                                            Logica
                                                        </SelectItem>
                                                    </SelectGroup>
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                    )}
                                    <Field data-invalid={!!errors.mac_address}>
                                        <FieldLabel htmlFor={`${id}-mac`}>
                                            MAC interfaccia
                                        </FieldLabel>
                                        <Input
                                            id={`${id}-mac`}
                                            name="mac_address"
                                            defaultValue={
                                                item?.mac_address ?? ''
                                            }
                                            placeholder={
                                                source?.problem
                                                    ? undefined
                                                    : (source?.mac_address ??
                                                      undefined)
                                            }
                                            maxLength={17}
                                            disabled={processing}
                                            aria-invalid={!!errors.mac_address}
                                        />
                                        <InputError
                                            message={errors.mac_address}
                                        />
                                    </Field>
                                    <div className="sm:col-span-2">
                                        <MacConfirmation errors={errors} />
                                    </div>
                                </FieldGroup>
                            )}
                        </Form>
                        {children}
                        <DialogFooter>
                            {item && !item.connector_id && (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    className="sm:mr-auto"
                                    onClick={() => setRemoving(true)}
                                    disabled={submitting}
                                >
                                    <Trash2 data-icon="inline-start" />
                                    Elimina
                                </Button>
                            )}
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={submitting}
                                >
                                    Annulla
                                </Button>
                            </DialogClose>
                            <SaveButton
                                form={`${id}-form`}
                                processing={submitting}
                            >
                                {confirmingMac ? 'Sostituisci MAC' : 'Salva'}
                            </SaveButton>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
