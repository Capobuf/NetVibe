import { useEffect, useId, useRef, useState } from 'react';
import { Search, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export function TableSearch({
    value,
    onChange,
    total,
    label,
}: {
    value: string;
    onChange: (value: string) => void;
    total: number;
    label: string;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const container = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLInputElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const compact = total < 25;
    const expanded = !compact || open || value !== '';

    useEffect(() => {
        if (!compact || !open || value !== '') return;

        function handleOutsidePointerDown(event: PointerEvent) {
            if (
                event.target instanceof Node &&
                !container.current?.contains(event.target)
            ) {
                setOpen(false);
            }
        }

        document.addEventListener('pointerdown', handleOutsidePointerDown);
        return () =>
            document.removeEventListener(
                'pointerdown',
                handleOutsidePointerDown,
            );
    }, [compact, open, value]);

    function close() {
        onChange('');
        setOpen(false);
        requestAnimationFrame(() => trigger.current?.focus());
    }

    return (
        <div className="min-w-0 flex-1 basis-full sm:basis-0">
            <div
                ref={container}
                className={cn(
                    'relative max-w-full motion-safe:transition-[width] motion-safe:duration-200 motion-safe:ease-out',
                    expanded ? 'w-full sm:w-80' : 'w-10',
                )}
                onKeyDown={(event) => {
                    if (event.key === 'Escape' && compact && expanded) {
                        event.preventDefault();
                        close();
                    }
                }}
            >
                <Button
                    ref={trigger}
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={label}
                    aria-expanded={expanded}
                    aria-controls={id}
                    aria-hidden={expanded}
                    tabIndex={expanded ? -1 : 0}
                    className={cn(
                        'absolute top-0 left-0 motion-safe:transition-opacity motion-safe:duration-150',
                        expanded
                            ? 'pointer-events-none opacity-0'
                            : 'opacity-100',
                    )}
                    onClick={() => {
                        setOpen(true);
                        requestAnimationFrame(() => input.current?.focus());
                    }}
                >
                    <Search aria-hidden="true" />
                </Button>
                <div
                    inert={!expanded}
                    aria-hidden={!expanded}
                    className={cn(
                        'motion-safe:transition-[opacity,transform] motion-safe:duration-200 motion-safe:ease-out',
                        expanded
                            ? 'translate-x-0 opacity-100'
                            : 'pointer-events-none -translate-x-1 opacity-0',
                    )}
                >
                    <Label htmlFor={id} className="sr-only">
                        {label}
                    </Label>
                    <InputGroup>
                        <InputGroupInput
                            ref={input}
                            id={id}
                            type="search"
                            placeholder={`${label}…`}
                            value={value}
                            onChange={(event) => onChange(event.target.value)}
                        />
                        <InputGroupAddon>
                            <Search aria-hidden="true" />
                        </InputGroupAddon>
                        {compact ? (
                            <InputGroupAddon align="inline-end">
                                <InputGroupButton
                                    size="icon-sm"
                                    aria-label={
                                        value
                                            ? 'Cancella ricerca e chiudi'
                                            : 'Chiudi ricerca'
                                    }
                                    onClick={close}
                                >
                                    <X aria-hidden="true" />
                                </InputGroupButton>
                            </InputGroupAddon>
                        ) : null}
                    </InputGroup>
                </div>
            </div>
        </div>
    );
}
