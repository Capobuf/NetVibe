import { Fragment, type ReactNode } from 'react';
import { CopyValue } from '@/components/copy-value';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn, formatDateTime, timeAgo } from '@/lib/utils';

export type FactTone = 'online' | 'offline' | 'idle';

type FactValue = {
    copy?: boolean;
    tone?: FactTone;
    hint?: string;
    origins: string[];
};

export type Facts = Map<string, Map<string, FactValue>>;

const toneDots: Record<FactTone, string> = {
    online: 'bg-emerald-500',
    offline: 'bg-destructive',
    idle: 'bg-muted-foreground',
};

// Focus the panel rather than its first copy button, which would look pre-selected.
export function focusPanel(event: Event) {
    event.preventDefault();
    (event.currentTarget as HTMLElement).focus();
}

/** Collects each distinct value once per label, remembering which records reported it. */
export function collectFacts() {
    const facts: Facts = new Map();
    const add = (
        label: string,
        value: string | number | boolean | null | undefined,
        origin: string,
        extra: Omit<FactValue, 'origins'> = {},
    ) => {
        if (value === undefined || value === null || value === '') return;
        const values = facts.get(label) ?? new Map<string, FactValue>();
        const fact = values.get(String(value)) ?? { ...extra, origins: [] };
        if (!fact.origins.includes(origin)) fact.origins.push(origin);
        values.set(String(value), fact);
        facts.set(label, values);
    };
    return { facts, add };
}

/** One sheet per source system: header with freshness, then label/value rows. */
export function FactSheet({
    mark,
    title,
    flags,
    readAt,
    stale,
    facts,
    order,
    children,
}: {
    mark: ReactNode;
    title: ReactNode;
    flags: string[];
    readAt?: string;
    stale: boolean;
    facts: Facts;
    order: string[];
    children?: ReactNode;
}) {
    const rank = (label: string) =>
        order.includes(label) ? order.indexOf(label) : order.length;

    return (
        <div className="overflow-hidden rounded-lg border text-sm">
            <div className="flex items-center gap-2 border-b bg-muted/30 px-4 py-2.5">
                {mark}
                {title}
                {flags.map((flag) => (
                    <Badge key={flag} variant="outline">
                        {flag}
                    </Badge>
                ))}
                {readAt ? (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <time
                                dateTime={readAt}
                                className={cn(
                                    'ml-auto shrink-0 text-xs',
                                    stale
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {stale ? 'Non aggiornato · ' : 'Letto '}
                                {timeAgo(readAt)}
                            </time>
                        </TooltipTrigger>
                        <TooltipContent>
                            {formatDateTime(readAt)}
                        </TooltipContent>
                    </Tooltip>
                ) : null}
            </div>
            <dl className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-6 gap-y-1 px-4 py-3">
                {[...facts]
                    .sort(([left], [right]) => rank(left) - rank(right))
                    .map(([label, values]) => (
                        <Fragment key={label}>
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="flex min-w-0 flex-col items-start">
                                {[...values].map(([value, fact]) => {
                                    const content = (
                                        <span className="flex min-w-0 items-center gap-2">
                                            {fact.tone ? (
                                                <span
                                                    aria-hidden="true"
                                                    className={cn(
                                                        'size-2 shrink-0 rounded-full',
                                                        toneDots[fact.tone],
                                                    )}
                                                />
                                            ) : null}
                                            {fact.copy ? (
                                                <CopyValue
                                                    value={value}
                                                    className="-ml-1.5 h-7 shrink-0 px-1.5 whitespace-nowrap tabular-nums"
                                                />
                                            ) : (
                                                <span className="min-w-0 py-1 break-words whitespace-pre-wrap">
                                                    {value}
                                                </span>
                                            )}
                                            {values.size > 1 ? (
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {fact.origins.join(', ')}
                                                </span>
                                            ) : null}
                                        </span>
                                    );
                                    return fact.hint ? (
                                        <Tooltip key={value}>
                                            <TooltipTrigger asChild>
                                                {content}
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                {fact.hint}
                                            </TooltipContent>
                                        </Tooltip>
                                    ) : (
                                        <Fragment key={value}>
                                            {content}
                                        </Fragment>
                                    );
                                })}
                            </dd>
                        </Fragment>
                    ))}
            </dl>
            {children}
        </div>
    );
}
