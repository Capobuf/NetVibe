import { Form } from '@inertiajs/react';
import { Building2, Pencil, Plus, Trash2 } from 'lucide-react';
import { useId, useState } from 'react';
import { GuidedDeletion } from '@/components/networks/guided-deletion';
import { IconButton } from '@/components/icon-button';
import { Button } from '@/components/ui/button';
import InputError from '@/components/input-error';
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
} from '@/routes/administration/companies';
import type { Company, Site } from '@/types/company';

export default function CompanyForm({
    company,
}: {
    company?: Company & { sites: Site[] };
}) {
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
                {company ? (
                    <IconButton label={`Modifica ${company.name}`}>
                        <Pencil />
                    </IconButton>
                ) : (
                    <Button>
                        <Plus />
                        Nuova azienda
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Building2 className="size-5" />
                        {deleting
                            ? 'Elimina azienda'
                            : company
                              ? 'Modifica azienda'
                              : 'Nuova azienda'}
                    </DialogTitle>
                    <DialogDescription>
                        {deleting
                            ? 'Verifica cosa verrà rimosso prima di confermare.'
                            : company
                              ? 'Aggiorna il nome dell’azienda.'
                              : 'Inserisci il nome dell’azienda da gestire.'}
                    </DialogDescription>
                </DialogHeader>
                <div hidden={deleting}>
                    <Form
                        {...(company ? update.form(company.id) : store.form())}
                        onSuccess={() => setOpen(false)}
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <FieldGroup>
                                <Field data-invalid={Boolean(errors.name)}>
                                    <FieldLabel htmlFor={id}>
                                        Nome azienda
                                    </FieldLabel>
                                    <Input
                                        id={id}
                                        name="name"
                                        defaultValue={company?.name ?? ''}
                                        required
                                        maxLength={255}
                                        autoFocus
                                        aria-invalid={Boolean(errors.name)}
                                        aria-describedby={
                                            errors.name
                                                ? `${id}-error`
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        id={`${id}-error`}
                                        message={errors.name}
                                    />
                                </Field>
                                <DialogFooter className="flex-row flex-wrap items-center justify-end">
                                    {company ? (
                                        <IconButton
                                            label="Elimina azienda"
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
                                        {processing ? <Spinner /> : null}
                                        {company
                                            ? 'Salva modifiche'
                                            : 'Crea azienda'}
                                    </Button>
                                </DialogFooter>
                            </FieldGroup>
                        )}
                    </Form>
                </div>
                {deleting && company ? (
                    <GuidedDeletion
                        action={destroy.form(company.id)}
                        kind="company"
                        previewUrl={deletionImpact.url(company.id)}
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
