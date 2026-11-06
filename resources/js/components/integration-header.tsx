import { router } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Item,
    ItemActions,
    ItemContent,
    ItemDescription,
    ItemMedia,
    ItemTitle,
} from '@/components/ui/item';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type IntegrationState =
    | 'active'
    | 'pending'
    | 'unconfigured'
    | 'suspended'
    | 'error';

const states: Record<IntegrationState, string> = {
    active: 'Attiva',
    pending: 'Da avviare',
    unconfigured: 'Da configurare',
    suspended: 'Sospesa',
    error: 'Errore',
};

/** Human label for an automatic sync interval in minutes. */
export function intervalLabel(minutes: number) {
    if (minutes === 60) return 'ogni ora';
    if (minutes === 1440) return 'una volta al giorno';
    return minutes < 60 ? `ogni ${minutes} minuti` : `ogni ${minutes / 60} ore`;
}

/** Label/value pairs shown in the status tooltip. */
export function StatusDetails({
    items,
}: {
    items: [string, ReactNode][];
}) {
    return (
        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
            {items.map(([label, value]) => (
                <div key={label} className="contents">
                    <dt className="opacity-70">{label}</dt>
                    <dd className="font-medium">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

/** One vocabulary for the state of every integration; details open in a tooltip. */
export function IntegrationStatus({
    state,
    details,
}: {
    state: IntegrationState;
    details?: ReactNode;
}) {
    const badge = (
        <Badge
            variant={
                state === 'error'
                    ? 'destructive'
                    : state === 'active'
                      ? 'secondary'
                      : 'outline'
            }
        >
            <span
                aria-hidden="true"
                className={cn(
                    'size-1.5 rounded-full',
                    state === 'active' && 'bg-emerald-500',
                    state === 'suspended' && 'bg-amber-500',
                    state === 'error' && 'bg-current',
                    (state === 'pending' || state === 'unconfigured') &&
                        'bg-muted-foreground',
                )}
            />
            {states[state]}
        </Badge>
    );
    if (!details) return badge;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    className="rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    {badge}
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom" align="start">
                {details}
            </TooltipContent>
        </Tooltip>
    );
}

/** Summary shown at the top of every integration page: brand, state, context and main actions. */
export function IntegrationHeader({
    mark,
    title,
    state,
    details,
    description,
    actions,
}: {
    mark: ReactNode;
    title: string;
    state: IntegrationState;
    details?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <Item variant="outline">
            <ItemMedia variant="icon" className="size-10 bg-background">
                {mark}
            </ItemMedia>
            <ItemContent className="min-w-0">
                <ItemTitle className="text-base">
                    {title}
                    <IntegrationStatus state={state} details={details} />
                </ItemTitle>
                {description ? (
                    <ItemDescription className="line-clamp-none text-pretty">
                        {description}
                    </ItemDescription>
                ) : null}
            </ItemContent>
            {actions ? (
                <ItemActions className="flex-wrap">{actions}</ItemActions>
            ) : null}
        </Item>
    );
}

export type Section<T extends string> = {
    value: T;
    label: string;
    icon: LucideIcon;
    count?: number;
    href?: NonNullable<InertiaLinkProps['href']>;
    disabled?: boolean;
};

/**
 * Second-level sections of an integration. The line variant keeps them
 * visually below the integration selector; sections with an href are pages.
 */
export function SectionTabs<T extends string>({
    value,
    sections,
    onChange,
    label,
}: {
    value: T;
    sections: Section<T>[];
    onChange?: (value: T) => void;
    label: string;
}) {
    return (
        <Tabs
            value={value}
            activationMode="manual"
            onValueChange={(next) => {
                const section = sections.find((item) => item.value === next);
                if (section?.href) router.visit(section.href);
                else onChange?.(next as T);
            }}
        >
            <TabsList variant="line" aria-label={label}>
                {sections.map(
                    ({ value: key, label, icon: Icon, count, disabled }) => (
                        <TabsTrigger key={key} value={key} disabled={disabled}>
                            <Icon aria-hidden="true" />
                            {label}
                            {count ? (
                                <Badge
                                    variant="secondary"
                                    className="tabular-nums"
                                >
                                    {count}
                                </Badge>
                            ) : null}
                        </TabsTrigger>
                    ),
                )}
            </TabsList>
        </Tabs>
    );
}
