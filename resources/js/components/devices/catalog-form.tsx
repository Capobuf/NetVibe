import { Form } from '@inertiajs/react';
import { useId, useState } from 'react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { IconButton } from '@/components/icon-button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
    DialogClose,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import {
    CatalogCombobox,
    createCatalogItem,
} from '@/components/devices/catalog-combobox';
import { store as storeManufacturer } from '@/routes/manufacturers';
import { store as storeType } from '@/routes/model-types';
import type { Manufacturer, ModelType, CreatedModel } from '@/types/device';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import type { RouteFormDefinition } from '@/wayfinder';

export function CatalogForm({
    title,
    item,
    action,
    deletion,
    manufacturers,
    manufacturerId,
    types,
    deletionMessage,
    open: controlledOpen,
    onOpenChange,
    initialName = '',
    onCreated,
}: {
    title: string;
    item?: { id: number; name: string; model_type_id?: number | null };
    types?: ModelType[];
    deletionMessage?: string;
    action:
        | RouteFormDefinition<'post'>
        | ((manufacturer: number) => RouteFormDefinition<'post'>);
    manufacturers?: Manufacturer[];
    manufacturerId?: number | null;
    deletion?: RouteFormDefinition<'post'>;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    initialName?: string;
    onCreated?: (model: CreatedModel) => void;
}) {
    const id = useId();
    const [manufacturer, setManufacturer] = useState(
        String(manufacturerId ?? ''),
    );
    const [type, setType] = useState(String(item?.model_type_id ?? ''));
    const [creating, setCreating] = useState(false);
    const formAction =
        typeof action === 'function'
            ? manufacturer
                ? action(Number(manufacturer))
                : undefined
            : action;
    const [internalOpen, setInternalOpen] = useState(false);
    const open = controlledOpen ?? internalOpen;
    const [removing, setRemoving] = useState(false);
    function setOpen(value: boolean) {
        (onOpenChange ?? setInternalOpen)(value);
        setRemoving(false);
        if (value) {
            setManufacturer(String(manufacturerId ?? ''));
            setType(String(item?.model_type_id ?? ''));
        }
    }
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {controlledOpen === undefined && (
                <DialogTrigger asChild>
                    {item ? (
                        <IconButton label={`Modifica ${item.name}`}>
                            <Pencil />
                        </IconButton>
                    ) : (
                        <Button>
                            <Plus data-icon="inline-start" />
                            {title}
                        </Button>
                    )}
                </DialogTrigger>
            )}
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {removing ? `Elimina ${item?.name}` : title}
                    </DialogTitle>
                </DialogHeader>
                {removing && deletion ? (
                    <DeletionConfirmation
                        action={deletion}
                        onCancel={() => setRemoving(false)}
                        onSuccess={() => setOpen(false)}
                    >
                        <p>
                            {deletionMessage ??
                                `Verrà eliminato «${item?.name}». La rimozione è bloccata se ci sono modelli o dispositivi associati.`}
                        </p>
                    </DeletionConfirmation>
                ) : (
                    <Form
                        {...formAction}
                        onBefore={() => !!formAction && !creating}
                        errorBag={onCreated ? 'newModel' : undefined}
                        transform={(data) =>
                            onCreated ? { ...data, inline: true } : data
                        }
                        onFlash={(flash) => {
                            if (onCreated && flash.createdModel)
                                onCreated(flash.createdModel as CreatedModel);
                        }}
                        onSuccess={() => setOpen(false)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <FieldGroup>
                                {manufacturers && (
                                    <Field
                                        data-invalid={!!errors.manufacturer_id}
                                    >
                                        <FieldLabel
                                            htmlFor={`${id}-manufacturer`}
                                        >
                                            Produttore
                                        </FieldLabel>
                                        <CatalogCombobox
                                            id={`${id}-manufacturer`}
                                            name="manufacturer_id"
                                            placeholder="Cerca o aggiungi produttore…"
                                            items={manufacturers}
                                            value={manufacturer}
                                            onChange={setManufacturer}
                                            required
                                            onCreate={(name) =>
                                                createCatalogItem(
                                                    storeManufacturer.url(),
                                                    name,
                                                )
                                            }
                                            onCreatingChange={setCreating}
                                            disabled={processing || creating}
                                            error={errors.manufacturer_id}
                                        />
                                    </Field>
                                )}
                                <Field data-invalid={!!errors.name}>
                                    <FieldLabel htmlFor={`${id}-name`}>
                                        {types ? 'Modello' : 'Nome'}
                                    </FieldLabel>
                                    <Input
                                        id={`${id}-name`}
                                        name="name"
                                        defaultValue={item?.name ?? initialName}
                                        maxLength={255}
                                        autoFocus
                                        required
                                        disabled={processing || creating}
                                        aria-invalid={!!errors.name}
                                    />
                                    <InputError message={errors.name} />
                                </Field>
                                {types && (
                                    <Field
                                        data-invalid={!!errors.model_type_id}
                                    >
                                        <FieldLabel htmlFor={`${id}-type`}>
                                            Tipo
                                        </FieldLabel>
                                        <CatalogCombobox
                                            id={`${id}-type`}
                                            name="model_type_id"
                                            placeholder="Cerca o aggiungi tipo…"
                                            items={types}
                                            value={type}
                                            onChange={setType}
                                            onCreate={(name) =>
                                                createCatalogItem(
                                                    storeType.url(),
                                                    name,
                                                )
                                            }
                                            onCreatingChange={setCreating}
                                            disabled={processing || creating}
                                            error={errors.model_type_id}
                                        />
                                    </Field>
                                )}
                                <DialogFooter>
                                    {deletion && (
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            className="sm:mr-auto"
                                            onClick={() => setRemoving(true)}
                                            disabled={processing || creating}
                                        >
                                            <Trash2 data-icon="inline-start" />
                                            Elimina
                                        </Button>
                                    )}
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={processing || creating}
                                        >
                                            Annulla
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        disabled={
                                            processing ||
                                            creating ||
                                            !formAction
                                        }
                                    >
                                        Salva
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
