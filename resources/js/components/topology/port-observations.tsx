import { ChevronDown, CircleAlert } from 'lucide-react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { formatDateTime } from '@/lib/utils';
import { show as deviceShow } from '@/routes/companies/sites/devices';
import type { TopologyObservation } from '@/types/topology';

const UNKNOWN = 'Porta non determinabile';

export function PortObservations({
    rows,
    route,
}: {
    rows: TopologyObservation[];
    route: { company: number; site: number };
}) {
    const groups = new Map<string, TopologyObservation[]>();
    for (const row of rows) {
        const port = row.port ?? (row.wireless ? 'Wi-Fi' : UNKNOWN);
        groups.set(port, [...(groups.get(port) ?? []), row]);
    }
    // Busy ports start closed so the card stays readable.
    const open = rows.length <= 8;
    return (
        <div className="flex flex-col gap-1 border-t p-2">
            <h4 className="flex items-center justify-between px-2 pt-1 pb-1 text-sm font-medium">
                Rilevati sulle porte
                <span className="text-xs font-normal text-muted-foreground tabular-nums">
                    {rows.length}
                </span>
            </h4>
            {[...groups].map(([port, items]) => (
                <Collapsible key={port} defaultOpen={open}>
                    <CollapsibleTrigger asChild>
                        <Button
                            variant="ghost"
                            className="group h-8 w-full justify-between px-2 text-sm"
                        >
                            <span
                                className={
                                    port === UNKNOWN
                                        ? 'font-normal text-muted-foreground'
                                        : undefined
                                }
                            >
                                {port}
                            </span>
                            <span className="flex items-center gap-1 text-xs font-normal text-muted-foreground tabular-nums">
                                {items.length}
                                <ChevronDown className="transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none" />
                            </span>
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <ul className="flex flex-col pb-1">
                            {items.map((row) => (
                                <ObservationRow
                                    key={row.key}
                                    row={row}
                                    route={route}
                                />
                            ))}
                        </ul>
                    </CollapsibleContent>
                </Collapsible>
            ))}
        </div>
    );
}

function ObservationRow({
    row,
    route,
}: {
    row: TopologyObservation;
    route: { company: number; site: number };
}) {
    const title = row.name ?? row.macs[0];
    const details = [
        ...(row.name ? row.macs : row.macs.slice(1)),
        ...row.ips,
        !row.port &&
            row.reported_ports.length > 0 &&
            `su ${row.reported_ports.join(', ')}`,
    ].filter(Boolean);
    const stale = row.sources.every((source) => source.stale);
    return (
        <li className="flex items-start justify-between gap-3 rounded-md px-2 py-1.5 pl-4">
            <span className="min-w-0">
                <span className="block truncate text-sm">
                    {row.device_id ? (
                        <TextLink
                            href={deviceShow({
                                ...route,
                                device: row.device_id,
                            })}
                        >
                            {title}
                        </TextLink>
                    ) : (
                        title
                    )}
                </span>
                {details.length > 0 && (
                    <span className="block truncate font-mono text-xs text-muted-foreground">
                        {details.join(' · ')}
                    </span>
                )}
            </span>
            <span className="flex shrink-0 items-start gap-1.5 text-right text-xs text-muted-foreground">
                <span className="flex flex-col">
                    {row.sources.map((source) => (
                        <span key={source.source}>
                            {source.vendor === 'unifi' ? 'UniFi' : 'MikroTik'}
                            {source.seen_at &&
                                ` · ${formatDateTime(source.seen_at)}`}
                        </span>
                    ))}
                </span>
                {stale && (
                    <CircleAlert
                        className="size-4 text-destructive"
                        aria-label="Non aggiornato"
                    />
                )}
            </span>
        </li>
    );
}
