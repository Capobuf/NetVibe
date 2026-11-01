import { cn } from '@/lib/utils';

export function MikrotikMark({
    variant = 'line',
    className,
}: {
    variant?: 'line' | 'solid';
    className?: string;
}) {
    return (
        <img
            src={`/images/mikrotik/${variant === 'solid' ? 'MT_Symbol_Black.svg' : 'MT_Symbol_Line_Black.svg'}`}
            alt=""
            aria-hidden="true"
            width={42}
            height={45}
            draggable={false}
            className={cn(
                'h-4 w-auto shrink-0 object-contain dark:invert',
                className,
            )}
        />
    );
}
