import { SourceAvatar } from '@/components/networks/source-avatar';
import { usePage } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { show as deviceShow } from '@/routes/companies/sites/devices';
import {
    Tooltip,
    TooltipTrigger,
    TooltipContent,
} from '@/components/ui/tooltip';
import { formatDateTime } from '@/lib/utils';
import { useState, type ReactNode } from 'react';
import { ChevronDown, TriangleAlert } from 'lucide-react';
import { MikrotikMark } from '@/components/mikrotik-mark';
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
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Separator } from '@/components/ui/separator';
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
    device_id: number;
    site_id: number;
    stale: boolean;
    seen_at: string;
    present_at?: string | null;
    presence?: 'online' | null;
    read_at?: string | null;
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

const statusLabels: Record<string, string> = {
    bound: 'Lease attiva',
    waiting: 'In attesa',
    offered: 'Offerta',
    busy: 'Occupato',
    reachable: 'Raggiungibile',
    stale: 'Stale',
    delay: 'Delay',
    probe: 'Probe',
    permanent: 'Statico',
    failed: 'Non risolto',
    incomplete: 'Incompleto',
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
        if (source.data.menu === '/ip/arp') return 'Tabella ARP';
        if (source.data.menu === '/interface/bridge/host')
            return 'Osservazione MAC';
        if (source.data.menu === '/ip/neighbor') return 'Neighbor';
        return 'fixed' in source.data
            ? 'Lease DHCP dinamica'
            : 'Lease DHCP attiva';
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
    const { companyNavigation } = usePage().props;
    const companyId = companyNavigation?.currentCompanyId;
    return (
        <div className="flex flex-col gap-3">
            {sources.map((source) => (
                <Card key={source.id} className="gap-5 py-5">
                    <CardHeader className="gap-3 px-5">
                        <CardTitle>{sourceLabel(source)}</CardTitle>
                        <div className="flex flex-wrap gap-2">
                            {source.presence === 'online' ? (
                                <Badge variant="secondary">Online</Badge>
                            ) : null}
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
                    <CardContent className="flex flex-col gap-5 px-5 text-sm">
                        {source.data.hostname_observed ? (
                            <dl>
                                <dt className="mb-1 text-muted-foreground">
                                    Hostname riportato
                                </dt>
                                <dd className="text-xl font-semibold break-words">
                                    {source.data.hostname_observed}
                                </dd>
                            </dl>
                        ) : null}
                        <dl className="grid gap-4 sm:grid-cols-2">
                            {Object.entries({
                                IPv4: source.ip,
                                MAC: source.data.mac ?? source.data.mac_address,
                                Stato: source.data.status
                                    ? (statusLabels[source.data.status] ??
                                      source.data.status)
                                    : null,
                            })
                                .filter(([, value]) => value)
                                .map(([label, value]) => (
                                    <div key={label} className="min-w-0">
                                        <dt className="text-muted-foreground">
                                            {label}
                                        </dt>
                                        <dd className="-ml-2 tabular-nums">
                                            <CopyValue value={value!} />
                                        </dd>
                                    </div>
                                ))}
                        </dl>
                        {source.data.comment ? (
                            <dl>
                                <dt className="mb-1 text-muted-foreground">
                                    Commento in MikroTik
                                </dt>
                                <dd className="break-words whitespace-pre-wrap">
                                    {source.data.comment}
                                </dd>
                            </dl>
                        ) : null}
                        <Separator />
                        <dl className="grid gap-4 sm:grid-cols-2">
                            <div className="min-w-0">
                                <dt className="mb-1 text-muted-foreground">
                                    Letto da
                                </dt>
                                <dd className="flex items-start gap-2">
                                    <MikrotikMark />
                                    {companyId ? (
                                        <TextLink
                                            href={deviceShow({
                                                company: companyId,
                                                site: source.site_id,
                                                device: source.device_id,
                                            })}
                                            className="min-w-0 break-words"
                                        >
                                            {source.source}
                                        </TextLink>
                                    ) : (
                                        <span className="min-w-0 break-words">
                                            {source.source}
                                        </span>
                                    )}
                                </dd>
                            </div>
                            {source.present_at ? (
                                <div>
                                    <dt className="mb-1 text-muted-foreground">
                                        Ultimo avvistamento
                                    </dt>
                                    <dd>
                                        <time dateTime={source.present_at}>
                                            {formatDateTime(source.present_at)}
                                        </time>
                                    </dd>
                                </div>
                            ) : null}
                            <div>
                                <dt className="mb-1 text-muted-foreground">
                                    Ultima lettura riuscita
                                </dt>
                                <dd>
                                    {source.read_at ? (
                                        <time dateTime={source.read_at}>
                                            {formatDateTime(source.read_at)}
                                        </time>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                        </dl>
                        <Collapsible>
                            <CollapsibleTrigger asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="w-full justify-between [&[data-state=open]>svg]:rotate-180"
                                >
                                    Dettagli di rete
                                    <ChevronDown data-icon="inline-end" />
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <dl className="grid gap-4 pt-4 sm:grid-cols-2">
                                    {Object.entries({
                                        'Server DHCP': source.data.server,
                                        'Porta RouterOS':
                                            source.data.default_name,
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
                                        Tagged: source.data.tagged,
                                        Untagged: source.data.untagged,
                                        'Tagged attuali':
                                            source.data['current-tagged'],
                                        'Untagged attuali':
                                            source.data['current-untagged'],
                                        'Menu RouterOS': source.data.menu,
                                    })
                                        .filter(
                                            ([, value]) =>
                                                value !== undefined &&
                                                value !== null &&
                                                value !== '',
                                        )
                                        .map(([label, value]) => (
                                            <div
                                                key={label}
                                                className="min-w-0"
                                            >
                                                <dt className="mb-1 text-muted-foreground">
                                                    {label}
                                                </dt>
                                                <dd className="break-words whitespace-pre-wrap">
                                                    {String(value)}
                                                </dd>
                                            </div>
                                        ))}
                                </dl>
                            </CollapsibleContent>
                        </Collapsible>
                        {source.import_note ? (
                            <p className="text-muted-foreground">
                                {source.import_note}
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

export function MacConflictAlert({ macs }: { macs?: string[] }) {
    if (!macs?.length) return null;
    return (
        <Alert>
            <TriangleAlert />
            <AlertDescription>
                <p>MAC differenti tra le fonti: {macs.join(', ')}</p>
            </AlertDescription>
        </Alert>
    );
}

export function MikrotikIndicator({
    sources,
    mac,
    field = 'ip',
    children,
    avatar = false,
    macConflict,
}: {
    sources: MikrotikSource[];
    mac?: string | null;
    field?: 'ip' | 'mac' | 'hostname';
    children?: ReactNode;
    avatar?: boolean;
    macConflict?: string[];
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
        Boolean(macConflict?.length) ||
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
                {avatar ? (
                    <SourceAvatar
                        label={
                            mismatch
                                ? 'MikroTik: differenze da verificare'
                                : 'Apri le fonti MikroTik'
                        }
                        warning={Boolean(mismatch)}
                    >
                        <MikrotikMark />
                    </SourceAvatar>
                ) : (
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
                )}
            </SheetTrigger>
            <SheetContent
                className="w-full gap-5 overflow-y-auto sm:max-w-xl"
                aria-describedby={undefined}
                onClick={(event) => event.stopPropagation()}
            >
                <SheetHeader className="px-5 pt-6 pr-12">
                    <SheetTitle className="flex items-center gap-2.5">
                        <MikrotikMark variant="solid" className="h-5" />
                        {field === 'mac'
                            ? 'MAC rilevato da MikroTik'
                            : field === 'hostname'
                              ? 'Hostname riportato da MikroTik'
                              : 'Rilevamenti'}
                    </SheetTitle>
                </SheetHeader>
                <div className="flex flex-col gap-4 px-5 pb-6">
                    {field === 'mac' && mac ? (
                        <dl className="rounded-lg border p-4 text-sm">
                            <dt className="mb-1 text-muted-foreground">
                                MAC documentato in NetVibe
                            </dt>
                            <dd className="tabular-nums">{mac}</dd>
                        </dl>
                    ) : null}
                    <MacConflictAlert macs={macConflict} />
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
                    className={`${showIndicator ? 'ml-10 ' : ''}h-8 max-w-96 py-1 whitespace-nowrap [&>span]:truncate`}
                />
            ) : null}
            {values.length ? (
                <MikrotikIndicator
                    sources={current}
                    field={field}
                    mac={field === 'mac' ? value : undefined}
                >
                    {showIndicator ? (
                        <span className="flex w-8 shrink-0 items-center justify-center">
                            <MikrotikMark />
                        </span>
                    ) : null}
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span className="max-w-96 truncate">
                                {values.join(', ')}
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>{values.join(', ')}</TooltipContent>
                    </Tooltip>
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
