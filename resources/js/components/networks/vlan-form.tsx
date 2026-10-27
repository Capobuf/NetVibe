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
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/companies/sites/vlans';
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
    const id = useId();
    const route = { company: companyId, site: siteId };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
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
                        {vlan ? `Modifica VLAN ${vlan.tag}` : 'Nuova VLAN'}
                    </DialogTitle>
                    <DialogDescription>
                        {context}. La VLAN può restare senza subnet.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...(vlan
                        ? update.form({ ...route, vlan: vlan.id })
                        : store.form(route))}
                    onSuccess={() => setOpen(false)}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor={`${id}-tag`}>ID VLAN</Label>
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
                            </div>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor={`${id}-name`}>
                                    Nome (facoltativo)
                                </Label>
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
                                    {vlan ? 'Salva VLAN' : 'Crea VLAN'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
