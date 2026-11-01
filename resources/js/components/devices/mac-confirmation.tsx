import { MikrotikEditMode } from '@/components/networks/mikrotik-edit-mode';
import { TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

export function MacConfirmation({
    errors,
}: {
    errors: Record<string, string>;
}) {
    return (
        <>
            <MikrotikEditMode error={errors.mikrotik_edit_mode} />
            {errors.mac_confirmation ? (
                <Alert>
                    <TriangleAlert />
                    <AlertTitle>Conferma sostituzione MAC</AlertTitle>
                    <AlertDescription className="break-words">
                        {errors.mac_confirmation}
                    </AlertDescription>
                    <input
                        type="hidden"
                        name="mac_confirmation_token"
                        value={errors.mac_confirmation_token ?? ''}
                    />
                </Alert>
            ) : null}
        </>
    );
}
