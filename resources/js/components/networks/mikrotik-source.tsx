import { SourceAvatar } from '@/components/networks/source-avatar';
import {
    collectFacts,
    FactSheet,
    focusPanel,
    type FactTone,
} from '@/components/networks/source-facts';
import { usePage } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { show as deviceShow } from '@/routes/companies/sites/devices';
import {
    Tooltip,
    TooltipTrigger,
    TooltipContent,
} from '@/components/ui/tooltip';
import { useState, type ReactNode } from 'react';
import { TriangleAlert } from 'lucide-react';
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
import { Button } from '@/components/ui/button';
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

// ARP states translated into what a technician needs: is the host online right now?
const reachability: Record<string, [string, FactTone | undefined]> = {
    reachable: ['Online', 'online'],
    stale: ['Visto di recente', 'idle'],
    delay: ['Visto di recente', 'idle'],
    probe: ['In verifica', 'idle'],
    failed: ['Offline', 'offline'],
    incomplete: ['Offline', 'offline'],
    permanent: ['Voce ARP statica', undefined],
};

const leaseStates: Record<string, string> = {
    bound: 'in uso',
    waiting: 'non in uso',
    offered: 'offerta in corso',
    busy: 'indirizzo occupato',
};

const factsOrder = ['Hostname', 'IPv4', 'MAC', 'Stato', 'DHCP', 'Server DHCP'];

function isDhcpObservation(source: MikrotikSource) {
    return (
        source.kind === 'observation' &&
        source.data.menu === '/ip/dhcp-server/lease'
    );
}

// Only states that change what the technician can trust or what the next sync will do.
function sourceFlags(source: MikrotikSource) {
    return [
        source.exclusion
            ? source.exclusion.kind === 'source'
                ? 'Ignorata nelle sincronizzazioni'
                : `Esclusa · ${source.exclusion.label}`
            : null,
        source.edit_mode === 'manual'
            ? 'Valore manuale mantenuto'
            : source.edit_mode
              ? source.matched_at
                  ? 'Modifica prevista riscontrata'
                  : 'Modifica prevista'
              : null,
    ].filter((flag): flag is string => Boolean(flag));
}

/** One sheet per router: every distinct value once, with the records it came from. */
function RouterFacts({ sources: all }: { sources: MikrotikSource[] }) {
    // Records the router no longer has describe the past: show them only when nothing current exists.
    const current = all.filter((source) => source.state !== 'missing');
    const sources = current.length ? current : all;
    const { companyNavigation } = usePage().props;
    const companyId = companyNavigation?.currentCompanyId;
    const { facts, add: addFact } = collectFacts();
    const add = (
        label: string,
        value: Parameters<typeof addFact>[1],
        source: MikrotikSource,
        extra?: Parameters<typeof addFact>[3],
    ) => addFact(label, value, sourceLabel(source), extra);

    const leases = sources.filter(
        (source) => source.kind === 'lease' || isDhcpObservation(source),
    );
    for (const source of sources) {
        const data = source.data;
        add('Hostname', data.hostname_observed, source, { copy: true });
        add('IPv4', source.ip, source, { copy: true });
        add('MAC', (data.mac ?? data.mac_address)?.toUpperCase(), source, {
            copy: true,
        });
        if (data.menu === '/ip/arp' && data.status) {
            const [label, tone] = reachability[data.status] ?? [data.status];
            add('Stato', label, source, {
                tone,
                hint: `Stato ARP RouterOS: ${data.status}`,
            });
        } else if (source.presence === 'online')
            add('Stato', 'Online', source, { tone: 'online' });
        const iface = data.interface ?? data['on-interface'];
        add('Interfaccia', iface, source);
        if (data.context !== iface)
            add('Interfaccia di base', data.context, source);
        add('Porta', data.default_name, source);
        add('Subnet', data.cidr, source);
        add('Indirizzo', data.address, source);
        add('Bridge', data.bridge, source);
        add('Lista', data.list, source);
        add('VLAN', data.tag, source);
        add('Oggetto', data.name, source);
        add('Tagged', data.tagged, source);
        add('Untagged', data.untagged, source);
        add('Tagged attuali', data['current-tagged'], source);
        add('Untagged attuali', data['current-untagged'], source);
    }
    if (leases.length) {
        const reservation = leases.find((source) => source.kind === 'lease');
        const status =
            leases.find((source) => source.data.status === 'bound')?.data
                .status ??
            leases.find((source) => source.data.status)?.data.status;
        const type = reservation
            ? reservation.data.fixed
                ? 'Prenotazione fissa'
                : 'Lease senza IPv4 fisso'
            : 'Lease dinamica';
        add(
            'DHCP',
            status ? `${type}, ${leaseStates[status] ?? status}` : type,
            leases[0],
        );
        for (const lease of leases)
            add('Server DHCP', lease.data.server, lease);
    }
    // The hostname still identifies the device after its lease is gone.
    if (!facts.has('Hostname'))
        for (const source of all)
            add('Hostname', source.data.hostname_observed, source, {
                copy: true,
            });
    const hostnames = [...(facts.get('Hostname')?.keys() ?? [])].map((value) =>
        value.toLowerCase(),
    );
    for (const source of sources)
        if (
            source.data.comment &&
            !hostnames.includes(source.data.comment.toLowerCase())
        )
            add('Commento', source.data.comment, source);

    const readAt = sources
        .map((source) => source.read_at ?? source.present_at)
        .filter((value): value is string => Boolean(value))
        .sort()
        .at(-1);
    const notes = [
        ...new Set(
            sources.flatMap((source) =>
                [source.problem, source.import_note].filter(
                    (note): note is string => Boolean(note),
                ),
            ),
        ),
    ];
    const first = sources[0];
    const flags = [...new Set(sources.flatMap(sourceFlags))];
    const stale = sources.every((source) => source.stale);

    return (
        <FactSheet
            mark={<MikrotikMark />}
            title={
                companyId ? (
                    <TextLink
                        href={deviceShow({
                            company: companyId,
                            site: first.site_id,
                            device: first.device_id,
                        })}
                        className="min-w-0 truncate font-medium"
                    >
                        {first.source}
                    </TextLink>
                ) : (
                    <span className="min-w-0 truncate font-medium">
                        {first.source}
                    </span>
                )
            }
            flags={flags}
            readAt={readAt}
            stale={stale}
            facts={facts}
            order={factsOrder}
        >
            {notes.map((note) => (
                <Alert key={note} className="mx-4 mb-3 w-auto">
                    <TriangleAlert />
                    <AlertDescription>
                        <p>{note}</p>
                    </AlertDescription>
                </Alert>
            ))}
        </FactSheet>
    );
}

export function SourceDetails({ sources }: { sources: MikrotikSource[] }) {
    const routers = new Map<number, MikrotikSource[]>();
    for (const source of sources)
        routers.set(source.device_id, [
            ...(routers.get(source.device_id) ?? []),
            source,
        ]);
    return (
        <div className="flex flex-col gap-3">
            {[...routers.values()].map((routerSources) => (
                <RouterFacts
                    key={routerSources[0].device_id}
                    sources={routerSources}
                />
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
            <DialogContent
                className="sm:max-w-xl"
                aria-describedby={undefined}
                onOpenAutoFocus={focusPanel}
            >
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
                onOpenAutoFocus={focusPanel}
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
