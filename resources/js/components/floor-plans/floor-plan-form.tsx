import { Form } from '@inertiajs/react';
import { ImageUp } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
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
import { store, update } from '@/routes/companies/sites/floor-plans';
import type { FloorPlan } from '@/types/floor-plan';

/** Creates a floor plan, or edits name, floor and image of an existing one. */
export function FloorPlanForm({
    company,
    site,
    plan,
    open,
    onOpenChange,
}: {
    company: number;
    site: number;
    plan?: FloorPlan;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
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
        if (!next || !plan?.items.length) return;
        try {
            const bitmap = await createImageBitmap(next);
            const ratio =
                bitmap.width /
                bitmap.height /
                (plan.image_width / plan.image_height);
            bitmap.close();
            setReshaped(Math.abs(ratio - 1) > 0.01);
        } catch {
            // The server validates the file and reports an unreadable image.
        }
    }
    const action = plan
        ? update.form({ company, site, floorPlan: plan.id })
        : store.form({ company, site });
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                if (!next) void choose(null);
            }}
        >
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {plan ? 'Modifica planimetria' : 'Nuova planimetria'}
                    </DialogTitle>
                </DialogHeader>
                <Form
                    {...action}
                    onSuccess={() => {
                        onOpenChange(false);
                        void choose(null);
                    }}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <FieldGroup>
                            {plan && (
                                <input
                                    type="hidden"
                                    name="revision"
                                    value={plan.revision}
                                />
                            )}
                            <Field data-invalid={!!errors.name}>
                                <FieldLabel htmlFor={`${id}-name`}>
                                    Nome
                                </FieldLabel>
                                <Input
                                    id={`${id}-name`}
                                    name="name"
                                    defaultValue={plan?.name}
                                    maxLength={255}
                                    required
                                    autoFocus
                                    disabled={processing}
                                    aria-invalid={!!errors.name}
                                />
                                <InputError message={errors.name} />
                            </Field>
                            <RackFloorField
                                disabled={processing}
                                error={errors.floor}
                                defaultValue={plan?.floor}
                            />
                            <Field data-invalid={!!errors.image}>
                                <FieldLabel htmlFor={`${id}-image`}>
                                    {plan ? 'Sostituisci immagine' : 'Immagine'}
                                </FieldLabel>
                                <div className="flex items-center gap-4">
                                    <div className="flex h-20 w-32 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted/40">
                                        {(preview ?? plan?.image_url) ? (
                                            <img
                                                src={preview ?? plan?.image_url}
                                                alt=""
                                                className="size-full object-contain"
                                            />
                                        ) : (
                                            <ImageUp className="size-6 text-muted-foreground" />
                                        )}
                                    </div>
                                    <div className="flex min-w-0 flex-1 flex-col gap-2">
                                        <Input
                                            id={`${id}-image`}
                                            type="file"
                                            name="image"
                                            accept="image/png,image/jpeg"
                                            required={!plan}
                                            disabled={processing}
                                            aria-invalid={!!errors.image}
                                            aria-describedby={`${id}-formats`}
                                            onChange={(event) =>
                                                void choose(
                                                    event.target.files?.[0] ??
                                                        null,
                                                )
                                            }
                                        />
                                        <p
                                            id={`${id}-formats`}
                                            className="text-xs text-muted-foreground"
                                        >
                                            PNG o JPG · massimo 20 MB, 8192 ×
                                            8192 px
                                        </p>
                                    </div>
                                </div>
                                <InputError message={errors.image} />
                            </Field>
                            {plan && file && (reshaped || errors.positions) && (
                                <Field data-invalid={!!errors.positions}>
                                    <Alert>
                                        <AlertDescription>
                                            La nuova immagine ha proporzioni
                                            diverse: le{' '}
                                            {plan.items.length} posizioni
                                            mantenute risulterebbero spostate.
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
                                    disabled={
                                        !!plan && !!file && reshaped && !positions
                                    }
                                >
                                    {plan ? 'Salva' : 'Crea planimetria'}
                                </SaveButton>
                            </DialogFooter>
                        </FieldGroup>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
