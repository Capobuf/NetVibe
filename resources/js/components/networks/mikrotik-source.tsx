import { useState, type ReactNode } from 'react';
import { TriangleAlert } from 'lucide-react';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { CopyValue } from '@/components/copy-value';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';

export type MikrotikSource = {
    id: number;
    edit_mode?: 'planned' | 'manual' | null;
    matched_at?: string | null;
    kind: string;
    state: 'current' | 'missing' | 'unknown';
    source: string;
    stale: boolean;
    seen_at: string;
    ip: string | null;
    problem: string | null;
    subnet_id: number | null;
    vlan_id: number | null;
    ip_address_id: number | null;
    data: {
        menu: string;
        comment?: string | null;
        name?: string | null;
        tag?: number | null;
        cidr?: string | null;
        context?: string | null;
        interface?: string | null;
        mac?: string | null;
        server?: string | null;
        fixed?: boolean;
        active?: boolean;
        status?: string | null;
        hostname_observed?: string | null;
        dynamic?: string | boolean;
        disabled?: string | boolean;
        invalid?: string | boolean;
        tagged?: string;
        untagged?: string;
        'current-tagged'?: string;
        'current-untagged'?: string;
        'on-interface'?: string;
    };
};

export function sourceLabel(source: MikrotikSource) {
    if (source.kind === 'lease')
        return source.data.fixed
            ? 'Prenotazione DHCP fissa'
            : 'Lease senza IPv4 fisso';
    if (source.kind === 'observation') {
        if (source.data.menu === '/ip/arp') return 'Osservazione ARP';
        if (source.data.menu === '/interface/bridge/host')
            return 'Osservazione MAC';
        if (source.data.menu === '/ip/neighbor') return 'Neighbor';
        return 'Osservazione DHCP';
    }
    const labels: Record<string, string> = {
        vlan: 'VLAN',
        subnet: 'Subnet',
        address: 'Indirizzo del dispositivo',
        context: 'Contesto della fonte',
    };
    return labels[source.kind] ?? source.kind;
}

export function SourceDetails({ sources }: { sources: MikrotikSource[] }) {
    return (
        <div className="flex flex-col gap-3">
            {sources.map((source) => (
                <Card key={source.id} className="gap-3 py-4">
                    <CardHeader className="gap-2 px-4">
                        <CardTitle className="flex items-start gap-2.5">
                            <MikrotikMark className="h-5" />
                            <span className="min-w-0 break-words">
                                {source.source} · {sourceLabel(source)}
                            </span>
                        </CardTitle>
                        <div className="flex flex-wrap gap-2">
                            {source.edit_mode ? (
                                <Badge variant="outline">
                                    {source.edit_mode === 'manual'
                                        ? 'Valore manuale mantenuto'
                                        : source.matched_at
                                          ? 'Modifica prevista riscontrata'
                                          : 'Modifica prevista'}
                                </Badge>
                            ) : null}
                            {source.state === 'missing' ? (
                                <Badge variant="outline">
                                    Non più presente nella fonte
                                </Badge>
                            ) : null}
                            {source.stale ? (
                                <Badge variant="outline">Non aggiornato</Badge>
                            ) : null}
                            {source.data.active === false ? (
                                <Badge variant="outline">
                                    Configurazione non attiva o non
                                    determinabile
                                </Badge>
                            ) : null}
                            {source.data.status ? (
                                <Badge variant="secondary">
                                    {source.data.status}
                                </Badge>
                            ) : null}
                        </div>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3 px-4 text-sm">
                        <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2">
                            {Object.entries({
                                IPv4: source.ip,
                                MAC: source.data.mac,
                                Subnet: source.data.cidr,
                                VLAN: source.data.tag,
                                Oggetto: source.data.name,
                                Contesto: source.data.context,
                                Interfaccia:
                                    source.data.interface ??
                                    source.data['on-interface'],
                                'Server DHCP': source.data.server,
                                'Hostname osservato':
                                    source.data.hostname_observed,
                                Tagged: source.data.tagged,
                                Untagged: source.data.untagged,
                                'Tagged attuali': source.data['current-tagged'],
                                'Untagged attuali':
                                    source.data['current-untagged'],
                                'Commento MikroTik': source.data.comment,
                            })
                                .filter(
                                    ([, value]) =>
                                        value !== undefined &&
                                        value !== null &&
                                        value !== '',
                                )
                                .map(([label, value]) => (
                                    <div className="contents" key={label}>
                                        <dt className="text-muted-foreground">
                                            {label}
                                        </dt>
                                        <dd className="break-words whitespace-pre-wrap">
                                            {String(value)}
                                        </dd>
                                    </div>
                                ))}
                        </dl>
                        {source.problem ? (
                            <p className="text-destructive">{source.problem}</p>
                        ) : null}
                        <p className="text-xs text-muted-foreground">
                            Ultimo rilevamento:{' '}
                            {new Date(source.seen_at).toLocaleString('it-IT')}
                        </p>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

export function MikrotikIndicator({
    sources,
    mac,
    field = 'ip',
    children,
}: {
    sources: MikrotikSource[];
    mac?: string | null;
    field?: 'ip' | 'mac' | 'hostname';
    children?: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const relevant =
        field === 'mac'
            ? sources.filter((source) => source.data.mac)
            : field === 'hostname'
              ? sources.filter((source) => source.data.hostname_observed)
              : sources;
    if (!relevant.length) return null;
    const current = relevant.filter((source) => source.state !== 'missing');
    const macs = new Set(
        current.map((source) => source.data.mac?.toUpperCase()).filter(Boolean),
    );
    const mismatch =
        current.some((source) => source.problem) ||
        (field === 'mac' &&
            (macs.size > 1 ||
                (mac &&
                    [...macs].some(
                        (value) =>
                            value !== mac.toUpperCase().replaceAll('-', ':'),
                    ))));
    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size={children ? 'sm' : 'icon'}
                    className={
                        children
                            ? 'h-8 max-w-full min-w-0 gap-1.5 px-2 py-1 font-normal hover:bg-transparent'
                            : undefined
                    }
                    aria-label={
                        children
                            ? undefined
                            : mismatch
                              ? 'Differenza MikroTik: apri le fonti'
                              : 'Apri le fonti MikroTik'
                    }
                    onClick={(event) => {
                        event.stopPropagation();
                    }}
                >
                    {children ??
                        (mismatch ? (
                            <TriangleAlert className="text-destructive" />
                        ) : (
                            <MikrotikMark className="h-5" />
                        ))}
                </Button>
            </SheetTrigger>
            <SheetContent
                className="w-full overflow-y-auto sm:max-w-lg"
                aria-describedby={undefined}
                onClick={(event) => event.stopPropagation()}
            >
                <SheetHeader>
                    <SheetTitle className="flex items-center gap-2.5">
                        <MikrotikMark variant="solid" className="h-5" />
                        {field === 'mac'
                            ? 'MAC · Fonti MikroTik'
                            : field === 'hostname'
                              ? 'Hostname · Fonti MikroTik'
                              : 'Fonti MikroTik'}
                    </SheetTitle>
                </SheetHeader>
                <div className="flex flex-col gap-4 px-4 pb-6">
                    {field === 'mac' && mac ? (
                        <p className="text-sm">
                            MAC documentato:{' '}
                            <span className="tabular-nums">{mac}</span>
                        </p>
                    ) : null}
                    <SourceDetails sources={relevant} />
                </div>
            </SheetContent>
        </Sheet>
    );
}

export function MikrotikValue({
    value,
    field,
    sources,
}: {
    value: string | null;
    field: 'mac' | 'hostname';
    sources: MikrotikSource[];
}) {
    const current = sources.filter((source) => source.state !== 'missing');
    const documented =
        field === 'mac'
            ? value?.replaceAll('-', ':').toLowerCase()
            : value?.toLowerCase();
    const values = [
        ...new Set(
            current
                .map((source) =>
                    (field === 'mac'
                        ? source.data.mac
                        : source.data.hostname_observed
                    )?.trim(),
                )
                .filter(
                    (item): item is string =>
                        Boolean(item) && item?.toLowerCase() !== documented,
                ),
        ),
    ].sort((left, right) => left.localeCompare(right));
    const valueSources = current.filter((source) =>
        values.includes(
            (field === 'mac'
                ? source.data.mac
                : source.data.hostname_observed
            )?.trim() ?? '',
        ),
    );
    return (
        <div className="inline-flex max-w-full items-center gap-1 whitespace-nowrap">
            {value ? (
                <CopyValue
                    value={value}
                    className="h-8 max-w-96 py-1 whitespace-nowrap [&>span]:truncate"
                />
            ) : null}
            {values.length ? (
                <MikrotikIndicator
                    sources={current}
                    field={field}
                    mac={field === 'mac' ? value : undefined}
                >
                    <span
                        className="max-w-96 truncate"
                        title={values.join(', ')}
                    >
                        {values.join(', ')}
                    </span>
                    <MikrotikMark />
                    <span className="sr-only">Fonti MikroTik</span>
                    {valueSources.every((source) => source.stale) ? (
                        <span className="font-sans text-xs text-muted-foreground">
                            Non aggiornato
                        </span>
                    ) : null}
                    {value ? (
                        <span className="font-sans text-xs text-muted-foreground">
                            Differente
                        </span>
                    ) : null}
                </MikrotikIndicator>
            ) : null}
        </div>
    );
}
