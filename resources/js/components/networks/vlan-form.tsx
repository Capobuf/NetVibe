import { MikrotikEditMode } from '@/components/networks/mikrotik-edit-mode';
import { Form } from '@inertiajs/react';
import { Check, Pencil, Plus, Trash2 } from 'lucide-react';
import { useId, useState } from 'react';
import { GuidedDeletion } from '@/components/networks/guided-deletion';
import { IconButton } from '@/components/icon-button';
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
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import {
    deletionImpact,
    destroy,
    store,
    update,
} from '@/routes/companies/sites/vlans';
import type { Vlan } from '@/types/network';

export default function VlanForm({
    companyId,
    siteId,
    context,
    vlan,
}: {
    companyId: number;
    siteId: number;
    context: string;
    vlan?: Vlan;
}) {
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const id = useId();
    const route = { company: companyId, site: siteId };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                if (value) setDeleting(false);
            }}
        >
            <DialogTrigger asChild>
                {vlan ? (
                    <IconButton label={`Modifica VLAN ${vlan.tag}`}>
                        <Pencil />
                    </IconButton>
                ) : (
                    <Button variant="outline">
                        <Plus data-icon="inline-start" />
                        Nuova VLAN
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {deleting
                            ? `Elimina VLAN ${vlan?.tag}`
                            : vlan
                              ? `Modifica VLAN ${vlan.tag}`
                              : 'Nuova VLAN'}
                    </DialogTitle>
                    <DialogDescription>
                        {deleting
                            ? context
                            : `${context}. La VLAN può restare senza subnet.`}
                    </DialogDescription>
                </DialogHeader>
                <div hidden={deleting}>
                    <Form
                        {...(vlan
                            ? update.form({ ...route, vlan: vlan.id })
                            : store.form(route))}
                        onSuccess={() => setOpen(false)}
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <FieldGroup>
                                <Field data-invalid={Boolean(errors.tag)}>
                                    <FieldLabel htmlFor={`${id}-tag`}>
                                        ID VLAN
                                    </FieldLabel>
                                    <Input
                                        id={`${id}-tag`}
                                        name="tag"
                                        type="number"
                                        min={1}
                                        max={4094}
                                        required
                                        autoFocus
                                        defaultValue={vlan?.tag ?? ''}
                                        aria-invalid={Boolean(errors.tag)}
                                        aria-describedby={`${id}-tag-help ${id}-tag-error`}
                                    />
                                    <p
                                        id={`${id}-tag-help`}
                                        className="text-sm text-muted-foreground"
                                    >
                                        Da 1 a 4094, univoco in questa sede.
                                    </p>
                                    <InputError
                                        id={`${id}-tag-error`}
                                        message={errors.tag}
                                    />
                                </Field>
                                <Field data-invalid={Boolean(errors.name)}>
                                    <FieldLabel htmlFor={`${id}-name`}>
                                        Nome (facoltativo)
                                    </FieldLabel>
                                    <Input
                                        id={`${id}-name`}
                                        name="name"
                                        maxLength={255}
                                        defaultValue={vlan?.name ?? ''}
                                        placeholder="Es. Uffici"
                                        aria-invalid={Boolean(errors.name)}
                                        aria-describedby={`${id}-name-error`}
                                    />
                                    <InputError
                                        id={`${id}-name-error`}
                                        message={errors.name}
                                    />
                                </Field>
                                <MikrotikEditMode
                                    error={errors.mikrotik_edit_mode}
                                />
                                <DialogFooter>
                                    {vlan ? (
                                        <IconButton
                                            label="Elimina VLAN"
                                            variant="destructive"
                                            className="mr-auto"
                                            onClick={() => setDeleting(true)}
                                            disabled={processing}
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
                                            Annulla
                                        </Button>
                                    </DialogClose>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <Check data-icon="inline-start" />
                                        )}
                                        {vlan ? 'Salva VLAN' : 'Crea VLAN'}
                                    </Button>
                                </DialogFooter>
                            </FieldGroup>
                        )}
                    </Form>
                </div>
                {deleting && vlan ? (
                    <GuidedDeletion
                        kind="vlan"
                        previewUrl={deletionImpact.url({
                            ...route,
                            vlan: vlan.id,
                        })}
                        action={destroy.form({ ...route, vlan: vlan.id })}
                        onCancel={() => {
                            setDeleting(false);
                            requestAnimationFrame(() =>
                                document.getElementById(`${id}-tag`)?.focus(),
                            );
                        }}
                        onSuccess={() => setOpen(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
