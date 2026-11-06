import { SaveButton } from '@/components/save-button';
import { Form } from '@inertiajs/react';
import { MapPin, Pencil, Plus, Trash2 } from 'lucide-react';
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
import {
    deletionImpact,
    destroy,
    store,
    update,
} from '@/routes/companies/sites';
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
    const actions = { deletionImpact, destroy, store, update };
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
                {site ? (
                    <IconButton label={`Modifica ${site.name}`}>
                        <Pencil />
                    </IconButton>
                ) : (
                    <Button>
                        <Plus />
                        Aggiungi sede
                    </Button>
                )}
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
                            ? actions.update.form({
                                  company: company.id,
                                  site: site.id,
                              })
                            : actions.store.form(company.id))}
                        onSuccess={() => setOpen(false)}
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <FieldGroup>
                                {nameExistingSite ? (
                                    <Field
                                        data-invalid={Boolean(
                                            errors.existing_site_name,
                                        )}
                                    >
                                        <FieldLabel htmlFor={`${id}-existing`}>
                                            Nome della sede esistente
                                        </FieldLabel>
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
                                    </Field>
                                ) : null}
                                <Field data-invalid={Boolean(errors.name)}>
                                    <FieldLabel htmlFor={id}>
                                        {site
                                            ? 'Nome sede'
                                            : 'Nome della nuova sede'}
                                    </FieldLabel>
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
                                </Field>
                                <DialogFooter className="flex-row flex-wrap items-center justify-end">
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
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Annulla
                                        </Button>
                                    </DialogClose>
                                    <SaveButton processing={processing}>
                                        {site
                                            ? 'Salva modifiche'
                                            : 'Aggiungi sede'}
                                    </SaveButton>
                                </DialogFooter>
                            </FieldGroup>
                        )}
                    </Form>
                </div>
                {deleting && site ? (
                    <GuidedDeletion
                        action={actions.destroy.form({
                            company: company.id,
                            site: site.id,
                        })}
                        kind="site"
                        previewUrl={actions.deletionImpact.url({
                            company: company.id,
                            site: site.id,
                        })}
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
