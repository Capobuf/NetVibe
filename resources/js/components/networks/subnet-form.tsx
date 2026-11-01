import { NewItemButton } from '@/components/new-item-button';
import { MikrotikEditMode } from '@/components/networks/mikrotik-edit-mode';
import { Form } from '@inertiajs/react';
import { Check, Pencil, Trash2 } from 'lucide-react';
import { useId, useState } from 'react';
import { GuidedDeletion } from '@/components/networks/guided-deletion';
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
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    deletionImpact,
    destroy,
    store,
    update,
} from '@/routes/companies/sites/subnets';
import type { Subnet, Vlan } from '@/types/network';

export default function SubnetForm({
    companyId,
    siteId,
    context,
    vlans,
    subnet,
}: {
    companyId: number;
    siteId: number;
    context: string;
    vlans: Vlan[];
    subnet?: Subnet;
}) {
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [vlanChoice, setVlanChoice] = useState(
        String(subnet?.vlan_id ?? 'none'),
    );
    const id = useId();
    const route = { company: companyId, site: siteId };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                if (value) setDeleting(false);
                if (value) setVlanChoice(String(subnet?.vlan_id ?? 'none'));
            }}
        >
            <DialogTrigger asChild>
                {subnet ? (
                    <IconButton label={`Modifica subnet ${subnet.cidr}`}>
                        <Pencil />
                    </IconButton>
                ) : (
                    <NewItemButton aria-label="Nuovo: crea subnet" />
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {deleting
                            ? 'Elimina subnet'
                            : subnet
                              ? 'Modifica subnet'
                              : 'Nuova subnet'}
                    </DialogTitle>
                    <DialogDescription>
                        {deleting
                            ? `${context} · ${subnet?.cidr}`
                            : `${context}. È obbligatorio soltanto il CIDR.`}
                    </DialogDescription>
                </DialogHeader>
                <div hidden={deleting}>
                    <Form
                        {...(subnet
                            ? update.form({ ...route, subnet: subnet.id })
                            : store.form(route))}
                        onSuccess={() => setOpen(false)}
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-5"
                    >
                        {({ processing, errors }) => (
                            <FieldGroup>
                                <Field data-invalid={Boolean(errors.cidr)}>
                                    <FieldLabel htmlFor={`${id}-cidr`}>
                                        CIDR IPv4
                                    </FieldLabel>
                                    <Input
                                        id={`${id}-cidr`}
                                        name="cidr"
                                        required
                                        autoFocus
                                        maxLength={18}
                                        defaultValue={subnet?.cidr ?? ''}
                                        placeholder="192.168.1.0/24"
                                        className="tabular-nums"
                                        aria-invalid={Boolean(errors.cidr)}
                                        aria-describedby={`${id}-cidr-help ${id}-cidr-error`}
                                    />
                                    <p
                                        id={`${id}-cidr-help`}
                                        className="text-sm text-muted-foreground"
                                    >
                                        L’indirizzo viene ricondotto alla rete.
                                        Le subnet della stessa sede non possono
                                        sovrapporsi.
                                    </p>
                                    <InputError
                                        id={`${id}-cidr-error`}
                                        message={errors.cidr}
                                    />
                                </Field>
                                <Field data-invalid={Boolean(errors.name)}>
                                    <FieldLabel htmlFor={`${id}-name`}>
                                        Nome (facoltativo)
                                    </FieldLabel>
                                    <Input
                                        id={`${id}-name`}
                                        name="name"
                                        maxLength={255}
                                        defaultValue={subnet?.name ?? ''}
                                        placeholder="Es. Rete uffici"
                                        aria-invalid={Boolean(errors.name)}
                                        aria-describedby={`${id}-name-error`}
                                    />
                                    <InputError
                                        id={`${id}-name-error`}
                                        message={errors.name}
                                    />
                                </Field>
                                <Field
                                    data-invalid={Boolean(errors.description)}
                                >
                                    <FieldLabel htmlFor={`${id}-description`}>
                                        Descrizione (facoltativa)
                                    </FieldLabel>
                                    <Input
                                        id={`${id}-description`}
                                        name="description"
                                        maxLength={2000}
                                        defaultValue={subnet?.description ?? ''}
                                        aria-invalid={Boolean(
                                            errors.description,
                                        )}
                                        aria-describedby={`${id}-description-error`}
                                    />
                                    <InputError
                                        id={`${id}-description-error`}
                                        message={errors.description}
                                    />
                                </Field>
                                <Field data-invalid={Boolean(errors.vlan_id)}>
                                    <FieldLabel htmlFor={`${id}-vlan`}>
                                        VLAN (facoltativa)
                                    </FieldLabel>
                                    <Select
                                        value={vlanChoice}
                                        onValueChange={setVlanChoice}
                                    >
                                        <SelectTrigger
                                            id={`${id}-vlan`}
                                            className="w-full"
                                            aria-invalid={Boolean(
                                                errors.vlan_id,
                                            )}
                                            aria-describedby={`${id}-vlan-error`}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                <SelectItem value="none">
                                                    Senza VLAN
                                                </SelectItem>
                                                {vlans.map((vlan) => (
                                                    <SelectItem
                                                        key={vlan.id}
                                                        value={String(vlan.id)}
                                                    >
                                                        VLAN {vlan.tag}
                                                        {vlan.name
                                                            ? ` · ${vlan.name}`
                                                            : ''}
                                                    </SelectItem>
                                                ))}
                                                <SelectItem value="new">
                                                    ＋ Crea una nuova VLAN
                                                </SelectItem>
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    <input
                                        type="hidden"
                                        name="vlan_id"
                                        value={
                                            vlanChoice === 'none' ||
                                            vlanChoice === 'new'
                                                ? ''
                                                : vlanChoice
                                        }
                                    />
                                    <InputError
                                        id={`${id}-vlan-error`}
                                        message={errors.vlan_id}
                                    />
                                </Field>
                                <fieldset
                                    hidden={vlanChoice !== 'new'}
                                    disabled={vlanChoice !== 'new'}
                                    className="rounded-lg border p-4"
                                >
                                    <legend className="px-1 text-sm font-medium">
                                        Nuova VLAN
                                    </legend>
                                    <div className="flex flex-col gap-4">
                                        <p className="text-sm text-muted-foreground">
                                            Verrà creata insieme alla subnet al
                                            salvataggio.
                                        </p>
                                        <Field
                                            data-invalid={Boolean(
                                                errors['new_vlan.tag'],
                                            )}
                                        >
                                            <FieldLabel htmlFor={`${id}-tag`}>
                                                ID VLAN
                                            </FieldLabel>
                                            <Input
                                                id={`${id}-tag`}
                                                name="new_vlan[tag]"
                                                type="number"
                                                min={1}
                                                max={4094}
                                                required={vlanChoice === 'new'}
                                                aria-invalid={Boolean(
                                                    errors['new_vlan.tag'],
                                                )}
                                                aria-describedby={`${id}-tag-help ${id}-tag-error`}
                                            />
                                            <p
                                                id={`${id}-tag-help`}
                                                className="text-sm text-muted-foreground"
                                            >
                                                Da 1 a 4094, univoco in questa
                                                sede.
                                            </p>
                                            <InputError
                                                id={`${id}-tag-error`}
                                                message={errors['new_vlan.tag']}
                                            />
                                        </Field>
                                        <Field
                                            data-invalid={Boolean(
                                                errors['new_vlan.name'],
                                            )}
                                        >
                                            <FieldLabel
                                                htmlFor={`${id}-vlan-name`}
                                            >
                                                Nome VLAN (facoltativo)
                                            </FieldLabel>
                                            <Input
                                                id={`${id}-vlan-name`}
                                                name="new_vlan[name]"
                                                maxLength={255}
                                                aria-invalid={Boolean(
                                                    errors['new_vlan.name'],
                                                )}
                                                aria-describedby={`${id}-vlan-name-error`}
                                            />
                                            <InputError
                                                id={`${id}-vlan-name-error`}
                                                message={
                                                    errors['new_vlan.name']
                                                }
                                            />
                                        </Field>
                                    </div>
                                </fieldset>
                                <MikrotikEditMode
                                    error={errors.mikrotik_edit_mode}
                                />
                                <DialogFooter>
                                    {subnet ? (
                                        <IconButton
                                            label="Elimina subnet"
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
                                        {subnet
                                            ? 'Salva subnet'
                                            : 'Crea subnet'}
                                    </Button>
                                </DialogFooter>
                            </FieldGroup>
                        )}
                    </Form>
                </div>
                {deleting && subnet ? (
                    <GuidedDeletion
                        kind="subnet"
                        previewUrl={deletionImpact.url({
                            ...route,
                            subnet: subnet.id,
                        })}
                        action={destroy.form({ ...route, subnet: subnet.id })}
                        onCancel={() => {
                            setDeleting(false);
                            requestAnimationFrame(() =>
                                document.getElementById(`${id}-cidr`)?.focus(),
                            );
                        }}
                        onSuccess={() => setOpen(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
