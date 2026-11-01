import { formatDateTime } from '@/lib/utils';
import { useState, type ReactNode } from 'react';
import { Info, TriangleAlert } from 'lucide-react';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { IconButton } from '@/components/icon-button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { CopyValue } from '@/components/copy-value';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
    excluded_at?: string | null;
    exclusion?: {
        kind: 'subnet' | 'vlan' | 'source';
        id: number;
        label: string;
        device?: number;
    } | null;
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
    import_note?: string | null;
    subnet_id: number | null;
    vlan_id: number | null;
    ip_address_id: number | null;
    data: {
        menu: string;
        address?: string | null;
        bridge?: string | null;
        list?: string | null;
        comment?: string | null;
        name?: string | null;
        tag?: number | null;
        cidr?: string | null;
        context?: string | null;
        interface?: string | null;
        mac?: string | null;
        mac_address?: string | null;
        default_name?: string | null;
        server?: string | null;
        fixed?: boolean;
        active?: boolean;
        status?: string | null;
        hostname_observed?: string | null;
        dynamic?: string | boolean | number;
        disabled?: string | boolean;
        invalid?: string | boolean;
        tagged?: string;
        untagged?: string;
        'current-tagged'?: string;
        'current-untagged'?: string;
        'on-interface'?: string;
    };
};

export function sourceIsDynamic(source: MikrotikSource) {
    return [true, 'true', 'yes', 1, '1'].includes(source.data.dynamic ?? false);
}

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
    if (source.kind === 'context') {
        const menus: Record<string, string> = {
            '/interface': 'Interfaccia RouterOS',
            '/interface/ethernet': 'Porta fisica RouterOS',
            '/interface/bridge': 'Bridge',
            '/interface/bridge/port': 'Porta del bridge',
            '/interface/bridge/vlan': 'VLAN dinamica del bridge',
            '/interface/list': 'Lista di interfacce',
            '/interface/list/member': 'Membro di lista',
            '/ip/dhcp-server': 'Server DHCP',
            '/ip/dhcp-server/network': 'Rete DHCP',
            '/ip/pool': 'Pool IP',
        };
        return menus[source.data.menu] ?? source.data.menu;
    }
    const labels: Record<string, string> = {
        vlan: 'VLAN',
        subnet: 'Subnet',
        address: 'Indirizzo del dispositivo',
        interface: 'Porta fisica',
    };
    return labels[source.kind] ?? source.kind;
}

export function SourceDetails({ sources }: { sources: MikrotikSource[] }) {
    return (
        <div className="flex flex-col gap-3">
            {sources.map((source) => (
                <Card key={source.id} className="gap-3 py-4">
                    <CardHeader className="gap-2 px-4">
                        <div className="flex items-start justify-between gap-2">
                            <CardTitle className="flex min-w-0 items-start gap-2.5">
                                <MikrotikMark />
                                <span className="min-w-0 break-words">
                                    {source.source} · {sourceLabel(source)}
                                </span>
                            </CardTitle>
                            <IconButton
                                variant="ghost"
                                className="-mt-2 -mr-2 shrink-0"
                                label={`Ultimo rilevamento: ${new Date(source.seen_at).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' })}`}
                            >
                                <Info />
                            </IconButton>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {sourceIsDynamic(source) ? (
                                <Badge variant="secondary">Dinamico</Badge>
                            ) : null}
                            {source.exclusion ? (
                                <Badge variant="outline">
                                    {source.exclusion.kind === 'source'
                                        ? 'Ignorata nelle sincronizzazioni'
                                        : `Esclusa · ${source.exclusion.label}`}
                                </Badge>
                            ) : null}
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
                        </div>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3 px-4 text-sm">
                        <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2">
                            {Object.entries({
                                IPv4: source.ip,
                                MAC: source.data.mac ?? source.data.mac_address,
                                'Porta RouterOS': source.data.default_name,
                                Subnet: source.data.cidr,
                                Indirizzo: source.data.address,
                                Bridge: source.data.bridge,
                                Lista: source.data.list,
                                VLAN: source.data.tag,
                                Oggetto: source.data.name,
                                'Interfaccia di base':
                                    source.data.context !==
                                    (source.data.interface ??
                                        source.data['on-interface'])
                                        ? source.data.context
                                        : null,
                                'Interfaccia RouterOS':
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
                                'Menu RouterOS': source.data.menu,
                                'Ultimo rilevamento': formatDateTime(
                                    source.seen_at,
                                ),
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
                        {source.import_note ? (
                            <p className="text-muted-foreground">
                                {source.import_note}
                            </p>
                        ) : source.kind === 'observation' &&
                          sourceIsDynamic(source) ? (
                            <p className="text-muted-foreground">
                                Osservazione dinamica: non crea un record IPAM.
                            </p>
                        ) : null}
                        {source.problem ? (
                            <Alert>
                                <TriangleAlert />
                                <AlertDescription>
                                    <p>{source.problem}</p>
                                </AlertDescription>
                            </Alert>
                        ) : null}
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

export function MikrotikSourcesDialog({
    sources,
}: {
    sources: MikrotikSource[];
}) {
    if (!sources.length) return null;
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button type="button" variant="ghost" size="sm">
                    <MikrotikMark />
                    Fonti MikroTik
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-xl" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>Fonti MikroTik</DialogTitle>
                </DialogHeader>
                <SourceDetails sources={sources} />
            </DialogContent>
        </Dialog>
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
                            ? 'h-8 max-w-full min-w-0 gap-2 px-2 py-1 font-normal hover:bg-transparent'
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
                            <MikrotikMark />
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
    showIndicator = true,
}: {
    value: string | null;
    field: 'mac' | 'hostname';
    sources: MikrotikSource[];
    showIndicator?: boolean;
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
        <div className="inline-flex max-w-full flex-col items-start gap-1 whitespace-nowrap">
            {value ? (
                <CopyValue
                    value={value}
                    className="ml-10 h-8 max-w-96 py-1 whitespace-nowrap [&>span]:truncate"
                />
            ) : null}
            {values.length ? (
                <MikrotikIndicator
                    sources={current}
                    field={field}
                    mac={field === 'mac' ? value : undefined}
                >
                    <span className="flex w-8 shrink-0 items-center justify-center">
                        {showIndicator ? <MikrotikMark /> : null}
                    </span>
                    <span
                        className="max-w-96 truncate"
                        title={values.join(', ')}
                    >
                        {values.join(', ')}
                    </span>
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
