import { CircleAlert } from 'lucide-react';
import { DataTable } from '@/components/data-table';
import TextLink from '@/components/text-link';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/utils';
import { show as deviceShow } from '@/routes/companies/sites/devices';
import type { TopologyObservation } from '@/types/topology';

type Route = { company: number; site: number };
type Column = 'port' | 'name' | 'ip' | 'mac' | 'source' | 'seen_at';

export function observedPort(row: TopologyObservation): string {
    return row.port ?? (row.wireless ? 'Wi-Fi' : 'Porta non determinabile');
}

const sourceNames = (row: TopologyObservation) =>
    [
        ...new Set(
            row.sources.map((source) =>
                source.vendor === 'unifi' ? 'UniFi' : 'MikroTik',
            ),
        ),
    ].join(', ');

const lastSeen = (row: TopologyObservation) =>
    row.sources
        .map((source) => source.seen_at)
        .filter((value): value is string => !!value)
        .sort()
        .at(-1) ?? null;

const isStale = (row: TopologyObservation) =>
    row.sources.every((source) => source.stale);

function HostName({ row, route }: { row: TopologyObservation; route: Route }) {
    if (!row.name)
        return <span className="text-muted-foreground">Non identificato</span>;
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
}: {
    rows: TopologyObservation[];
    route: Route;
}) {
    const value = (row: TopologyObservation, column: Column) => {
        switch (column) {
            case 'port':
                return observedPort(row);
            case 'name':
                return row.name;
            case 'ip':
                return row.ips.join(', ');
            case 'mac':
                return row.macs.join(', ');
            case 'source':
                return sourceNames(row);
            default:
                return lastSeen(row);
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
                    case 'ip':
                    case 'mac':
                        return (
                            <span className="tabular-nums">
                                {value(row, column) || '—'}
                            </span>
                        );
                    case 'seen_at': {
                        const seen = lastSeen(row);
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

/** Compact list for one port, sized for the port popover. */
export function ObservedHostsList({
    rows,
    route,
}: {
    rows: TopologyObservation[];
    route: Route;
}) {
    return (
        <section
            aria-label="Rilevati su questa porta"
            className="flex flex-col gap-3"
        >
            <h3 className="text-sm font-medium">
                Rilevati su questa porta ({rows.length})
            </h3>
            <ul className="divide-y rounded-lg border">
                {rows.map((row) => {
                    const seen = lastSeen(row);
                    return (
                        <li
                            key={row.key}
                            className="flex flex-col gap-0.5 px-3 py-2"
                        >
                            <span className="flex items-baseline justify-between gap-3 text-sm">
                                <span className="min-w-0 truncate">
                                    <HostName row={row} route={route} />
                                </span>
                                {row.ips.length > 0 && (
                                    <span className="shrink-0 text-muted-foreground tabular-nums">
                                        {row.ips.join(', ')}
                                    </span>
                                )}
                            </span>
                            <span className="flex items-center gap-1.5 text-xs text-muted-foreground tabular-nums">
                                <span className="min-w-0 truncate">
                                    {[
                                        row.macs.join(', '),
                                        `${sourceNames(row)}${seen ? ` ${formatDateTime(seen)}` : ''}`,
                                    ].join(' · ')}
                                </span>
                                {isStale(row) && (
                                    <CircleAlert
                                        className="size-3.5 shrink-0 text-destructive"
                                        aria-label="Non aggiornato"
                                    />
                                )}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
