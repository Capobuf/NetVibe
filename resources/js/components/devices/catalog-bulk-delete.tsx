import { useState } from 'react';
import { Trash2 } from 'lucide-react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { RouteFormDefinition } from '@/wayfinder';

export function CatalogBulkDelete({
    items,
    action,
    onSuccess,
}: {
    items: { id: number; name: string }[];
    action: RouteFormDefinition<'post'>;
    onSuccess: () => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    <Trash2 data-icon="inline-start" />
                    Elimina selezionati
                </Button>
            </DialogTrigger>
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        Elimina {items.length} voci selezionate
                    </DialogTitle>
                </DialogHeader>
                <DeletionConfirmation
                    action={action}
                    cancelLabel="Annulla"
                    submitLabel="Elimina selezionati"
                    onCancel={() => setOpen(false)}
                    onSuccess={() => {
                        setOpen(false);
                        onSuccess();
                    }}
                    fields={() =>
                        items.map((item) => (
                            <input
                                key={item.id}
                                type="hidden"
                                name="ids[]"
                                value={item.id}
                            />
                        ))
                    }
                >
                    <p>
                        Verranno eliminate le voci selezionate. Se una voce è
                        associata a modelli o dispositivi, nessuna voce verrà
                        eliminata.
                    </p>
                    <ul className="max-h-48 list-disc overflow-y-auto pl-5">
                        {items.map((item) => (
                            <li key={item.id}>{item.name}</li>
                        ))}
                    </ul>
                </DeletionConfirmation>
            </DialogContent>
        </Dialog>
    );
}
