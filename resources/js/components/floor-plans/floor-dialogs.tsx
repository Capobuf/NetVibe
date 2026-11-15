import { Form } from '@inertiajs/react';
import { ImageUp } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import InputError from '@/components/input-error';
import { RackFloorField } from '@/components/racks/rack-floor-field';
import { SaveButton } from '@/components/save-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    destroy,
    store,
    update,
    upload,
} from '@/routes/companies/sites/floors';
import type { Floor } from '@/types/floor';

type Route = { company: number; site: number };
type DialogProps = Route & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function Footer({
    processing,
    disabled,
    label,
}: {
    processing: boolean;
    disabled?: boolean;
    label: string;
}) {
    return (
        <DialogFooter>
            <DialogClose asChild>
                <Button type="button" variant="outline" disabled={processing}>
                    Annulla
                </Button>
            </DialogClose>
            <SaveButton processing={processing} disabled={disabled}>
                {label}
            </SaveButton>
        </DialogFooter>
    );
}

/** Creates a floor, or changes the code of an existing one. */
export function FloorDialog({
    company,
    site,
    floor,
    open,
    onOpenChange,
}: DialogProps & { floor?: Floor }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {floor ? 'Modifica piano' : 'Nuovo piano'}
                    </DialogTitle>
                </DialogHeader>
                <Form
                    {...(floor
                        ? update.form({ company, site, floor: floor.id })
                        : store.form({ company, site }))}
                    onSuccess={() => onOpenChange(false)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <FieldGroup>
                            {floor && (
                                <input
                                    type="hidden"
                                    name="revision"
                                    value={floor.revision}
                                />
                            )}
                            <RackFloorField
                                defaultValue={floor?.code}
                                required
                                autoFocus
                                disabled={processing}
                                error={errors.floor}
                            />
                            <InputError message={errors.revision} />
                            <Footer
                                processing={processing}
                                label={floor ? 'Salva' : 'Crea piano'}
                            />
                        </FieldGroup>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** Uploads or replaces the floor plan image of a floor. */
export function FloorImageDialog({
    company,
    site,
    floor,
    open,
    onOpenChange,
}: DialogProps & { floor: Floor }) {
    const id = useId();
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [reshaped, setReshaped] = useState(false);
    const [positions, setPositions] = useState('');
    useEffect(() => {
        if (!file) return;
        const url = URL.createObjectURL(file);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);
    async function choose(next: File | null) {
        setFile(next);
        setPositions('');
        setReshaped(false);
        if (!next) setPreview(null);
        if (!next || !floor.image || !floor.items.length) return;
        try {
            const bitmap = await createImageBitmap(next);
            const ratio =
                bitmap.width /
                bitmap.height /
                (floor.image.width / floor.image.height);
            bitmap.close();
            setReshaped(Math.abs(ratio - 1) > 0.01);
        } catch {
            // The server validates the file and reports an unreadable image.
        }
    }
    const current = preview ?? floor.image?.url;
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {floor.image
                            ? `Sostituisci planimetria · ${floor.code}`
                            : `Carica planimetria · ${floor.code}`}
                    </DialogTitle>
                </DialogHeader>
                <Form
                    {...upload.form({ company, site, floor: floor.id })}
                    onSuccess={() => onOpenChange(false)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <FieldGroup>
                            <input
                                type="hidden"
                                name="revision"
                                value={floor.revision}
                            />
                            <Field data-invalid={!!errors.image}>
                                <FieldLabel htmlFor={id}>Planimetria</FieldLabel>
                                <div className="flex aspect-video items-center justify-center overflow-hidden rounded-md border bg-muted/40">
                                    {current ? (
                                        <img
                                            src={current}
                                            alt=""
                                            className="size-full object-contain"
                                        />
                                    ) : (
                                        <ImageUp className="size-8 text-muted-foreground" />
                                    )}
                                </div>
                                <Input
                                    id={id}
                                    type="file"
                                    name="image"
                                    accept="image/png,image/jpeg"
                                    required
                                    autoFocus
                                    disabled={processing}
                                    aria-invalid={!!errors.image}
                                    aria-describedby={`${id}-formats`}
                                    onChange={(event) =>
                                        void choose(
                                            event.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                                <p
                                    id={`${id}-formats`}
                                    className="text-xs text-muted-foreground"
                                >
                                    PNG o JPG · massimo 20 MB, 8192 × 8192 px
                                </p>
                                <InputError message={errors.image} />
                            </Field>
                            {file && (reshaped || errors.positions) && (
                                <Field data-invalid={!!errors.positions}>
                                    <Alert>
                                        <AlertDescription>
                                            La nuova planimetria ha proporzioni
                                            diverse: le {floor.items.length}{' '}
                                            posizioni mantenute risulterebbero
                                            spostate.
                                        </AlertDescription>
                                    </Alert>
                                    <input
                                        type="hidden"
                                        name="positions"
                                        value={positions}
                                    />
                                    <ToggleGroup
                                        type="single"
                                        variant="outline"
                                        value={positions}
                                        onValueChange={setPositions}
                                        disabled={processing}
                                        aria-label="Posizioni esistenti"
                                        className="w-full"
                                    >
                                        <ToggleGroupItem
                                            value="keep"
                                            className="flex-1"
                                        >
                                            Mantieni posizioni
                                        </ToggleGroupItem>
                                        <ToggleGroupItem
                                            value="clear"
                                            className="flex-1"
                                        >
                                            Rimuovi posizioni
                                        </ToggleGroupItem>
                                    </ToggleGroup>
                                    <InputError message={errors.positions} />
                                </Field>
                            )}
                            <InputError message={errors.revision} />
                            <Footer
                                processing={processing}
                                disabled={!file || (reshaped && !positions)}
                                label={floor.image ? 'Sostituisci' : 'Carica'}
                            />
                        </FieldGroup>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export function FloorDeleteDialog({
    company,
    site,
    floor,
    open,
    onOpenChange,
}: DialogProps & { floor: Floor }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>Elimina piano {floor.code}</DialogTitle>
                </DialogHeader>
                <DeletionConfirmation
                    action={destroy.form({ company, site, floor: floor.id })}
                    onCancel={() => onOpenChange(false)}
                    onSuccess={() => onOpenChange(false)}
                    submitLabel="Elimina piano"
                    fields={() => (
                        <input
                            type="hidden"
                            name="revision"
                            value={floor.revision}
                        />
                    )}
                >
                    {floor.image
                        ? `Verranno eliminati il piano, la planimetria e ${floor.items.length} posizioni.`
                        : 'Verrà eliminato il piano.'}{' '}
                    Dispositivi e rack resteranno documentati.
                </DeletionConfirmation>
            </DialogContent>
        </Dialog>
    );
}
