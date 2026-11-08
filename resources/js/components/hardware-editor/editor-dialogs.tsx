import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { labelConflict, updatePorts } from '@/lib/hardware-editor/document';
import type { Editor } from './use-editor';

export function LabelDialog({
    editor: e,
    onClose,
}: {
    editor: Editor;
    onClose: () => void;
}) {
    const port = e.selected[0];
    const [label, setLabel] = useState(port?.label ?? '');
    const candidate = updatePorts(
        e.doc,
        e.face,
        e.ports.map((p) =>
            p.id === port?.id ? { ...p, label: label.trim() } : p,
        ),
    );
    const conflict = labelConflict(candidate);
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>Etichetta / numero</DialogTitle>
                </DialogHeader>
                <form
                    className="flex flex-col gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (!conflict && e.commit(candidate)) onClose();
                    }}
                >
                    <Field data-invalid={!!conflict}>
                        <FieldLabel htmlFor="edit-port-label">
                            Etichetta
                        </FieldLabel>
                        <Input
                            id="edit-port-label"
                            autoFocus
                            value={label}
                            maxLength={40}
                            aria-invalid={!!conflict}
                            onChange={(event) => setLabel(event.target.value)}
                        />
                        {conflict && (
                            <FieldError>
                                «{conflict}» è già assegnata su questo pannello.
                            </FieldError>
                        )}
                    </Field>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Annulla
                        </Button>
                        <Button type="submit" disabled={!!conflict}>
                            Applica
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
