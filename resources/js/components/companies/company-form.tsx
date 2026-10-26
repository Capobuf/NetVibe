import { Form } from '@inertiajs/react';
import { Building2, Check, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useId, useState } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import { IconButton } from '@/components/icon-button';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import { destroy, store, update } from '@/routes/administration/companies';
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
                <IconButton
                    label={
                        company ? `Modifica ${company.name}` : 'Nuova azienda'
                    }
                    variant={company ? 'outline' : 'default'}
                >
                    {company ? <Pencil /> : <Plus />}
                </IconButton>
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
                            <>
                                <div className="flex flex-col gap-2">
                                    <Label htmlFor={id}>Nome azienda</Label>
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
                                </div>
                                <DialogFooter className="flex-row items-center justify-end">
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
                                        <IconButton
                                            label="Annulla"
                                            disabled={processing}
                                        >
                                            <X />
                                        </IconButton>
                                    </DialogClose>
                                    <IconButton
                                        label={
                                            company
                                                ? 'Salva azienda'
                                                : 'Crea azienda'
                                        }
                                        type="submit"
                                        variant="default"
                                        disabled={processing}
                                    >
                                        {processing ? <Spinner /> : <Check />}
                                    </IconButton>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </div>
                {deleting && company ? (
                    <DeletionConfirmation
                        action={destroy.form(company.id)}
                        sites={company.sites}
                        onCancel={() => {
                            setDeleting(false);
                            requestAnimationFrame(() =>
                                document.getElementById(id)?.focus(),
                            );
                        }}
                        onSuccess={() => setOpen(false)}
                    >
                        <p>
                            L’azienda <strong>{company.name}</strong> e la sua
                            anagrafica verranno eliminate.
                        </p>
                        {company.sites.length > 1 ? (
                            <>
                                <p>
                                    Verranno eliminate anche tutte le{' '}
                                    {company.sites.length} sedi:
                                </p>
                                <ul className="flex flex-wrap gap-2">
                                    {company.sites.map((site) => (
                                        <li key={site.id}>
                                            <Badge variant="secondary">
                                                {site.name}
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        ) : null}
                        <p>Questa operazione non può essere annullata.</p>
                    </DeletionConfirmation>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
