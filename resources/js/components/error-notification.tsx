import { useEffect, useId, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export function ErrorNotification({
    error,
}: {
    error: { message: string } | null;
}) {
    const [details, setDetails] = useState<string | null>(null);
    const id = useId();
    useEffect(() => {
        if (!error) return;
        const message = error.message;
        const long = message.length > 180;
        const notification = toast.error(
            long ? `${message.slice(0, 180).trimEnd()}…` : message,
            {
                duration: long ? Infinity : 8000,
                closeButton: true,
                action: long
                    ? {
                          label: 'Mostra altro',
                          onClick: () => setDetails(message),
                      }
                    : undefined,
            },
        );
        return () => {
            toast.dismiss(notification);
        };
    }, [error]);
    return (
        <Dialog
            open={details !== null}
            onOpenChange={(open) => {
                if (!open) setDetails(null);
            }}
        >
            <DialogContent className="sm:max-w-2xl" aria-describedby={id}>
                <DialogHeader>
                    <DialogTitle>Dettagli dell’errore</DialogTitle>
                </DialogHeader>
                <div
                    id={id}
                    className="max-h-[60dvh] overflow-y-auto text-sm break-words whitespace-pre-wrap"
                >
                    {details}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button>Chiudi</Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
