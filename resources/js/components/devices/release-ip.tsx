import type { ComponentProps } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { destroy } from '@/routes/companies/sites/devices/ip-addresses';

export function ReleaseIp({
    company,
    site,
    device,
    ip,
    onClose,
    onCloseAutoFocus,
}: {
    company: number;
    site: number;
    device: number;
    ip: { id: number; ip: string };
    onClose: () => void;
    onCloseAutoFocus?: ComponentProps<typeof DialogContent>['onCloseAutoFocus'];
}) {
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                aria-describedby={undefined}
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <DialogHeader>
                    <DialogTitle>Libera IP {ip.ip}</DialogTitle>
                </DialogHeader>
                <DeletionConfirmation
                    action={destroy.form({
                        company,
                        site,
                        device,
                        ipAddress: ip.id,
                    })}
                    submitLabel="Scollega e libera IP"
                    cancelLabel="Annulla"
                    onCancel={onClose}
                    onSuccess={onClose}
                >
                    <p>
                        Verrà eliminato il record di <strong>{ip.ip}</strong>{' '}
                        con hostname, descrizione, MAC, stato, associazioni ai
                        tag e al dispositivo/interfaccia. L’indirizzo torna
                        libero; se appartiene a un intervallo escluso, resta
                        escluso dai suggerimenti.
                    </p>
                </DeletionConfirmation>
            </DialogContent>
        </Dialog>
    );
}
