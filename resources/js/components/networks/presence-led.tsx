import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type Presence = 'online' | 'offline' | 'unknown';

const states: Record<Presence, { label: string; color: string }> = {
    online: { label: 'Online', color: 'text-emerald-500' },
    offline: { label: 'Offline', color: 'text-red-500' },
    unknown: { label: 'Stato sconosciuto', color: 'text-muted-foreground/40' },
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
                    <span className={cn('relative flex size-[7px]', state.color)}>
                        {presence !== 'unknown' ? (
                            <span
                                aria-hidden="true"
                                className="absolute -inset-[3px] rounded-full bg-current opacity-45 blur-[3px] motion-safe:animate-led-breathe"
                            />
                        ) : null}
                        {/* A soft lit core: light falls off towards the edge instead of a hard bright dot. */}
                        <span className="relative size-full rounded-full bg-[radial-gradient(circle_at_40%_35%,color-mix(in_oklab,currentColor_60%,white),currentColor_75%)] opacity-85" />
                    </span>
                </span>
            </TooltipTrigger>
            <TooltipContent>{state.label}</TooltipContent>
        </Tooltip>
    );
}
