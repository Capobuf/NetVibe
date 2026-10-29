import { Form, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    Pencil,
    Plus,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { type ComponentProps, useId, useState } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import { IconButton } from '@/components/icon-button';
import { TagBadge } from '@/components/networks/tag-badge';
import { Badge } from '@/components/ui/badge';
import InputError from '@/components/input-error';
import IpMetadataFields from '@/components/networks/ip-metadata-fields';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ipv4Number } from '@/lib/ipv4';
import {
    destroy,
    store,
    update,
} from '@/routes/companies/sites/subnets/ip-addresses';
import type { ExcludedRange, IpAddress, IpStatus, Tag } from '@/types/network';

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
    onCloseAutoFocus?: ComponentProps<typeof DialogContent>['onCloseAutoFocus'];
}) {
    const { auth } = usePage().props;
    const canEdit = auth.user.is_superadmin;
    const [internalOpen, setInternalOpen] = useState(false);
    const open = controlledOpen ?? internalOpen;
    const setOpen = onOpenChange ?? setInternalOpen;
    const [deleting, setDeleting] = useState(false);
    const [editing, setEditing] = useState(!ipAddress);
    const [ip, setIp] = useState(ipAddress?.ip ?? suggestedIp ?? '');
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

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                if (value) {
                    setDeleting(false);
                    setEditing(!ipAddress);
                    setIp(ipAddress?.ip ?? suggestedIp ?? '');
                }
            }}
        >
            {controlledOpen === undefined ? (
                <DialogTrigger asChild>
                    <Button
                        variant={suggestedIp ? 'secondary' : 'default'}
                        className="group motion-safe:transition-transform motion-safe:active:scale-[0.98]"
                        aria-label={
                            suggestedIp
                                ? `Aggiungi IP ${suggestedIp}`
                                : 'Aggiungi IP'
                        }
                    >
                        {suggestedIp ? (
                            <>
                                Usa questo IP
                                <ArrowRight
                                    data-icon="inline-end"
                                    className="motion-safe:transition-transform motion-safe:group-hover:translate-x-0.5"
                                />
                            </>
                        ) : (
                            <>
                                <Plus data-icon="inline-start" />
                                Aggiungi IP
                            </>
                        )}
                    </Button>
                </DialogTrigger>
            ) : null}
            <DialogContent
                className="max-h-[90dvh] overflow-y-auto sm:max-w-xl"
                onCloseAutoFocus={onCloseAutoFocus}
                aria-describedby={undefined}
            >
                <DialogHeader>
                    <DialogTitle>
                        {deleting
                            ? `Elimina dati di ${ipAddress?.ip}`
                            : ipAddress
                              ? `${editing ? 'Modifica IP' : 'IP'} ${ipAddress.ip}`
                              : ip || 'Nuovo IP'}
                    </DialogTitle>
                </DialogHeader>
                {ipAddress && !editing ? (
                    <>
                        <dl className="grid gap-4 text-sm sm:grid-cols-2">
                            {(
                                [
                                    ['IP', ipAddress.ip],
                                    ['Hostname', ipAddress.hostname],
                                    ['MAC Address', ipAddress.mac_address],
                                ] as const
                            ).map(([label, value]) => (
                                <div
                                    key={label}
                                    className="flex min-w-0 flex-col gap-1"
                                >
                                    <dt className="text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd className="break-words">
                                        {value || '—'}
                                    </dd>
                                </div>
                            ))}
                            <div className="flex min-w-0 flex-col gap-1">
                                <dt className="text-muted-foreground">Stato</dt>
                                <dd>
                                    {ipAddress.status ? (
                                        <Badge variant="secondary">
                                            {ipAddress.status.name}
                                        </Badge>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div className="flex min-w-0 flex-col gap-1 sm:col-span-2">
                                <dt className="text-muted-foreground">
                                    Descrizione
                                </dt>
                                <dd className="break-words whitespace-pre-wrap">
                                    {ipAddress.description || '—'}
                                </dd>
                            </div>
                            <div className="flex min-w-0 flex-col gap-1 sm:col-span-2">
                                <dt className="text-muted-foreground">Tag</dt>
                                <dd className="flex flex-wrap gap-1">
                                    {ipAddress.tags.length
                                        ? ipAddress.tags.map((tag) => (
                                              <TagBadge
                                                  key={tag.id}
                                                  color={tag.color}
                                                  className="max-w-full break-words whitespace-normal"
                                              >
                                                  {tag.name}
                                              </TagBadge>
                                          ))
                                        : '—'}
                                </dd>
                            </div>
                        </dl>
                        {excluded ? (
                            <p className="text-sm text-muted-foreground">
                                Escluso dai suggerimenti
                            </p>
                        ) : null}
                        {canEdit ? (
                            <DialogFooter className="flex-row justify-end">
                                <IconButton
                                    label={`Modifica IP ${ipAddress.ip}`}
                                    onClick={() => setEditing(true)}
                                >
                                    <Pencil />
                                </IconButton>
                            </DialogFooter>
                        ) : null}
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
                            onSuccess={() => setOpen(false)}
                            onBefore={() => canEdit && editing}
                            options={{ preserveScroll: true }}
                            className="flex flex-col gap-5"
                        >
                            {({ processing, errors }) => (
                                <FieldGroup>
                                    {excluded ? (
                                        <Alert>
                                            <TriangleAlert />
                                            <AlertTitle>
                                                IP in un intervallo escluso
                                            </AlertTitle>
                                            <AlertDescription>
                                                Puoi documentarlo manualmente.
                                                L’esclusione riguarda solo i
                                                suggerimenti e non impedisce il
                                                salvataggio.
                                            </AlertDescription>
                                        </Alert>
                                    ) : null}
                                    <div className="grid gap-7 sm:grid-cols-2">
                                        {(
                                            [
                                                {
                                                    name: 'hostname',
                                                    label: 'Hostname',
                                                    maxLength: 255,
                                                },
                                                {
                                                    name: 'ip',
                                                    label: 'IP',
                                                    maxLength: 15,
                                                },
                                                {
                                                    name: 'mac_address',
                                                    label: 'MAC Address',
                                                    maxLength: 17,
                                                },
                                            ] as const
                                        ).map((field) => (
                                            <Field
                                                key={field.name}
                                                className={
                                                    field.name === 'hostname'
                                                        ? 'min-w-0 sm:col-span-2'
                                                        : 'min-w-0'
                                                }
                                                data-invalid={Boolean(
                                                    errors[field.name],
                                                )}
                                                data-disabled={processing}
                                            >
                                                <FieldLabel
                                                    htmlFor={`${id}-${field.name}`}
                                                >
                                                    {field.label}
                                                    {field.name === 'ip' ? (
                                                        <span aria-hidden="true">
                                                            *
                                                        </span>
                                                    ) : null}
                                                </FieldLabel>
                                                <Input
                                                    id={`${id}-${field.name}`}
                                                    name={field.name}
                                                    maxLength={field.maxLength}
                                                    required={
                                                        field.name === 'ip'
                                                    }
                                                    autoFocus={
                                                        field.name ===
                                                        'hostname'
                                                    }
                                                    autoComplete="off"
                                                    readOnly={!canEdit}
                                                    disabled={processing}
                                                    defaultValue={
                                                        field.name === 'ip'
                                                            ? undefined
                                                            : (ipAddress?.[
                                                                  field.name
                                                              ] ?? '')
                                                    }
                                                    value={
                                                        field.name === 'ip'
                                                            ? ip
                                                            : undefined
                                                    }
                                                    onChange={
                                                        field.name === 'ip'
                                                            ? (event) =>
                                                                  setIp(
                                                                      event
                                                                          .target
                                                                          .value,
                                                                  )
                                                            : undefined
                                                    }
                                                    aria-invalid={Boolean(
                                                        errors[field.name],
                                                    )}
                                                    aria-describedby={`${field.name === 'hostname' ? `${id}-help ` : ''}${id}-${field.name}-error`}
                                                />
                                                <InputError
                                                    id={`${id}-${field.name}-error`}
                                                    message={errors[field.name]}
                                                />
                                            </Field>
                                        ))}
                                    </div>
                                    <Field
                                        data-invalid={Boolean(
                                            errors.description,
                                        )}
                                    >
                                        <FieldLabel
                                            htmlFor={`${id}-description`}
                                        >
                                            Descrizione
                                        </FieldLabel>
                                        <Textarea
                                            id={`${id}-description`}
                                            name="description"
                                            defaultValue={
                                                ipAddress?.description ?? ''
                                            }
                                            rows={4}
                                            className="min-h-28 resize-y"
                                            maxLength={2000}
                                            readOnly={!canEdit}
                                            disabled={processing}
                                            aria-invalid={Boolean(
                                                errors.description,
                                            )}
                                            aria-describedby={`${id}-help ${id}-description-error`}
                                        />
                                        <InputError
                                            id={`${id}-description-error`}
                                            message={errors.description}
                                        />
                                        <p
                                            id={`${id}-help`}
                                            className="text-sm text-muted-foreground"
                                        >
                                            Compila almeno Hostname o
                                            Descrizione.
                                        </p>
                                    </Field>
                                    <div className="grid items-start gap-7 sm:grid-cols-2">
                                        <IpMetadataFields
                                            statuses={statuses}
                                            tags={tags}
                                            ipAddress={ipAddress}
                                            disabled={processing}
                                            readOnly={!canEdit}
                                            errors={errors}
                                        />
                                    </div>
                                    <DialogFooter>
                                        {ipAddress && canEdit ? (
                                            <IconButton
                                                label="Elimina dati IP"
                                                variant="destructive"
                                                className="sm:mr-auto"
                                                disabled={processing}
                                                onClick={() =>
                                                    setDeleting(true)
                                                }
                                            >
                                                <Trash2 />
                                            </IconButton>
                                        ) : null}
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                {canEdit ? 'Annulla' : 'Chiudi'}
                                            </Button>
                                        </DialogClose>
                                        {canEdit ? (
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {processing ? (
                                                    <Spinner />
                                                ) : (
                                                    <Check data-icon="inline-start" />
                                                )}
                                                Salva
                                            </Button>
                                        ) : null}
                                    </DialogFooter>
                                </FieldGroup>
                            )}
                        </Form>
                    </div>
                )}
                {deleting && ipAddress && canEdit && editing ? (
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
                    >
                        <p>
                            Verrà eliminato il record di{' '}
                            <strong>{ipAddress.ip}</strong> con descrizione,
                            hostname, MAC, stato e associazioni ai tag. Se
                            l’indirizzo è escluso, resterà escluso dai
                            suggerimenti; potrai comunque documentarlo
                            manualmente.
                        </p>
                        <p>
                            Questa operazione non modifica alcun dispositivo
                            sulla rete.
                        </p>
                    </DeletionConfirmation>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
