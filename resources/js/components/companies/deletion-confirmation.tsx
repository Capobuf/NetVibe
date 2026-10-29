import { Form } from '@inertiajs/react';
import { ArrowLeft, Trash2, TriangleAlert } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { IconButton } from '@/components/icon-button';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { DialogFooter } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { RouteFormDefinition } from '@/wayfinder';

export function DeletionConfirmation({
    action,
    children,
    details,
    confirmationKey,
    fields,
    submitLabel,
    cancelLabel = 'Torna alla modifica',
    disabled = false,
    onError,
    onCancel,
    onSuccess,
}: {
    action: RouteFormDefinition<'post'>;
    children: ReactNode;
    details?: ReactNode;
    confirmationKey?: string;
    fields?: (processing: boolean) => ReactNode;
    submitLabel?: string;
    cancelLabel?: string;
    disabled?: boolean;
    onError?: () => void;
    onCancel: () => void;
    onSuccess: () => void;
}) {
    const id = useId();

    return (
        <Form
            {...action}
            onSuccess={onSuccess}
            onError={onError}
            options={{ preserveScroll: true }}
            className="flex flex-col gap-5"
        >
            {({ processing, errors }) => (
                <>
                    {fields?.(processing)}
                    {details}
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertTitle>Eliminazione definitiva</AlertTitle>
                        <AlertDescription>{children}</AlertDescription>
                    </Alert>
                    <div className="flex items-start gap-3">
                        <Checkbox
                            key={confirmationKey}
                            id={id}
                            name="confirmed"
                            value="1"
                            required
                            autoFocus={!details}
                            disabled={processing || disabled}
                            aria-invalid={Boolean(errors.confirmed)}
                            aria-describedby={
                                errors.confirmed ? `${id}-error` : undefined
                            }
                        />
                        <Label htmlFor={id} className="leading-relaxed">
                            Ho letto l’impatto e confermo l’eliminazione
                            definitiva.
                        </Label>
                    </div>
                    <InputError
                        id={`${id}-error`}
                        message={Object.values(errors).join(' ')}
                    />
                    <DialogFooter className="flex-row items-center justify-end">
                        <IconButton
                            label={cancelLabel}
                            onClick={onCancel}
                            disabled={processing}
                        >
                            <ArrowLeft />
                        </IconButton>
                        {submitLabel ? (
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing || disabled}
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <Trash2 data-icon="inline-start" />
                                )}
                                {submitLabel}
                            </Button>
                        ) : (
                            <IconButton
                                label="Conferma eliminazione definitiva"
                                type="submit"
                                variant="destructive"
                                disabled={processing || disabled}
                            >
                                {processing ? <Spinner /> : <Trash2 />}
                            </IconButton>
                        )}
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
