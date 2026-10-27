import { Form } from '@inertiajs/react';
import { Check, Pencil, Plus } from 'lucide-react';
import { useId, useState } from 'react';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/administration/ip-statuses';
import type { IpStatus } from '@/types/network';

export default function IpStatusForm({ status }: { status?: IpStatus }) {
    const [open, setOpen] = useState(false);
    const id = useId();

    return (
        <Dialog open={open} onOpenChange={setOpen}>
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
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {status ? 'Rinomina stato' : 'Nuovo stato IP'}
                    </DialogTitle>
                </DialogHeader>
                <Form
                    {...(status
                        ? update.form({ ipStatus: status.id })
                        : store.form())}
                    onSuccess={() => setOpen(false)}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor={id}>Nome</Label>
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
                            </div>
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
                                <Button type="submit" disabled={processing}>
                                    {processing ? (
                                        <Spinner />
                                    ) : (
                                        <Check data-icon="inline-start" />
                                    )}
                                    {status ? 'Salva stato' : 'Crea stato'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
