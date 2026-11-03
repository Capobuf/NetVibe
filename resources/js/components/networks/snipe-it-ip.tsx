import { SourceAvatar } from '@/components/networks/source-avatar';
import { ArrowUpRight, TriangleAlert } from 'lucide-react';
import { SnipeItMark } from '@/components/snipe-it-mark';
import { MikrotikMark } from '@/components/mikrotik-mark';
import {
    sourceLabel,
    type MikrotikSource,
} from '@/components/networks/mikrotik-source';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Table,
    TableHeader,
    TableHead,
    TableBody,
    TableRow,
    TableCell,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDateTime } from '@/lib/utils';
import type { IpAddress } from '@/types/network';

export function SnipeItIp({
    source,
    compact = false,
    avatar = false,
    mikrotikSources = [],
}: {
    source: IpAddress['snipe_it'];
    compact?: boolean;
    avatar?: boolean;
    mikrotikSources?: MikrotikSource[];
}) {
    if (!source) return null;
    const problem = !source.present
        ? 'Asset non più presente in Snipe-IT.'
        : !source.active
          ? 'Sincronizzazione Snipe-IT non aggiornata.'
          : source.problem;
    if (avatar)
        return (
            <SourceAvatar
                href={source.url}
                label={`Apri ${source.name ?? 'asset'} in Snipe-IT (nuova scheda)${problem ? ` · ${problem}` : ''}`}
                warning={Boolean(problem)}
            >
                <SnipeItMark />
            </SourceAvatar>
        );
    const link = (
        <Button asChild variant="outline" size="sm">
            <a
                href={source.url}
                target="_blank"
                rel="noopener noreferrer"
                aria-label={`Apri ${source.name ?? 'asset'} in Snipe-IT (nuova scheda)`}
                onClick={(event) => event.stopPropagation()}
            >
                <SnipeItMark />
                Apri
                <ArrowUpRight data-icon="inline-end" />
            </a>
        </Button>
    );
    if (compact)
        return (
            <span className="inline-flex items-center gap-2">
                {link}
                {problem ? (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span
                                tabIndex={0}
                                role="img"
                                aria-label={problem}
                                className="rounded-sm text-destructive outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <TriangleAlert className="size-4" />
                            </span>
                        </TooltipTrigger>
                        <TooltipContent className="max-w-xs">
                            {problem}
                        </TooltipContent>
                    </Tooltip>
                ) : null}
            </span>
        );
    return (
        <section className="flex flex-col gap-3 rounded-lg border p-4 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 className="font-medium">Snipe-IT</h3>
                {link}
            </div>
            <dl className="grid gap-3 sm:grid-cols-2">
                {Object.entries({
                    Nome: source.name,
                    IP: source.ip,
                    MAC: source.mac,
                    'Ultima sincronizzazione': source.synced_at
                        ? formatDateTime(source.synced_at)
                        : null,
                }).map(([label, value]) => (
                    <div key={label}>
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="break-words">{value ?? '—'}</dd>
                    </div>
                ))}
            </dl>
            {source.notes ? (
                <div>
                    <p className="text-muted-foreground">Note Snipe-IT</p>
                    <p className="whitespace-pre-wrap">{source.notes}</p>
                </div>
            ) : null}
            {problem ? (
                <Alert>
                    <TriangleAlert />
                    <AlertDescription>{problem}</AlertDescription>
                </Alert>
            ) : null}
            {mikrotikSources.length ? (
                <div className="flex flex-col gap-4">
                    <h4 className="font-medium">Confronto con MikroTik</h4>
                    {mikrotikSources.map((reading) => (
                        <section
                            key={reading.id}
                            className="flex min-w-0 flex-col gap-3"
                        >
                            <div className="flex flex-col gap-1">
                                <p className="flex items-start gap-2 font-medium">
                                    <MikrotikMark />
                                    <span className="min-w-0 break-words">
                                        {reading.source} ·{' '}
                                        {sourceLabel(reading)}
                                    </span>
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Ultima lettura:{' '}
                                    <time dateTime={reading.seen_at}>
                                        {formatDateTime(reading.seen_at)}
                                    </time>
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    {reading.state === 'missing' ? (
                                        <Badge variant="outline">
                                            Non più presente
                                        </Badge>
                                    ) : null}
                                    {reading.stale ||
                                    reading.state === 'unknown' ? (
                                        <Badge variant="outline">
                                            Non aggiornato
                                        </Badge>
                                    ) : null}
                                    {reading.exclusion ? (
                                        <Badge variant="outline">
                                            Esclusa dalla sincronizzazione
                                        </Badge>
                                    ) : null}
                                    {reading.data.status ? (
                                        <Badge variant="secondary">
                                            Stato riportato:{' '}
                                            {reading.data.status}
                                        </Badge>
                                    ) : null}
                                </div>
                            </div>
                            <Table
                                className="table-fixed"
                                aria-label={`Confronto Snipe-IT e ${reading.source}: ${sourceLabel(reading)}`}
                            >
                                <TableHeader>
                                    <TableRow>
                                        <TableHead
                                            scope="col"
                                            className="w-24 px-2"
                                        >
                                            Campo
                                        </TableHead>
                                        <TableHead scope="col" className="px-2">
                                            Snipe-IT
                                        </TableHead>
                                        <TableHead scope="col" className="px-2">
                                            MikroTik
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {[
                                        [
                                            'Nome / hostname',
                                            source.name,
                                            reading.data.hostname_observed,
                                        ],
                                        ['IP', source.ip, reading.ip],
                                        [
                                            'MAC',
                                            source.mac,
                                            reading.data.mac ??
                                                reading.data.mac_address,
                                        ],
                                    ].map(([label, inventory, network]) => (
                                        <TableRow key={label}>
                                            <TableHead
                                                scope="row"
                                                className="h-auto px-2 py-3 align-top whitespace-normal"
                                            >
                                                {label}
                                            </TableHead>
                                            <TableCell className="px-2 py-3 align-top break-words whitespace-normal">
                                                {inventory || 'Non indicato'}
                                            </TableCell>
                                            <TableCell className="px-2 py-3 align-top break-words whitespace-normal">
                                                {network || 'Non indicato'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </section>
                    ))}
                </div>
            ) : null}
        </section>
    );
}
