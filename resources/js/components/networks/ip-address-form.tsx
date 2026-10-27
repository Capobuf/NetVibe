import { Form, usePage } from '@inertiajs/react';
import { ArrowRight, Check, Plus, Trash2, TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import InputError from '@/components/input-error';
import IpMetadataFields from '@/components/networks/ip-metadata-fields';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
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
    context,
    ipAddress,
    excludedRanges,
    statuses,
    tags,
    suggestedIp,
    iconTrigger = false,
}: {
    companyId: number;
    siteId: number;
    subnetId: number;
    context: string;
    ipAddress?: IpAddress;
    excludedRanges: ExcludedRange[];
    statuses: IpStatus[];
    tags: Tag[];
    suggestedIp?: string;
    iconTrigger?: boolean;
}) {
    const { auth } = usePage().props;
    const canEdit = auth.user.is_superadmin;
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
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
                    setIp(ipAddress?.ip ?? suggestedIp ?? '');
                }
            }}
        >
            <DialogTrigger asChild>
                {iconTrigger ? (
                    <Button
                        type="button"
                        size="icon"
                        aria-label={
                            ipAddress
                                ? `Apri IP ${ipAddress.ip}`
                                : `Documenta IP ${suggestedIp}`
                        }
                        variant="ghost"
                    >
                        {ipAddress ? <ArrowRight /> : <Plus />}
                    </Button>
                ) : ipAddress ? (
                    <Button
                        type="button"
                        variant="link"
                        className="h-auto p-0 font-mono tabular-nums"
                        aria-label={`Apri IP ${ipAddress.ip}`}
                    >
                        {ipAddress.ip}
                    </Button>
                ) : (
                    <Button variant={suggestedIp ? 'outline' : 'default'}>
                        <Plus data-icon="inline-start" />
                        {suggestedIp
                            ? `Primo proponibile: ${suggestedIp}`
                            : 'Documenta IP'}
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {deleting
                            ? `Libera ${ipAddress?.ip}`
                            : ipAddress
                              ? `IP ${ipAddress.ip}`
                              : 'Documenta IP'}
                    </DialogTitle>
                    <DialogDescription>{context}</DialogDescription>
                </DialogHeader>
                <div hidden={deleting}>
                    <Form
                        {...(ipAddress
                            ? update.form({ ...route, ipAddress: ipAddress.id })
                            : store.form(route))}
                        onSuccess={() => setOpen(false)}
                        onBefore={() => canEdit}
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-5"
                    >
                        {({ processing, errors }) => (
                            <FieldGroup>
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
                                            required={field.name === 'ip'}
                                            autoFocus={
                                                field.name === 'hostname'
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
                                                              event.target
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
                                <Field
                                    data-invalid={Boolean(errors.description)}
                                >
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
                                        Compila almeno Hostname o Descrizione.
                                    </p>
                                </Field>
                                <IpMetadataFields
                                    statuses={statuses}
                                    tags={tags}
                                    ipAddress={ipAddress}
                                    disabled={processing}
                                    readOnly={!canEdit}
                                    errors={errors}
                                />
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
                                <DialogFooter>
                                    {ipAddress && canEdit ? (
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            className="sm:mr-auto"
                                            disabled={processing}
                                            onClick={() => setDeleting(true)}
                                        >
                                            <Trash2 data-icon="inline-start" />
                                            Libera IP
                                        </Button>
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
                                            {ipAddress
                                                ? 'Salva IP'
                                                : 'Documenta IP'}
                                        </Button>
                                    ) : null}
                                </DialogFooter>
                            </FieldGroup>
                        )}
                    </Form>
                </div>
                {deleting && ipAddress && canEdit ? (
                    <DeletionConfirmation
                        action={destroy.form({
                            ...route,
                            ipAddress: ipAddress.id,
                        })}
                        sites={[]}
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
