import { Form } from '@inertiajs/react';
import { useId, useState, type ComponentProps, type ReactNode } from 'react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
    DialogClose,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import InputError from '@/components/input-error';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import {
    store,
    update,
    destroy,
} from '@/routes/companies/sites/devices/interfaces';
import { interfaceName, type DeviceInterface } from '@/types/device';
import type { Port } from '@/lib/hardware-editor/document';

export function InterfaceForm({
    company,
    site,
    device,
    item,
    ports,
    linkedIps = [],
    open: controlledOpen,
    onOpenChange,
    onCloseAutoFocus,
    description,
    children,
}: {
    company: number;
    site: number;
    device: number;
    item?: DeviceInterface;
    ports: Port[];
    linkedIps?: string[];
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    onCloseAutoFocus?: ComponentProps<typeof DialogContent>['onCloseAutoFocus'];
    description?: string;
    children?: ReactNode;
}) {
    const [internalOpen, setInternalOpen] = useState(false);
    const open = controlledOpen ?? internalOpen;
    const [submitting, setSubmitting] = useState(false);
    const id = useId();
    function setOpen(value: boolean) {
        (onOpenChange ?? setInternalOpen)(value);
        setRemoving(false);
    }
    const [removing, setRemoving] = useState(false);
    const route = { company, site, device };
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {controlledOpen === undefined && (
                <DialogTrigger asChild>
                    <Button variant="outline" size="sm">
                        {item ? (
                            <Pencil data-icon="inline-start" />
                        ) : (
                            <Plus data-icon="inline-start" />
                        )}
                        {item ? 'Modifica interfaccia' : 'Nuova interfaccia'}
                    </Button>
                </DialogTrigger>
            )}
            <DialogContent
                className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl"
                aria-describedby={
                    description && !removing ? `${id}-context` : undefined
                }
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <DialogHeader>
                    <DialogTitle>
                        {removing
                            ? 'Elimina interfaccia'
                            : item
                              ? interfaceName(item, ports)
                              : 'Nuova interfaccia'}
                    </DialogTitle>
                    {description && !removing && (
                        <DialogDescription id={`${id}-context`}>
                            {description}
                        </DialogDescription>
                    )}
                </DialogHeader>
                {removing && item ? (
                    <DeletionConfirmation
                        action={destroy.form({ ...route, interface: item.id })}
                        onCancel={() => setRemoving(false)}
                        onSuccess={() => setOpen(false)}
                    >
                        <p>
                            L’interfaccia verrà eliminata. Gli IP{' '}
                            {linkedIps.join(', ') || 'eventualmente collegati'}{' '}
                            saranno scollegati e conservati nell’IPAM.
                        </p>
                    </DeletionConfirmation>
                ) : (
                    <>
                        <Form
                            id={`${id}-form`}
                            errorBag="interface"
                            onStart={() => setSubmitting(true)}
                            onFinish={() => setSubmitting(false)}
                            {...(item
                                ? update.form({ ...route, interface: item.id })
                                : store.form(route))}
                            onSuccess={() => setOpen(false)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        data-invalid={!!errors.name}
                                        className={
                                            !item?.connector_id
                                                ? 'sm:col-span-2'
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor={`${id}-name`}>
                                            {item?.connector_id
                                                ? 'Alias locale'
                                                : 'Nome *'}
                                        </FieldLabel>
                                        <Input
                                            id={`${id}-name`}
                                            name="name"
                                            defaultValue={item?.name ?? ''}
                                            placeholder={
                                                item?.connector_id
                                                    ? interfaceName(
                                                          {
                                                              ...item,
                                                              name: null,
                                                          },
                                                          ports,
                                                      )
                                                    : undefined
                                            }
                                            maxLength={255}
                                            required={!item?.connector_id}
                                            autoFocus
                                            disabled={processing}
                                            aria-invalid={!!errors.name}
                                        />
                                        <InputError message={errors.name} />
                                    </Field>
                                    {!item?.connector_id && (
                                        <Field>
                                            <FieldLabel htmlFor={`${id}-kind`}>
                                                Tipo
                                            </FieldLabel>
                                            <Select
                                                name="kind"
                                                defaultValue={
                                                    item?.kind ?? 'physical'
                                                }
                                                disabled={processing}
                                            >
                                                <SelectTrigger
                                                    id={`${id}-kind`}
                                                    className="w-full"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectGroup>
                                                        <SelectItem value="physical">
                                                            Fisica
                                                        </SelectItem>
                                                        <SelectItem value="logical">
                                                            Logica
                                                        </SelectItem>
                                                    </SelectGroup>
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                    )}
                                    <Field data-invalid={!!errors.mac_address}>
                                        <FieldLabel htmlFor={`${id}-mac`}>
                                            MAC interfaccia
                                        </FieldLabel>
                                        <Input
                                            id={`${id}-mac`}
                                            name="mac_address"
                                            defaultValue={
                                                item?.mac_address ?? ''
                                            }
                                            maxLength={17}
                                            disabled={processing}
                                            aria-invalid={!!errors.mac_address}
                                        />
                                        <InputError
                                            message={errors.mac_address}
                                        />
                                    </Field>
                                </FieldGroup>
                            )}
                        </Form>
                        {children}
                        <DialogFooter>
                            {item && !item.connector_id && (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    className="sm:mr-auto"
                                    onClick={() => setRemoving(true)}
                                    disabled={submitting}
                                >
                                    <Trash2 data-icon="inline-start" />
                                    Elimina
                                </Button>
                            )}
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={submitting}
                                >
                                    Annulla
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                form={`${id}-form`}
                                disabled={submitting}
                            >
                                Salva
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
