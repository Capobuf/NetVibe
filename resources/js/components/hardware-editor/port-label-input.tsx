import { useRef, useState, type CSSProperties } from 'react';
import { Input } from '@/components/ui/input';
import { labelConflict, updatePorts } from '@/lib/hardware-editor/document';
import type { Editor } from './use-editor';

export const portLabelInputId = 'port-label-input';

// Edits the selected port's label in place, over the canvas.
export function PortLabelInput({
    editor: e,
    typed,
    autoFocus,
    style,
    onClose,
}: {
    editor: Editor;
    typed?: string;
    autoFocus: boolean;
    style: CSSProperties;
    onClose: () => void;
}) {
    const port = e.selected[0];
    const [label, setLabel] = useState(typed ?? port?.label ?? '');
    const done = useRef(false);
    const candidate = updatePorts(
        e.doc,
        e.face,
        e.ports.map((p) =>
            p.id === port?.id ? { ...p, label: label.trim() } : p,
        ),
    );
    const conflict = labelConflict(candidate);
    function finish(apply: boolean) {
        if (done.current) return;
        if (apply && label.trim() !== port?.label && !e.commit(candidate))
            return;
        done.current = true;
        onClose();
    }
    return (
        <div
            className="absolute z-10 -translate-x-1/2 -translate-y-1/2"
            style={style}
        >
            <Input
                id={portLabelInputId}
                aria-label="Etichetta / numero"
                aria-invalid={!!conflict}
                aria-describedby={
                    conflict ? `${portLabelInputId}-error` : undefined
                }
                autoFocus={autoFocus}
                value={label}
                maxLength={40}
                className="h-7 w-full bg-background px-1 text-center font-mono text-xs"
                onChange={(event) => setLabel(event.target.value)}
                onFocus={(event) => {
                    // Keep typing after the first character instead of overwriting it.
                    if (typed) event.currentTarget.setSelectionRange(1, 1);
                    else event.currentTarget.select();
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' && !conflict) finish(true);
                    else if (event.key === 'Escape') finish(false);
                }}
                onBlur={() => finish(!conflict)}
            />
            {conflict && (
                <p
                    id={`${portLabelInputId}-error`}
                    role="alert"
                    className="absolute top-full left-1/2 mt-1 -translate-x-1/2 rounded bg-background px-1.5 py-0.5 text-xs whitespace-nowrap text-destructive shadow-sm"
                >
                    «{conflict}» è già assegnata su questo pannello.
                </p>
            )}
        </div>
    );
}
