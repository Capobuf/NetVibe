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
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/companies/sites/subnets';
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
                if (value) setVlanChoice(String(subnet?.vlan_id ?? 'none'));
            }}
        >
            <DialogTrigger asChild>
                {subnet ? (
                    <IconButton label={`Modifica subnet ${subnet.cidr}`}>
                        <Pencil />
                    </IconButton>
                ) : (
                    <Button>
                        <Plus data-icon="inline-start" />
                        Nuova subnet
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {subnet ? 'Modifica subnet' : 'Nuova subnet'}
                    </DialogTitle>
                    <DialogDescription>
                        {context}. È obbligatorio soltanto il CIDR.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...(subnet
                        ? update.form({ ...route, subnet: subnet.id })
                        : store.form(route))}
                    onSuccess={() => setOpen(false)}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor={`${id}-cidr`}>CIDR IPv4</Label>
                                <Input
                                    id={`${id}-cidr`}
                                    name="cidr"
                                    required
                                    autoFocus
                                    maxLength={18}
                                    defaultValue={subnet?.cidr ?? ''}
                                    placeholder="192.168.1.0/24"
                                    className="font-mono"
                                    aria-invalid={Boolean(errors.cidr)}
                                    aria-describedby={`${id}-cidr-help ${id}-cidr-error`}
                                />
                                <p
                                    id={`${id}-cidr-help`}
                                    className="text-sm text-muted-foreground"
                                >
                                    L’indirizzo viene ricondotto alla rete. Le
                                    subnet della stessa sede non possono
                                    sovrapporsi.
                                </p>
                                <InputError
                                    id={`${id}-cidr-error`}
                                    message={errors.cidr}
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
                                    defaultValue={subnet?.name ?? ''}
                                    placeholder="Es. Rete uffici"
                                    aria-invalid={Boolean(errors.name)}
                                    aria-describedby={`${id}-name-error`}
                                />
                                <InputError
                                    id={`${id}-name-error`}
                                    message={errors.name}
                                />
                            </div>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor={`${id}-description`}>
                                    Descrizione (facoltativa)
                                </Label>
                                <Input
                                    id={`${id}-description`}
                                    name="description"
                                    maxLength={2000}
                                    defaultValue={subnet?.description ?? ''}
                                    aria-invalid={Boolean(errors.description)}
                                    aria-describedby={`${id}-description-error`}
                                />
                                <InputError
                                    id={`${id}-description-error`}
                                    message={errors.description}
                                />
                            </div>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor={`${id}-vlan`}>
                                    VLAN (facoltativa)
                                </Label>
                                <Select
                                    value={vlanChoice}
                                    onValueChange={setVlanChoice}
                                >
                                    <SelectTrigger
                                        id={`${id}-vlan`}
                                        className="w-full"
                                        aria-invalid={Boolean(errors.vlan_id)}
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
                            </div>
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
                                    <div className="flex flex-col gap-2">
                                        <Label htmlFor={`${id}-tag`}>
                                            ID VLAN
                                        </Label>
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
                                            Da 1 a 4094, univoco in questa sede.
                                        </p>
                                        <InputError
                                            id={`${id}-tag-error`}
                                            message={errors['new_vlan.tag']}
                                        />
                                    </div>
                                    <div className="flex flex-col gap-2">
                                        <Label htmlFor={`${id}-vlan-name`}>
                                            Nome VLAN (facoltativo)
                                        </Label>
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
                                            message={errors['new_vlan.name']}
                                        />
                                    </div>
                                </div>
                            </fieldset>
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
                                    {subnet ? 'Salva subnet' : 'Crea subnet'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
