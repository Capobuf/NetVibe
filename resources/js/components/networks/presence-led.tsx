import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type Presence = 'online' | 'offline' | 'unknown';

const states: Record<Presence, { label: string; className: string }> = {
    online: {
        label: 'Online',
        className:
            'bg-current text-emerald-500 shadow-[0_0_8px_2px_currentColor] motion-safe:animate-led-glow',
    },
    offline: {
        label: 'Offline',
        className:
            'bg-current text-red-500 shadow-[0_0_8px_2px_currentColor] motion-safe:animate-led-glow',
    },
    unknown: {
        label: 'Stato sconosciuto',
        className: 'bg-muted-foreground/40',
    },
};

/** Operational state from the integrations, separate from the address assignment. */
export function PresenceLed({ presence }: { presence: Presence }) {
    const state = states[presence];
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    tabIndex={0}
                    role="img"
                    aria-label={state.label}
                    onClick={(event) => event.stopPropagation()}
                    className="flex size-6 items-center justify-center rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <span
                        className={cn('size-2.5 rounded-full', state.className)}
                    />
                </span>
            </TooltipTrigger>
            <TooltipContent>{state.label}</TooltipContent>
        </Tooltip>
    );
}
