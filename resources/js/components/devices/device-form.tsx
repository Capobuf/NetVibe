import { NewItemButton } from '@/components/new-item-button';
import { Form, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Pencil } from 'lucide-react';
import { Button } from '@/components/ui/button';
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
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/input-error';
import { store, update } from '@/routes/companies/sites/devices';
import { store as storeModel } from '@/routes/manufacturers/models';
import { CatalogCombobox } from '@/components/devices/catalog-combobox';
import { CatalogForm } from '@/components/devices/catalog-form';
import type { Manufacturer, Device, ModelType } from '@/types/device';

export function DeviceForm({
    company,
    site,
    manufacturers,
    device,
    linked = false,
}: {
    company: number;
    site: number;
    manufacturers: Manufacturer[];
    device?: Device;
    linked?: boolean;
}) {
    const { auth, types } = usePage<{ types: ModelType[] }>().props;
    const [open, setOpen] = useState(false);
    const [modelDraft, setModelDraft] = useState<{ name: string } | null>(null);
    const [model, setModel] = useState(String(device?.device_model_id ?? ''));
    const models = manufacturers.flatMap((manufacturer) =>
        (manufacturer.models ?? []).map((model) => ({
            ...model,
            name: `${manufacturer.name} · ${model.name}`,
        })),
    );
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                if (value) {
                    setModel(String(device?.device_model_id ?? ''));
                }
            }}
        >
            <DialogTrigger asChild>
                {device ? (
                    <Button variant="outline">
                        <Pencil data-icon="inline-start" />
                        Modifica
                    </Button>
                ) : (
                    <NewItemButton aria-label="Nuovo: crea dispositivo" />
                )}
            </DialogTrigger>
            <DialogContent
                className="max-h-[90dvh] overflow-y-auto sm:max-w-xl"
                aria-describedby={undefined}
            >
                <DialogHeader>
                    <DialogTitle>
                        {device
                            ? `Modifica ${device.name}`
                            : 'Nuovo dispositivo'}
                    </DialogTitle>
                </DialogHeader>
                <Form
                    {...(device
                        ? update.form({ company, site, device: device.id })
                        : store.form({ company, site }))}
                    onBefore={() => !modelDraft}
                    onSuccess={() => setOpen(false)}
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <FieldGroup>
                            <Field data-invalid={!!errors.name}>
                                <FieldLabel htmlFor="device-name">
                                    Nome *
                                </FieldLabel>
                                <Input
                                    id="device-name"
                                    name="name"
                                    defaultValue={device?.name}
                                    maxLength={255}
                                    autoFocus
                                    required
                                    disabled={processing}
                                    readOnly={linked}
                                    aria-invalid={!!errors.name}
                                />
                                <InputError message={errors.name} />
                            </Field>
                            <Field data-invalid={!!errors.device_model_id}>
                                <FieldLabel htmlFor="device-model">
                                    Modello *
                                </FieldLabel>
                                <CatalogCombobox
                                    id="device-model"
                                    name="device_model_id"
                                    items={models}
                                    required
                                    value={model}
                                    onChange={setModel}
                                    placeholder={
                                        auth.user.is_superadmin
                                            ? 'Cerca o aggiungi modello…'
                                            : 'Cerca modello…'
                                    }
                                    onCreate={
                                        auth.user.is_superadmin
                                            ? (name) => {
                                                  setModelDraft({ name });
                                              }
                                            : undefined
                                    }
                                    disabled={processing || linked}
                                    error={errors.device_model_id}
                                />
                                {linked ? (
                                    <input
                                        type="hidden"
                                        name="device_model_id"
                                        value={model}
                                    />
                                ) : null}
                            </Field>
                            <Field data-invalid={!!errors.serial_number}>
                                <FieldLabel htmlFor="device-serial">
                                    Seriale
                                </FieldLabel>
                                <Input
                                    id="device-serial"
                                    name="serial_number"
                                    defaultValue={device?.serial_number ?? ''}
                                    maxLength={255}
                                    disabled={processing}
                                    readOnly={linked}
                                    aria-invalid={!!errors.serial_number}
                                    aria-describedby="device-serial-error"
                                />
                                <InputError
                                    id="device-serial-error"
                                    message={errors.serial_number}
                                />
                            </Field>
                            <Field data-invalid={!!errors.asset_tag}>
                                <FieldLabel htmlFor="device-asset-tag">
                                    Asset tag
                                </FieldLabel>
                                <Input
                                    id="device-asset-tag"
                                    name="asset_tag"
                                    defaultValue={device?.asset_tag ?? ''}
                                    maxLength={255}
                                    disabled={processing}
                                    readOnly={linked}
                                    aria-invalid={!!errors.asset_tag}
                                />
                                <InputError message={errors.asset_tag} />
                            </Field>
                            <Field data-invalid={!!errors.notes}>
                                <FieldLabel htmlFor="device-notes">
                                    Note tecniche
                                </FieldLabel>
                                <Textarea
                                    id="device-notes"
                                    name="notes"
                                    defaultValue={device?.notes ?? ''}
                                    maxLength={10000}
                                    disabled={processing}
                                    aria-invalid={!!errors.notes}
                                />
                                <InputError message={errors.notes} />
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
                                <Button disabled={processing || !!modelDraft}>
                                    Salva
                                </Button>
                            </DialogFooter>
                        </FieldGroup>
                    )}
                </Form>
                {modelDraft && (
                    <CatalogForm
                        title="Nuovo modello"
                        open
                        onOpenChange={(value) => {
                            if (!value) setModelDraft(null);
                        }}
                        initialName={modelDraft.name}
                        manufacturers={manufacturers}
                        manufacturerId={
                            models.find((item) => String(item.id) === model)
                                ?.manufacturer_id
                        }
                        types={types ?? []}
                        action={(producer) => storeModel.form(producer)}
                        onCreated={(created) => {
                            setModel(String(created.id));
                        }}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
