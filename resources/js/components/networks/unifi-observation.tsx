import { SourceAvatar } from '@/components/networks/source-avatar';
import {
    Tooltip,
    TooltipTrigger,
    TooltipContent,
} from '@/components/ui/tooltip';
import { useForm, usePage } from '@inertiajs/react';
import { CircleAlert, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { update } from '@/actions/App/Http/Controllers/UnifiIpamController';
import { MacConflictAlert } from '@/components/networks/mikrotik-source';
import {
    collectFacts,
    FactSheet,
    focusPanel,
} from '@/components/networks/source-facts';
import { UnifiMark } from '@/components/unifi-mark';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';

export type UnifiObservation = {
    id: number;
    kind: 'network' | 'client' | 'device' | 'reservation';
    state: 'current' | 'missing';
    seen_at: string;
    present_at?: string | null;
    presence?: 'online' | 'offline' | null;
    read_at?: string | null;
    source: string;
    ip: string | null;
    stale: boolean;
    problem: string | null;
    subnet_id: number | null;
    vlan_id: number | null;
    ip_address_id: number | null;
    decision: 'keep' | null;
    can_update: boolean;
    edit_mode?: 'planned' | 'manual' | null;
    matched_at?: string | null;
    local_values: {
        vlan_tag: number | null;
        vlan_name: string | null;
        subnet_vlan_tag: number | null;
    };
    data: {
        name?: string | null;
        management?: string | null;
        tag?: number | null;
        default?: boolean;
        cidr?: string | null;
        host_ip?: string | null;
        dhcp?: {
            mode?: string | null;
            start?: string | null;
            stop?: string | null;
            gateway?: string | null;
            dns?: string[] | null;
            servers?: string[] | null;
        } | null;
        mac?: string | null;
        hostname_observed?: string | null;
        type?: string | null;
        state?: string | null;
        switch?: {
            switch_mac?: string | null;
            switch_port?: number | null;
        } | null;
        dns?: string | null;
        alias?: string | null;
    };
};

export function UnifiObservationIndicator({
    observations,
    company,
    site,
    documented,
    children,
    avatar = false,
    showIndicator = true,
    macConflict,
}: {
    observations: UnifiObservation[];
    company: number;
    site: number;
    documented?: string | null;
    children?: ReactNode;
    avatar?: boolean;
    showIndicator?: boolean;
    macConflict?: string[];
}) {
    if (!observations.length) return null;
    const warning =
        Boolean(macConflict?.length) ||
        observations.some((observation) => observation.problem);
    return (
        <Sheet>
            <SheetTrigger asChild>
                {avatar ? (
                    <SourceAvatar
                        label={
                            warning
                                ? 'UniFi: differenze da verificare'
                                : 'Apri le osservazioni UniFi'
                        }
                        warning={warning}
                    >
                        <UnifiMark />
                    </SourceAvatar>
                ) : (
                    <Button
                        variant="ghost"
                        size={children ? 'sm' : 'icon'}
                        className={children ? 'h-auto min-h-8 py-1' : undefined}
                        onClick={(event) => event.stopPropagation()}
                        aria-label={
                            children ? undefined : 'Apri le osservazioni UniFi'
                        }
                    >
                        {!showIndicator ? null : observations.some(
                              (observation) => observation.problem,
                          ) ? (
                            <CircleAlert />
                        ) : (
                            <UnifiMark />
                        )}
                        {children}
                    </Button>
                )}
            </SheetTrigger>
            <SheetContent
                className="w-full overflow-y-auto sm:max-w-xl"
                aria-describedby={undefined}
                onOpenAutoFocus={focusPanel}
                onClick={(event) => event.stopPropagation()}
            >
                <SheetHeader>
                    <SheetTitle className="flex items-center gap-2">
                        <UnifiMark className="size-5" />
                        Rilevato da UniFi
                    </SheetTitle>
                </SheetHeader>
                <div className="flex flex-col gap-5 px-4 pb-6">
                    {documented ? (
                        <dl className="text-sm">
                            <dt className="text-muted-foreground">
                                Dato NetVibe
                            </dt>
                            <dd className="break-words">{documented}</dd>
                        </dl>
                    ) : null}
                    <MacConflictAlert macs={macConflict} />
                    {[
                        ...Map.groupBy(
                            observations,
                            (observation) => observation.source,
                        ).values(),
                    ].map((group) => (
                        <UnifiFacts
                            key={group[0].source}
                            observations={group}
                            company={company}
                            site={site}
                        />
                    ))}
                </div>
            </SheetContent>
        </Sheet>
    );
}

const connections: Record<string, string> = {
    WIRED: 'Cablata',
    WIRELESS: 'Wi-Fi',
};

const dhcpModes: Record<string, string> = {
    SERVER: 'Server attivo',
    RELAY: 'Relay',
    NONE: 'Disattivato',
};

const kindLabels: Record<UnifiObservation['kind'], string> = {
    network: 'Rete',
    client: 'Client',
    device: 'Dispositivo',
    reservation: 'Prenotazione',
};

const factsOrder = [
    'Nome',
    'Alias',
    'Nome DNS',
    'Rete',
    'VLAN',
    'Subnet',
    'IPv4',
    'IP configurato',
    'MAC',
    'Stato',
    'DHCP',
    'Intervallo DHCP',
    'Gateway DHCP',
    'DNS',
    'Server DHCP relay',
    'Connessione',
    'Porta switch',
];

/** One sheet per UniFi site: every distinct value once, actions kept per observation. */
function UnifiFacts({
    observations: all,
    company,
    site,
}: {
    observations: UnifiObservation[];
    company: number;
    site: number;
}) {
    // Observations UniFi no longer reports describe the past: show them only when nothing current exists.
    const current = all.filter(
        (observation) => observation.state !== 'missing',
    );
    const observations = current.length ? current : all;
    const { facts, add: addFact } = collectFacts();
    const add = (
        label: string,
        value: Parameters<typeof addFact>[1],
        observation: UnifiObservation,
        extra?: Parameters<typeof addFact>[3],
    ) => addFact(label, value, kindLabels[observation.kind], extra);

    for (const observation of observations) {
        const data = observation.data;
        add(
            'Nome',
            data.hostname_observed ??
                (observation.kind === 'network' ? null : data.name),
            observation,
            { copy: true },
        );
        add('Alias', data.alias, observation);
        add('Nome DNS', data.dns, observation, { copy: true });
        // A network's address is already shown as its configured IP.
        if (observation.kind !== 'network')
            add('IPv4', observation.ip, observation, { copy: true });
        add('MAC', data.mac?.toUpperCase(), observation, { copy: true });
        const presence =
            observation.presence ??
            (data.state === 'ONLINE'
                ? 'online'
                : data.state === 'OFFLINE'
                  ? 'offline'
                  : null);
        if (presence)
            add(
                'Stato',
                presence === 'online' ? 'Online' : 'Offline',
                observation,
                { tone: presence },
            );
        if (observation.kind === 'reservation')
            add('DHCP', 'Prenotazione IP fissa', observation);
        add('Connessione', connections[data.type ?? ''], observation);
        add('Porta switch', data.switch?.switch_port, observation);
        if (observation.kind === 'network') {
            add('Rete', data.name, observation);
            add(
                'VLAN',
                data.default ? 'Rete predefinita' : data.tag,
                observation,
            );
            add('Subnet', data.cidr, observation);
            add(
                'Gestione',
                data.management === 'GATEWAY' ? 'Gateway' : data.management,
                observation,
            );
            add('IP configurato', data.host_ip, observation);
            add(
                'DHCP',
                data.dhcp?.mode
                    ? (dhcpModes[data.dhcp.mode] ?? data.dhcp.mode)
                    : null,
                observation,
            );
            add(
                'Intervallo DHCP',
                data.dhcp?.start && data.dhcp.stop
                    ? `${data.dhcp.start} – ${data.dhcp.stop}`
                    : null,
                observation,
            );
            add('Gateway DHCP', data.dhcp?.gateway, observation);
            add('DNS', data.dhcp?.dns?.join(', '), observation);
            add(
                'Server DHCP relay',
                data.dhcp?.servers?.join(', '),
                observation,
            );
        }
    }
    const readAt = observations
        .map((observation) => observation.read_at ?? observation.present_at)
        .filter((value): value is string => Boolean(value))
        .sort()
        .at(-1);
    // Only states that change what the technician can trust or what the next sync will do.
    const flags = [
        ...new Set(
            observations.flatMap((observation) =>
                [
                    observation.edit_mode === 'manual'
                        ? 'Valore manuale mantenuto'
                        : observation.edit_mode
                          ? observation.matched_at
                              ? 'Modifica prevista riscontrata'
                              : 'Modifica prevista'
                          : null,
                    observation.decision === 'keep'
                        ? 'Dato NetVibe mantenuto'
                        : null,
                ].filter((flag): flag is string => Boolean(flag)),
            ),
        ),
    ];

    return (
        <FactSheet
            mark={<UnifiMark />}
            title={
                <span className="min-w-0 truncate font-medium">
                    {/* The UniFi mark already names the system. */}
                    {observations[0].source.replace(/^UniFi · /, '')}
                </span>
            }
            flags={flags}
            readAt={readAt}
            stale={observations.every((observation) => observation.stale)}
            facts={facts}
            order={factsOrder}
        >
            {observations
                .filter(
                    (observation) =>
                        observation.problem || observation.can_update,
                )
                .map((observation) => (
                    <ObservationReview
                        key={observation.id}
                        observation={observation}
                        company={company}
                        site={site}
                    />
                ))}
        </FactSheet>
    );
}

/** The VLAN difference with its update/keep decision, for one observation. */
function ObservationReview({
    observation,
    company,
    site,
}: {
    observation: UnifiObservation;
    company: number;
    site: number;
}) {
    const { auth } = usePage().props;
    const form = useForm({ decision: 'keep' });
    const data = observation.data;
    const differs =
        observation.problem === 'Nome VLAN diverso dalla fonte.' ||
        observation.problem === 'VLAN documentata diversa dalla fonte.';
    const submit = (decision: 'update' | 'keep') => {
        form.transform(() => ({ decision }));
        form.submit(update({ company, site, observation: observation.id }), {
            preserveScroll: true,
        });
    };
    return (
        <div className="flex flex-col gap-3 border-t px-4 py-3">
            {differs ? (
                <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-1">
                    <dt className="text-muted-foreground">Dato NetVibe</dt>
                    <dd>
                        {observation.problem ===
                        'Nome VLAN diverso dalla fonte.'
                            ? (observation.local_values.vlan_name ?? '—')
                            : observation.local_values.subnet_vlan_tag === null
                              ? 'Senza VLAN'
                              : `VLAN ${observation.local_values.subnet_vlan_tag}`}
                    </dd>
                    <dt className="text-muted-foreground">Rilevato da UniFi</dt>
                    <dd>
                        {observation.problem ===
                        'Nome VLAN diverso dalla fonte.'
                            ? (data.name ?? '—')
                            : data.tag === null || data.tag === undefined
                              ? 'Senza VLAN'
                              : `VLAN ${data.tag}`}
                    </dd>
                </dl>
            ) : observation.problem ? (
                <Alert>
                    <TriangleAlert />
                    <AlertDescription>{observation.problem}</AlertDescription>
                </Alert>
            ) : null}
            {auth.canEditIpam && observation.can_update ? (
                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        disabled={form.processing || observation.stale}
                        onClick={() => submit('update')}
                    >
                        Aggiorna
                    </Button>
                    {observation.decision !== 'keep' ? (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={form.processing}
                            onClick={() => submit('keep')}
                        >
                            Mantieni
                        </Button>
                    ) : null}
                </div>
            ) : null}
            {form.hasErrors ? (
                <Alert variant="destructive">
                    <AlertDescription>
                        {Object.values(form.errors).join(' ')}
                    </AlertDescription>
                </Alert>
            ) : null}
        </div>
    );
}

export function UnifiObservedValue({
    observations,
    company,
    site,
    value,
    field,
    showIndicator = true,
}: {
    observations: UnifiObservation[];
    company: number;
    site: number;
    value: string | null;
    field: 'mac' | 'hostname_observed';
    showIndicator?: boolean;
}) {
    const relevant = observations.filter(
        (observation) => observation.data[field],
    );
    const values = [
        ...new Set(
            relevant
                .filter((observation) => observation.state === 'current')
                .map((observation) => observation.data[field]!)
                .filter(
                    (observed) =>
                        observed.toLowerCase().replaceAll('-', ':') !==
                        value?.toLowerCase().replaceAll('-', ':'),
                ),
        ),
    ];
    if (!values.length) return null;
    const warning = `${field === 'mac' ? 'MAC' : 'Hostname'} discordanti per lo stesso IP`;
    return (
        <UnifiObservationIndicator
            observations={relevant}
            company={company}
            site={site}
            documented={value}
            showIndicator={showIndicator}
        >
            <span className="flex flex-col items-start gap-1">
                {values.map((observed) => (
                    <Tooltip key={observed}>
                        <TooltipTrigger asChild>
                            <span className="max-w-72 truncate">
                                {observed}
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>{observed}</TooltipContent>
                    </Tooltip>
                ))}
            </span>
            {values.length > 1 ? (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span>
                            <TriangleAlert
                                data-icon="inline-end"
                                aria-hidden="true"
                            />
                            <span className="sr-only">{warning}</span>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent>{warning}</TooltipContent>
                </Tooltip>
            ) : null}
            {value ? (
                <span className="text-xs text-muted-foreground">
                    Differente
                </span>
            ) : null}
            {relevant.every((observation) => observation.stale) ? (
                <span className="text-xs text-muted-foreground">
                    Non aggiornato
                </span>
            ) : null}
        </UnifiObservationIndicator>
    );
}
