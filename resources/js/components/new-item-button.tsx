import type { ComponentProps } from 'react';
import { Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export function NewItemButton({
    className,
    ...props
}: ComponentProps<typeof Button>) {
    return (
        <Button
            className={cn(
                'group border border-transparent hover:border-primary hover:bg-primary-foreground hover:text-primary motion-reduce:transition-none',
                className,
            )}
            {...props}
        >
            <span className="inline-flex motion-safe:transition-transform motion-safe:duration-150 motion-safe:group-hover:rotate-90 motion-safe:group-active:scale-[0.96]">
                <Plus aria-hidden="true" />
            </span>
            Nuovo
        </Button>
    );
}
