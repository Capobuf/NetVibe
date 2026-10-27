import { Form, usePage } from '@inertiajs/react';
import { Check, Plus, Trash2 } from 'lucide-react';
import { useId, useState } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    destroy,
    store,
    update,
} from '@/routes/companies/sites/subnets/ip-addresses';
import type { IpAddress } from '@/types/network';

export default function IpAddressForm({
    companyId,
    siteId,
    subnetId,
    context,
    ipAddress,
}: {
    companyId: number;
    siteId: number;
    subnetId: number;
    context: string;
    ipAddress?: IpAddress;
}) {
    const { auth } = usePage().props;
    const canEdit = auth.user.is_superadmin;
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const id = useId();
    const route = { company: companyId, site: siteId, subnet: subnetId };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                if (value) setDeleting(false);
            }}
        >
            <DialogTrigger asChild>
                {ipAddress ? (
                    <button
                        type="button"
                        className="font-mono font-medium tabular-nums underline-offset-4 hover:underline focus-visible:underline"
                        aria-label={`Apri IP ${ipAddress.ip}`}
                    >
                        {ipAddress.ip}
                    </button>
                ) : (
                    <Button>
                        <Plus data-icon="inline-start" />
                        Documenta IP
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
                            <>
                                <p
                                    id={`${id}-help`}
                                    className="text-sm text-muted-foreground"
                                >
                                    Inserisci almeno una descrizione o un
                                    hostname. Il MAC è facoltativo e può essere
                                    condiviso da più IP.
                                </p>
                                {(
                                    [
                                        {
                                            name: 'ip',
                                            label: 'IPv4',
                                            maxLength: 15,
                                        },
                                        {
                                            name: 'description',
                                            label: 'Descrizione',
                                            maxLength: 2000,
                                        },
                                        {
                                            name: 'hostname',
                                            label: 'Hostname',
                                            maxLength: 255,
                                        },
                                        {
                                            name: 'mac_address',
                                            label: 'MAC (facoltativo)',
                                            maxLength: 17,
                                        },
                                    ] as const
                                ).map((field) => (
                                    <div
                                        key={field.name}
                                        className="flex flex-col gap-2"
                                    >
                                        <Label htmlFor={`${id}-${field.name}`}>
                                            {field.label}
                                        </Label>
                                        <Input
                                            id={`${id}-${field.name}`}
                                            name={field.name}
                                            maxLength={field.maxLength}
                                            required={field.name === 'ip'}
                                            autoFocus={field.name === 'ip'}
                                            autoComplete="off"
                                            readOnly={!canEdit}
                                            disabled={processing}
                                            defaultValue={
                                                ipAddress?.[field.name] ?? ''
                                            }
                                            aria-invalid={Boolean(
                                                errors[field.name],
                                            )}
                                            aria-describedby={`${id}-help ${id}-${field.name}-error`}
                                        />
                                        <InputError
                                            id={`${id}-${field.name}-error`}
                                            message={errors[field.name]}
                                        />
                                    </div>
                                ))}
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
                            </>
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
                            hostname e MAC. Potrai documentare nuovamente questo
                            indirizzo.
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
