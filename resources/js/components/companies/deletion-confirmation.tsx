import { Form } from '@inertiajs/react';
import { ArrowLeft, Trash2, TriangleAlert } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { IconButton } from '@/components/icon-button';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { DialogFooter } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { Site } from '@/types/company';
import type { RouteFormDefinition } from '@/wayfinder';

export function DeletionConfirmation({
    action,
    sites,
    children,
    onCancel,
    onSuccess,
}: {
    action: RouteFormDefinition<'post'>;
    sites: Site[];
    children: ReactNode;
    onCancel: () => void;
    onSuccess: () => void;
}) {
    const id = useId();

    return (
        <Form
            {...action}
            onSuccess={onSuccess}
            options={{ preserveScroll: true }}
            className="flex flex-col gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <Alert>
                        <TriangleAlert />
                        <AlertTitle>Eliminazione definitiva</AlertTitle>
                        <AlertDescription>{children}</AlertDescription>
                    </Alert>
                    {sites.map((site) => (
                        <input
                            key={site.id}
                            type="hidden"
                            name="site_ids[]"
                            value={site.id}
                        />
                    ))}
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id={id}
                            name="confirmed"
                            value="1"
                            required
                            autoFocus
                            disabled={processing}
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
                            label="Torna alla modifica"
                            onClick={onCancel}
                            disabled={processing}
                        >
                            <ArrowLeft />
                        </IconButton>
                        <IconButton
                            label="Conferma eliminazione definitiva"
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                        >
                            {processing ? <Spinner /> : <Trash2 />}
                        </IconButton>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
