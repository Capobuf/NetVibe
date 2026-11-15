import { SaveButton } from '@/components/save-button';
import { NewItemButton } from '@/components/new-item-button';
import { Form } from '@inertiajs/react';
import { useId, useState } from 'react';
import {
    Eye,
    EyeOff,
    Monitor,
    Network,
    Pencil,
    Sparkles,
    Tag,
    Trash2,
    type LucideIcon,
} from 'lucide-react';
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
import {
    Field,
    FieldGroup,
    FieldLabel,
    FieldSeparator,
} from '@/components/ui/field';
import {
    CatalogCombobox,
    createCatalogItem,
} from '@/components/devices/catalog-combobox';
import { store as storeManufacturer } from '@/routes/manufacturers';
import { store as storeType } from '@/routes/model-types';
import type { Manufacturer, ModelType, CreatedModel } from '@/types/device';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/input-error';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import { GuidedDeletion } from '@/components/networks/guided-deletion';
import type { RouteFormDefinition } from '@/wayfinder';

export function CatalogForm({
    title,
    kind,
    item,
    action,
    deletion,
    manufacturers,
    manufacturerId,
    types,
    deletionMessage,
    deletionPreviewUrl,
    open: controlledOpen,
    onOpenChange,
    initialName = '',
    onCreated,
}: {
    title: string;
    kind?: 'model' | 'manufacturer' | 'type';
    item?: {
        id: number;
        name: string;
        model_type_id?: number | null;
        rack_height_u?: number | null;
        without_rack_ears?: boolean;
        is_infrastructure?: boolean;
        show_in_topology?: boolean | null;
    };
    types?: ModelType[];
    deletionMessage?: string;
    deletionPreviewUrl?: string;
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
    const [classification, setClassification] = useState(
        item?.is_infrastructure ? 'infrastructure' : 'other',
    );
    const [topology, setTopology] = useState(topologyChoice(item));
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
            setClassification(
                item?.is_infrastructure ? 'infrastructure' : 'other',
            );
            setTopology(topologyChoice(item));
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
                        <NewItemButton aria-label={title} />
                    )}
                </DialogTrigger>
            )}
            <DialogContent
                aria-describedby={undefined}
                // Editing opens with the caret after the name instead of the whole name selected.
                onOpenAutoFocus={(event) => {
                    const name = document.getElementById(`${id}-name`);
                    if (!item || !(name instanceof HTMLInputElement)) return;
                    event.preventDefault();
                    name.focus();
                    name.setSelectionRange(name.value.length, name.value.length);
                }}
            >
                <DialogHeader>
                    <DialogTitle>
                        {removing ? `Elimina ${item?.name}` : title}
                    </DialogTitle>
                </DialogHeader>
                {removing && deletion ? (
                    deletionPreviewUrl ? (
                        <GuidedDeletion
                            kind="model"
                            previewUrl={deletionPreviewUrl}
                            action={deletion}
                            onCancel={() => setRemoving(false)}
                            onSuccess={() => setOpen(false)}
                        />
                    ) : (
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
                    )
                ) : (
                    <Form
                        {...formAction}
                        onBefore={() => !!formAction && !creating}
                        errorBag={onCreated ? 'newModel' : undefined}
                        transform={(data) => ({
                            ...data,
                            ...(kind === 'type' && {
                                is_infrastructure:
                                    classification === 'infrastructure',
                            }),
                            ...(types && {
                                without_rack_ears:
                                    data.without_rack_ears === '1',
                            }),
                            ...((types || kind === 'type') && {
                                show_in_topology:
                                    topology === 'inherit'
                                        ? null
                                        : topology === 'show',
                            }),
                            ...(onCreated && { inline: true }),
                        })}
                        onFlash={(flash) => {
                            if (onCreated && flash.createdModel)
                                onCreated(flash.createdModel as CreatedModel);
                        }}
                        onSuccess={() => setOpen(false)}
                        options={{ preserveScroll: true, preserveState: true }}
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
                                {kind === 'type' && (
                                    <>
                                        <FieldSeparator />
                                        <SettingSelect
                                            id={`${id}-classification`}
                                            label="Classificazione"
                                            hint="Vista dell’elenco Dispositivi in cui compaiono."
                                            value={classification}
                                            onChange={setClassification}
                                            choices={{
                                                infrastructure: {
                                                    label: 'Infrastruttura',
                                                    icon: Network,
                                                },
                                                other: {
                                                    label: 'Altri dispositivi',
                                                    icon: Monitor,
                                                },
                                            }}
                                            disabled={processing}
                                            error={errors.is_infrastructure}
                                        />
                                    </>
                                )}
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
                                {types && (
                                    <Field
                                        data-invalid={!!errors.rack_height_u}
                                    >
                                        <FieldLabel
                                            htmlFor={`${id}-rack-height`}
                                        >
                                            Altezza (U, facoltativa)
                                        </FieldLabel>
                                        <Input
                                            id={`${id}-rack-height`}
                                            name="rack_height_u"
                                            type="number"
                                            min={1}
                                            max={100}
                                            step={1}
                                            defaultValue={
                                                item?.rack_height_u ?? ''
                                            }
                                            placeholder="Non specificata"
                                            disabled={processing || creating}
                                            aria-invalid={
                                                !!errors.rack_height_u
                                            }
                                        />
                                        <InputError
                                            message={errors.rack_height_u}
                                        />
                                    </Field>
                                )}
                                {types && (
                                    <Field
                                        data-invalid={
                                            !!errors.without_rack_ears
                                        }
                                    >
                                        <Field orientation="horizontal">
                                            <Checkbox
                                                id={`${id}-without-rack-ears`}
                                                name="without_rack_ears"
                                                value="1"
                                                defaultChecked={
                                                    item?.without_rack_ears ??
                                                    false
                                                }
                                                disabled={
                                                    processing || creating
                                                }
                                                aria-invalid={
                                                    !!errors.without_rack_ears
                                                }
                                            />
                                            <FieldLabel
                                                htmlFor={`${id}-without-rack-ears`}
                                            >
                                                Senza staffe rack
                                            </FieldLabel>
                                        </Field>
                                        <InputError
                                            message={errors.without_rack_ears}
                                        />
                                    </Field>
                                )}
                                {(types || kind === 'type') && (
                                    <SettingSelect
                                        id={`${id}-topology`}
                                        label="Topologia di rete"
                                        hint={
                                            kind === 'type'
                                                ? 'Automatica: solo i modelli con porte di rete.'
                                                : 'Usa tipo: segue l’impostazione del tipo.'
                                        }
                                        value={topology}
                                        onChange={setTopology}
                                        choices={{
                                            inherit:
                                                kind === 'type'
                                                    ? {
                                                          label: 'Automatica',
                                                          icon: Sparkles,
                                                      }
                                                    : {
                                                          label: 'Usa tipo',
                                                          icon: Tag,
                                                      },
                                            show: { label: 'Mostra', icon: Eye },
                                            hide: {
                                                label: 'Nascondi',
                                                icon: EyeOff,
                                            },
                                        }}
                                        disabled={processing || creating}
                                        error={errors.show_in_topology}
                                    />
                                )}
                                <DialogFooter>
                                    {deletion && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="text-destructive-foreground hover:bg-destructive/10 hover:text-destructive-foreground sm:mr-auto"
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
                                    <SaveButton
                                        processing={processing}
                                        disabled={creating || !formAction}
                                    />
                                </DialogFooter>
                            </FieldGroup>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function topologyChoice(item?: { show_in_topology?: boolean | null }) {
    return item?.show_in_topology == null
        ? 'inherit'
        : item.show_in_topology
          ? 'show'
          : 'hide';
}

function SettingSelect({
    id,
    label,
    hint,
    value,
    onChange,
    choices,
    disabled,
    error,
}: {
    id: string;
    label: string;
    hint: string;
    value: string;
    onChange: (value: string) => void;
    choices: Record<string, { label: string; icon: LucideIcon }>;
    disabled: boolean;
    error?: string;
}) {
    return (
        <div className="flex flex-col gap-2">
            <Field orientation="horizontal" data-invalid={!!error}>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <FieldLabel
                            htmlFor={id}
                            className="flex-none!"
                        >
                            {label}
                        </FieldLabel>
                    </TooltipTrigger>
                    <TooltipContent>{hint}</TooltipContent>
                </Tooltip>
                <Select value={value} onValueChange={onChange}>
                    <SelectTrigger
                        id={id}
                        className="ml-auto w-48"
                        disabled={disabled}
                        aria-invalid={!!error}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {Object.entries(choices).map(
                                ([key, { label: text, icon: Icon }]) => (
                                    <SelectItem key={key} value={key}>
                                        <Icon />
                                        {text}
                                    </SelectItem>
                                ),
                            )}
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </Field>
            <InputError message={error} />
        </div>
    );
}
