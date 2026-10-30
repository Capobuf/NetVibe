import { Form } from '@inertiajs/react';
import { useId, useState } from 'react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { IconButton } from '@/components/icon-button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import { destroy, store, update } from '@/routes/companies/sites/racks';
import type { Rack } from '@/types/rack';

export function RackForm({
    company,
    site,
    rack,
}: {
    company: number;
    site: number;
    rack?: Rack;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [removing, setRemoving] = useState(false);
    const route = { company, site, rack: rack?.id ?? 0 };
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                setRemoving(false);
            }}
        >
            <DialogTrigger asChild>
                {rack ? (
                    <IconButton label={`Modifica ${rack.name}`}>
                        <Pencil />
                    </IconButton>
                ) : (
                    <Button>
                        <Plus data-icon="inline-start" />
                        Nuovo rack
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {removing
                            ? `Elimina ${rack?.name}`
                            : rack
                              ? 'Modifica rack'
                              : 'Nuovo rack'}
                    </DialogTitle>
                </DialogHeader>
                {rack && removing ? (
                    <DeletionConfirmation
                        action={destroy.form(route)}
                        onCancel={() => setRemoving(false)}
                        onSuccess={() => setOpen(false)}
                        fields={() => (
                            <input
                                type="hidden"
                                name="revision"
                                value={rack.revision}
                            />
                        )}
                        submitLabel="Elimina rack"
                    >
                        <p>
                            Verranno eliminati «{rack.name}» e la disposizione
                            di {rack.items_count ?? rack.items?.length ?? 0}{' '}
                            elementi. I{' '}
                            {rack.devices_count ??
                                rack.items?.filter((item) => item.device_id)
                                    .length ??
                                0}{' '}
                            dispositivi resteranno nell’inventario con porte e
                            IP.
                        </p>
                    </DeletionConfirmation>
                ) : (
                    <Form
                        {...(rack
                            ? update.form(route)
                            : store.form({ company, site }))}
                        onSuccess={() => setOpen(false)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <FieldGroup>
                                {rack && (
                                    <input
                                        type="hidden"
                                        name="revision"
                                        value={rack.revision}
                                    />
                                )}
                                <Field data-invalid={!!errors.name}>
                                    <FieldLabel htmlFor={`${id}-name`}>
                                        Nome
                                    </FieldLabel>
                                    <Input
                                        id={`${id}-name`}
                                        name="name"
                                        defaultValue={rack?.name ?? ''}
                                        maxLength={255}
                                        required
                                        autoFocus
                                        aria-invalid={!!errors.name}
                                        disabled={processing}
                                    />
                                    <InputError message={errors.name} />
                                </Field>
                                <InputError
                                    message={errors.items ?? errors.revision}
                                />
                                <DialogFooter>
                                    {rack && (
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            className="sm:mr-auto"
                                            onClick={() => setRemoving(true)}
                                            disabled={processing}
                                        >
                                            <Trash2 />
                                            Elimina
                                        </Button>
                                    )}
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Annulla
                                        </Button>
                                    </DialogClose>
                                    <Button disabled={processing}>
                                        {rack ? 'Salva' : 'Crea rack'}
                                    </Button>
                                </DialogFooter>
                            </FieldGroup>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
