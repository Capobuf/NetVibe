import { Form } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useId, useState } from 'react';
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
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/administration/users';
import { store as companyStore } from '@/routes/companies/settings/users';
import type { Company } from '@/types/company';

export default function InviteUser({ company }: { company: Company | null }) {
    const [open, setOpen] = useState(false);
    const id = useId();
    const title = company ? 'Invita utente' : 'Invita Superadmin';

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <UserPlus data-icon="inline-start" />
                    {title}
                </Button>
            </DialogTrigger>
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {title}
                        {company ? ` · ${company.name}` : ''}
                    </DialogTitle>
                </DialogHeader>
                <Form
                    {...(company
                        ? companyStore.form(company.id)
                        : store.form())}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing, errors }) => (
                        <FieldGroup>
                            <Field data-invalid={Boolean(errors.email)}>
                                <FieldLabel htmlFor={id}>Email</FieldLabel>
                                <Input
                                    id={id}
                                    name="email"
                                    type="email"
                                    required
                                    maxLength={255}
                                    autoFocus
                                    autoComplete="off"
                                    disabled={processing}
                                    aria-invalid={Boolean(errors.email)}
                                    aria-describedby={`${id}-error`}
                                />
                                <InputError
                                    id={`${id}-error`}
                                    message={errors.email}
                                />
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
                                <Button type="submit" disabled={processing}>
                                    {processing ? <Spinner /> : null}Invita
                                </Button>
                            </DialogFooter>
                        </FieldGroup>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
