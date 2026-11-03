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
import { UnifiMark } from '@/components/unifi-mark';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Separator } from '@/components/ui/separator';
import { formatDateTime } from '@/lib/utils';

export type UnifiObservation = {
    id: number;
    kind: 'network' | 'client' | 'device';
    state: 'current' | 'missing';
    seen_at: string;
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
}: {
    observations: UnifiObservation[];
    company: number;
    site: number;
    documented?: string | null;
    children?: ReactNode;
    avatar?: boolean;
    showIndicator?: boolean;
}) {
    if (!observations.length) return null;
    return (
        <Sheet>
            <SheetTrigger asChild>
                {avatar ? (
                    <SourceAvatar
                        label={
                            observations.some(
                                (observation) => observation.problem,
                            )
                                ? 'UniFi: differenze da verificare'
                                : 'Apri le osservazioni UniFi'
                        }
                        warning={observations.some(
                            (observation) => observation.problem,
                        )}
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
                    {observations.map((observation, index) => (
                        <div
                            key={observation.id}
                            className="flex flex-col gap-5"
                        >
                            {index > 0 ? <Separator /> : null}
                            <ObservationDetails
                                observation={observation}
                                company={company}
                                site={site}
                            />
                        </div>
                    ))}
                </div>
            </SheetContent>
        </Sheet>
    );
}

function ObservationDetails({
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
    const title =
        observation.kind === 'network'
            ? (data.name ?? data.cidr ?? 'Rete')
            : (data.hostname_observed ?? observation.ip ?? 'Osservazione');
    const facts = [
        ['IPv4', observation.ip],
        ['MAC', data.mac],
        ['Nome', data.hostname_observed],
        ['Tipo', data.type],
        ['Gestione', data.management],
        ['VLAN', data.default ? 'Rete predefinita' : data.tag],
        ['Subnet', data.cidr],
        ['IP configurato', data.host_ip],
        ['DHCP', data.dhcp?.mode],
        [
            'Intervallo DHCP',
            data.dhcp?.start && data.dhcp.stop
                ? `${data.dhcp.start} – ${data.dhcp.stop}`
                : null,
        ],
        ['Gateway DHCP', data.dhcp?.gateway],
        ['DNS', data.dhcp?.dns?.join(', ')],
        ['Server DHCP relay', data.dhcp?.servers?.join(', ')],
    ];
    return (
        <section className="flex flex-col gap-3 text-sm">
            <header className="flex flex-wrap items-center gap-2">
                <h3 className="font-medium break-words">{title}</h3>
                {observation.state === 'missing' ? (
                    <Badge variant="outline">Non osservato</Badge>
                ) : observation.stale ? (
                    <Badge variant="outline">Non aggiornato</Badge>
                ) : null}
            </header>
            <dl className="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-x-4 gap-y-2">
                {facts
                    .filter(
                        ([, value]) =>
                            value !== undefined &&
                            value !== null &&
                            value !== '',
                    )
                    .map(([label, value]) => (
                        <div key={label} className="contents">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="break-words">{value}</dd>
                        </div>
                    ))}
                <dt className="text-muted-foreground">Fonte</dt>
                <dd className="break-words">{observation.source}</dd>
                <dt className="text-muted-foreground">Ultima lettura</dt>
                <dd>{formatDateTime(observation.seen_at)}</dd>
            </dl>
            {differs ? (
                <dl className="grid grid-cols-2 gap-2 rounded-lg border p-3">
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
            ) : null}
            {observation.edit_mode ? (
                <p className="text-xs text-muted-foreground">
                    {observation.edit_mode === 'manual'
                        ? 'Valore manuale mantenuto'
                        : observation.matched_at
                          ? 'Modifica prevista riscontrata'
                          : 'Modifica prevista'}
                </p>
            ) : null}
            {observation.problem ? (
                <Alert>
                    <AlertDescription>{observation.problem}</AlertDescription>
                </Alert>
            ) : null}
            {observation.decision === 'keep' ? (
                <p className="text-xs text-muted-foreground">
                    Dato NetVibe mantenuto
                </p>
            ) : null}
            {auth.canEditIpam && observation.can_update ? (
                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        disabled={form.processing || observation.stale}
                        onClick={() => {
                            form.transform(() => ({ decision: 'update' }));
                            form.submit(
                                update({
                                    company,
                                    site,
                                    observation: observation.id,
                                }),
                                {
                                    preserveScroll: true,
                                },
                            );
                        }}
                    >
                        Aggiorna
                    </Button>
                    {observation.decision !== 'keep' ? (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={form.processing}
                            onClick={() => {
                                form.transform(() => ({ decision: 'keep' }));
                                form.submit(
                                    update({
                                        company,
                                        site,
                                        observation: observation.id,
                                    }),
                                    { preserveScroll: true },
                                );
                            }}
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
        </section>
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
