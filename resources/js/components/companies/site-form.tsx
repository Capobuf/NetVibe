import { Form } from '@inertiajs/react';
import { Check, MapPin, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useId, useState } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import { IconButton } from '@/components/icon-button';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    destroy,
    store,
    update,
} from '@/routes/administration/companies/sites';
import type { Company, Site } from '@/types/company';

export default function SiteForm({
    company,
    sites,
    site,
}: {
    company: Company;
    sites: Site[];
    site?: Site;
}) {
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const id = useId();
    const nameExistingSite = !site && sites.length === 1;

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
                    label={site ? `Modifica ${site.name}` : 'Aggiungi sede'}
                    variant={site ? 'outline' : 'default'}
                >
                    {site ? <Pencil /> : <Plus />}
                </IconButton>
            </DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <MapPin className="size-5" />
                        {deleting
                            ? 'Elimina sede'
                            : site
                              ? 'Modifica sede'
                              : 'Aggiungi sede'}
                    </DialogTitle>
                    <DialogDescription>
                        {deleting
                            ? 'Verifica cosa verrà rimosso prima di confermare.'
                            : site
                              ? 'Aggiorna il nome della sede.'
                              : nameExistingSite
                                ? 'Dai un nome alla sede esistente e alla nuova. Tutti i dati già presenti resteranno nella sede esistente.'
                                : `Aggiungi una sede a ${company.name}.`}
                    </DialogDescription>
                </DialogHeader>
                <div hidden={deleting}>
                    <Form
                        {...(site
                            ? update.form({
                                  company: company.id,
                                  site: site.id,
                              })
                            : store.form(company.id))}
                        onSuccess={() => setOpen(false)}
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                {nameExistingSite ? (
                                    <div className="flex flex-col gap-2">
                                        <Label htmlFor={`${id}-existing`}>
                                            Nome della sede esistente
                                        </Label>
                                        <Input
                                            id={`${id}-existing`}
                                            name="existing_site_name"
                                            defaultValue={sites[0].name ?? ''}
                                            required
                                            maxLength={255}
                                            autoFocus
                                            aria-invalid={Boolean(
                                                errors.existing_site_name,
                                            )}
                                            aria-describedby={
                                                errors.existing_site_name
                                                    ? `${id}-existing-error`
                                                    : undefined
                                            }
                                        />
                                        <InputError
                                            id={`${id}-existing-error`}
                                            message={errors.existing_site_name}
                                        />
                                    </div>
                                ) : null}
                                <div className="flex flex-col gap-2">
                                    <Label htmlFor={id}>
                                        {site
                                            ? 'Nome sede'
                                            : 'Nome della nuova sede'}
                                    </Label>
                                    <Input
                                        id={id}
                                        name="name"
                                        defaultValue={site?.name ?? ''}
                                        required
                                        maxLength={255}
                                        autoFocus={!nameExistingSite}
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
                                    {site ? (
                                        <IconButton
                                            label="Elimina sede"
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
                                            site
                                                ? 'Salva sede'
                                                : 'Aggiungi sede'
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
                {deleting && site ? (
                    <DeletionConfirmation
                        action={destroy.form({
                            company: company.id,
                            site: site.id,
                        })}
                        sites={sites}
                        onCancel={() => {
                            setDeleting(false);
                            requestAnimationFrame(() =>
                                document.getElementById(id)?.focus(),
                            );
                        }}
                        onSuccess={() => setOpen(false)}
                    >
                        <p>
                            La sede <strong>{site.name}</strong> di{' '}
                            <strong>{company.name}</strong> verrà eliminata
                            definitivamente.
                        </p>
                        <p>
                            {sites.length === 2
                                ? 'L’altra sede e l’azienda saranno conservate.'
                                : `Le altre ${sites.length - 1} sedi e l’azienda saranno conservate.`}
                        </p>
                        {sites.length === 2 ? (
                            <p>
                                Rimarrà una sola sede: lavorerai direttamente
                                nel contesto azienda.
                            </p>
                        ) : null}
                    </DeletionConfirmation>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
