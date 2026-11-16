import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { DataTable } from '@/components/data-table';
import TextLink from '@/components/text-link';
import { Badge } from '@/components/ui/badge';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import type { DeviceLink } from '@/components/devices/port-link';
import { cn, formatDateTime } from '@/lib/utils';
import { show as deviceShow } from '@/routes/companies/sites/devices';
import type { TopologyObservation } from '@/types/topology';

type Route = { company: number; site: number };
type Column =
    | 'port'
    | 'name'
    | 'documented'
    | 'ip'
    | 'mac'
    | 'source'
    | 'seen_at';

export function observedPort(row: TopologyObservation): string {
    return row.port ?? (row.wireless ? 'Wi-Fi' : 'Porta non determinabile');
}

const sourceNames = (rows: TopologyObservation[]) =>
    [
        ...new Set(
            rows.flatMap((row) =>
                row.sources.map((source) =>
                    source.vendor === 'unifi' ? 'UniFi' : 'MikroTik',
                ),
            ),
        ),
    ].join(', ');

const lastSeen = (rows: TopologyObservation[]) =>
    rows
        .flatMap((row) => row.sources.map((source) => source.seen_at))
        .filter((value): value is string => !!value)
        .sort()
        .at(-1) ?? null;

// An unidentified host is named by its MAC.
const hostName = (row: TopologyObservation) => row.name ?? row.macs[0];

const isStale = (row: TopologyObservation) =>
    row.sources.every((source) => source.stale);

function HostName({ row, route }: { row: TopologyObservation; route: Route }) {
    if (!row.name)
        return <span className="tabular-nums">{hostName(row)}</span>;
    return row.device_id ? (
        <TextLink href={deviceShow({ ...route, device: row.device_id })}>
            {row.name}
        </TextLink>
    ) : (
        <span>{row.name}</span>
    );
}

export function ObservedHostsTable({
    rows,
    route,
    links,
}: {
    rows: TopologyObservation[];
    route: Route;
    links: DeviceLink[];
}) {
    const linkOf = (row: TopologyObservation) =>
        row.connector_id
            ? links.find((link) => link.connector_id === row.connector_id)
            : undefined;
    const value = (row: TopologyObservation, column: Column) => {
        switch (column) {
            case 'port':
                return observedPort(row);
            case 'name':
                return hostName(row);
            case 'documented': {
                const link = linkOf(row);
                return link
                    ? `${link.peer.name ?? 'Dispositivo'} · ${link.peer.port}`
                    : null;
            }
            case 'ip':
                return row.ips.join(', ');
            case 'mac':
                return row.macs.join(', ');
            case 'source':
                return sourceNames([row]);
            default:
                return lastSeen([row]);
        }
    };
    return (
        <DataTable
            name="device-observed-hosts"
            rows={rows.map((row) => ({ ...row, id: row.key }))}
            defaultSort={{ column: 'port', direction: 'asc' }}
            columns={[
                { id: 'port', label: 'Porta', visible: true },
                { id: 'name', label: 'Dispositivo', visible: true },
                {
                    id: 'documented',
                    label: 'Collegamento documentato',
                    visible: true,
                },
                { id: 'ip', label: 'IPv4', visible: true },
                { id: 'mac', label: 'MAC', visible: true },
                { id: 'source', label: 'Fonte', visible: true },
                { id: 'seen_at', label: 'Ultima lettura', visible: true },
            ]}
            value={value}
            render={(row, column) => {
                switch (column) {
                    case 'port':
                        return row.port ? (
                            <span className="font-medium">{row.port}</span>
                        ) : (
                            <span className="text-muted-foreground">
                                {observedPort(row)}
                                {row.reported_ports.length > 0 &&
                                    ` · ${row.reported_ports.join(', ')}`}
                            </span>
                        );
                    case 'name':
                        return <HostName row={row} route={route} />;
                    case 'documented': {
                        const link = linkOf(row);
                        return link ? (
                            <TextLink
                                href={deviceShow({
                                    ...route,
                                    device: link.peer.device_id,
                                })}
                            >
                                {value(row, column)}
                            </TextLink>
                        ) : (
                            '—'
                        );
                    }
                    case 'ip':
                    case 'mac':
                        return (
                            <span className="tabular-nums">
                                {value(row, column) || '—'}
                            </span>
                        );
                    case 'seen_at': {
                        const seen = lastSeen([row]);
                        return (
                            <span className="flex items-center gap-2 tabular-nums">
                                {seen ? formatDateTime(seen) : '—'}
                                {isStale(row) && (
                                    <Badge variant="outline">
                                        Non aggiornato
                                    </Badge>
                                )}
                            </span>
                        );
                    }
                    default:
                        return value(row, column) || '—';
                }
            }}
            empty="Nessun dispositivo rilevato."
        />
    );
}

const explanations = [
    'Switch non gestito',
    'Telefono IP con PC in cascata',
    'Access point o bridge',
    'Macchine virtuali',
    'Altro apparato non documentato',
];

// Inventory devices first, then hosts known by name, then bare MACs.
const rank = (row: TopologyObservation) =>
    row.device_id ? 0 : row.name ? 1 : 2;

/** Compact list for one port, sized for the port popover. */
export function ObservedHostsList({
    rows,
    route,
}: {
    rows: TopologyObservation[];
    route: Route;
}) {
    const [explained, setExplained] = useState(false);
    // Every host on a port is read by the same sources at the same time: show it once.
    const seen = lastSeen(rows);
    const stale = rows.every(isStale);
    // Several MACs prove neither a switch nor how many ports it has.
    const several = rows.reduce((total, row) => total + row.macs.length, 0) > 1;
    return (
        <section
            aria-label="Rilevati su questa porta"
            className="flex flex-col gap-3"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-sm font-medium">
                        Rilevati su questa porta{' '}
                        <span className="font-normal text-muted-foreground tabular-nums">
                            {rows.length}
                        </span>
                    </h3>
                    <p className="text-xs text-muted-foreground tabular-nums">
                        {sourceNames(rows)}
                        {seen && ` · ${formatDateTime(seen)}`}
                    </p>
                </div>
                {stale && (
                    <Badge variant="outline" className="shrink-0">
                        Non aggiornato
                    </Badge>
                )}
            </div>
            {several && (
                <Collapsible
                    open={explained}
                    onOpenChange={setExplained}
                    className="rounded-lg bg-muted/50"
                >
                    <CollapsibleTrigger className="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                        Possibili spiegazioni
                        <ChevronDown
                            aria-hidden
                            className={cn(
                                'size-4 shrink-0 text-muted-foreground transition-transform duration-200 motion-reduce:transition-none',
                                explained && 'rotate-180',
                            )}
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <ul className="flex list-disc flex-col gap-1 px-3 pt-1 pb-3 pl-7 text-sm text-muted-foreground">
                            {explanations.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </CollapsibleContent>
                </Collapsible>
            )}
            <ul className="divide-y rounded-lg border">
                {[...rows].sort((a, b) => rank(a) - rank(b)).map((row) => {
                    const macs = row.name ? row.macs : row.macs.slice(1);
                    return (
                        <li
                            key={row.key}
                            className="flex items-baseline justify-between gap-3 px-3 py-2"
                        >
                            <span className="min-w-0">
                                <span className="block truncate text-sm">
                                    <HostName row={row} route={route} />
                                </span>
                                {(macs.length > 0 ||
                                    (!stale && isStale(row))) && (
                                    <span className="block truncate text-xs text-muted-foreground tabular-nums">
                                        {[
                                            macs.join(', '),
                                            !stale &&
                                                isStale(row) &&
                                                'Non aggiornato',
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                )}
                            </span>
                            {row.ips.length > 0 && (
                                <span className="shrink-0 text-sm text-muted-foreground tabular-nums">
                                    {row.ips.join(', ')}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
