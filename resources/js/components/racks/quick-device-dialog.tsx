import { useId, useState, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import { SaveButton } from '@/components/save-button';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import type { DeviceModel } from '@/types/device';

export type CatalogModel = Pick<
    DeviceModel,
    'id' | 'name' | 'rack_height_u' | 'without_rack_ears'
> & {
    manufacturer: { id: number; name: string };
    model_type: { id: number; name: string } | null;
};

export type QuickDeviceCallbacks = {
    onError: (errors: Record<string, string>) => void;
    onFinish: () => void;
};

// Names a new device of a catalog model; the rack editor creates and places it.
export function QuickDeviceDialog({
    model,
    onClose,
    onCreate,
}: {
    model: CatalogModel | null;
    onClose: () => void;
    onCreate: (name: string, callbacks: QuickDeviceCallbacks) => void;
}) {
    const id = useId();
    const [name, setName] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    function submit(event: FormEvent) {
        event.preventDefault();
        if (processing) return;
        setProcessing(true);
        setErrors({});
        onCreate(name, {
            onError: setErrors,
            onFinish: () => setProcessing(false),
        });
    }
    const otherErrors = Object.entries(errors)
        .filter(([field]) => field !== 'name')
        .map(([, message]) => message)
        .join(' ');
    return (
        <Dialog
            open={!!model}
            onOpenChange={(open) => {
                if (open || processing) return;
                onClose();
                setName('');
                setErrors({});
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Nuovo dispositivo</DialogTitle>
                    <DialogDescription>
                        {model?.manufacturer.name} · {model?.name}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit}>
                    <FieldGroup>
                        <Field data-invalid={!!errors.name}>
                            <FieldLabel htmlFor={`${id}-name`}>Nome</FieldLabel>
                            <Input
                                id={`${id}-name`}
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                maxLength={255}
                                autoFocus
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
                            <SaveButton processing={processing}>
                                Crea e aggiungi
                            </SaveButton>
                        </DialogFooter>
                    </FieldGroup>
                </form>
            </DialogContent>
        </Dialog>
    );
}
