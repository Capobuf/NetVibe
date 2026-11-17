import { useId, useState, type FormEvent } from 'react';
import { CatalogCombobox } from '@/components/devices/catalog-combobox';
import { CatalogForm } from '@/components/devices/catalog-form';
import InputError from '@/components/input-error';
import type { QuickDeviceCallbacks } from '@/components/racks/quick-device-dialog';
import { SaveButton } from '@/components/save-button';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store as storeModel } from '@/routes/manufacturers/models';
import type { Manufacturer, ModelType } from '@/types/device';
import type { FloorModel } from '@/types/floor';

/** Names a new device of a catalog model, which the floor plan then places; a missing model can be added here. */
export function FloorDeviceDialog({
    open,
    initialModel,
    models,
    manufacturers,
    types,
    canCreateModel,
    onClose,
    onCreate,
}: {
    open: boolean;
    initialModel: number | null;
    models: FloorModel[];
    manufacturers: Manufacturer[];
    types: ModelType[];
    canCreateModel: boolean;
    onClose: () => void;
    onCreate: (
        modelId: number,
        name: string,
        callbacks: QuickDeviceCallbacks,
    ) => void;
}) {
    const id = useId();
    const [model, setModel] = useState(
        initialModel ? String(initialModel) : '',
    );
    const [name, setName] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [modelDraft, setModelDraft] = useState<string | null>(null);
    function submit(event: FormEvent) {
        event.preventDefault();
        if (processing || !model) return;
        setProcessing(true);
        setErrors({});
        onCreate(Number(model), name, {
            onError: setErrors,
            onFinish: () => setProcessing(false),
        });
    }
    const otherErrors = Object.entries(errors)
        .filter(([field]) => field !== 'name' && field !== 'device_model_id')
        .map(([, message]) => message)
        .join(' ');
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value && !processing) onClose();
            }}
        >
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>Nuovo dispositivo</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit}>
                    <FieldGroup>
                        <Field data-invalid={!!errors.device_model_id}>
                            <FieldLabel htmlFor={`${id}-model`}>
                                Modello
                            </FieldLabel>
                            <CatalogCombobox
                                id={`${id}-model`}
                                name="device_model_id"
                                items={models.map((item) => ({
                                    id: item.id,
                                    name: [
                                        item.manufacturer.name,
                                        item.name,
                                        item.model_type &&
                                            `(${item.model_type.name})`,
                                    ]
                                        .filter(Boolean)
                                        .join(' '),
                                }))}
                                required
                                value={model}
                                onChange={setModel}
                                placeholder={
                                    canCreateModel
                                        ? 'Cerca o aggiungi modello…'
                                        : 'Cerca modello…'
                                }
                                onCreate={
                                    canCreateModel
                                        ? (draft) => setModelDraft(draft)
                                        : undefined
                                }
                                createLabel="Nuovo modello"
                                disabled={processing}
                                error={errors.device_model_id}
                            />
                        </Field>
                        <Field data-invalid={!!errors.name}>
                            <FieldLabel htmlFor={`${id}-name`}>Nome</FieldLabel>
                            <Input
                                id={`${id}-name`}
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                maxLength={255}
                                required
                                disabled={processing}
                                aria-invalid={!!errors.name}
                            />
                            <InputError message={errors.name} />
                        </Field>
                        <InputError message={otherErrors} />
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
                            <SaveButton
                                processing={processing}
                                disabled={!model}
                            >
                                Crea e posiziona
                            </SaveButton>
                        </DialogFooter>
                    </FieldGroup>
                </form>
                {modelDraft !== null && (
                    <CatalogForm
                        title="Nuovo modello"
                        open
                        onOpenChange={(value) => {
                            if (!value) setModelDraft(null);
                        }}
                        initialName={modelDraft}
                        manufacturers={manufacturers}
                        types={types}
                        action={(producer) => storeModel.form(producer)}
                        onCreated={(created) => setModel(String(created.id))}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
