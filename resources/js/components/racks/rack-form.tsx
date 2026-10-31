import { Form } from '@inertiajs/react';
import { useId, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
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
import { destroy, store } from '@/routes/companies/sites/racks';
import type { Rack } from '@/types/rack';

export function RackForm({ company, site }: { company: number; site: number }) {
    const id = useId();
    const [open, setOpen] = useState(false);
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    Nuovo rack
                </Button>
            </DialogTrigger>
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>Nuovo rack</DialogTitle>
                </DialogHeader>
                <Form
                    {...store.form({ company, site })}
                    onSuccess={() => setOpen(false)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <FieldGroup>
                            <Field data-invalid={!!errors.name}>
                                <FieldLabel htmlFor={id}>Nome</FieldLabel>
                                <Input
                                    id={id}
                                    name="name"
                                    maxLength={255}
                                    required
                                    autoFocus
                                    aria-invalid={!!errors.name}
                                    disabled={processing}
                                />
                                <InputError message={errors.name} />
                            </Field>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Annulla
                                    </Button>
                                </DialogClose>
                                <Button disabled={processing}>Crea rack</Button>
                            </DialogFooter>
                        </FieldGroup>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export function RackDelete({
    company,
    site,
    rack,
}: {
    company: number;
    site: number;
    rack: Rack;
}) {
    const [open, setOpen] = useState(false);
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <IconButton label={`Elimina ${rack.name}`}>
                    <Trash2 />
                </IconButton>
            </DialogTrigger>
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>Elimina {rack.name}</DialogTitle>
                </DialogHeader>
                <DeletionConfirmation
                    action={destroy.form({ company, site, rack: rack.id })}
                    onCancel={() => setOpen(false)}
                    onSuccess={() => setOpen(false)}
                    submitLabel="Elimina rack"
                    fields={() => (
                        <input
                            type="hidden"
                            name="revision"
                            value={rack.revision}
                        />
                    )}
                >
                    <p>
                        Verranno eliminati «{rack.name}» e la disposizione di{' '}
                        {rack.items_count ?? rack.items?.length ?? 0} elementi.
                        Dispositivi, porte, IP e cavi resteranno documentati.
                    </p>
                </DeletionConfirmation>
            </DialogContent>
        </Dialog>
    );
}
