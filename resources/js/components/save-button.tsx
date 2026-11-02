import { Check, LoaderCircle, Save } from 'lucide-react';
import type { ComponentProps } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type Props = Omit<ComponentProps<typeof Button>, 'asChild'> & {
    processing: boolean;
    saved?: boolean;
};

export function SaveButton({
    processing,
    saved = false,
    disabled,
    children = 'Salva',
    className,
    variant,
    type = 'submit',
    ...props
}: Props) {
    const state = processing ? 'saving' : saved ? 'saved' : 'dirty';
    return (
        <Button
            {...props}
            type={type}
            variant={variant ?? (saved && !processing ? 'outline' : 'default')}
            className={cn('min-w-36', className)}
            disabled={disabled || processing || saved}
            aria-busy={processing}
        >
            <span aria-live="polite">
                <span
                    key={state}
                    className="inline-flex items-center gap-2 motion-safe:animate-in motion-safe:duration-200 motion-safe:fade-in motion-safe:slide-in-from-bottom-1"
                >
                    {processing ? (
                        <LoaderCircle className="motion-safe:animate-spin" />
                    ) : saved ? (
                        <Check />
                    ) : (
                        <Save />
                    )}
                    {processing ? 'Salvataggio…' : saved ? 'Salvato' : children}
                </span>
            </span>
        </Button>
    );
}
