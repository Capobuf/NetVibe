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
} from '@/routes/administration/ip-statuses';
import type { IpStatus } from '@/types/network';

export default function IpStatusForm({ status }: { status?: IpStatus }) {
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const id = useId();

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                if (value) setDeleting(false);
            }}
        >
            <DialogTrigger asChild>
                {status ? (
                    <IconButton label={`Rinomina stato ${status.name}`}>
                        <Pencil />
                    </IconButton>
                ) : (
                    <Button>
                        <Plus data-icon="inline-start" />
                        Nuovo stato
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent
                aria-describedby={undefined}
                className="max-h-[90dvh] overflow-y-auto"
            >
                <DialogHeader>
                    <DialogTitle>
                        {deleting
                            ? 'Elimina stato'
                            : status
                              ? 'Rinomina stato'
                              : 'Nuovo stato IP'}
                    </DialogTitle>
                </DialogHeader>
                <div hidden={deleting}>
                    <Form
                        {...(status
                            ? update.form({ ipStatus: status.id })
                            : store.form())}
                        onSuccess={() => setOpen(false)}
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <FieldGroup>
                                <Field data-invalid={Boolean(errors.name)}>
                                    <FieldLabel htmlFor={id}>Nome</FieldLabel>
                                    <Input
                                        id={id}
                                        name="name"
                                        required
                                        maxLength={255}
                                        autoFocus
                                        defaultValue={status?.name ?? ''}
                                        disabled={processing}
                                        aria-invalid={Boolean(errors.name)}
                                        aria-describedby={`${id}-error`}
                                    />
                                    <InputError
                                        id={`${id}-error`}
                                        message={errors.name}
                                    />
                                </Field>
                                <DialogFooter>
                                    {status ? (
                                        <IconButton
                                            label="Elimina stato"
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
                                        {status ? 'Salva stato' : 'Crea stato'}
                                    </Button>
                                </DialogFooter>
                            </FieldGroup>
                        )}
                    </Form>
                </div>
                {deleting && status ? (
                    <GuidedDeletion
                        kind="status"
                        previewUrl={deletionImpact.url({ ipStatus: status.id })}
                        action={destroy.form({ ipStatus: status.id })}
                        onCancel={() => {
                            setDeleting(false);
                            requestAnimationFrame(() =>
                                document.getElementById(id)?.focus(),
                            );
                        }}
                        onSuccess={() => setOpen(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
