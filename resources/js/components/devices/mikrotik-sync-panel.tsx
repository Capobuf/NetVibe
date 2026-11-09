import { cn, formatDateTime } from '@/lib/utils';
import {
    NetworkExclusionAction,
    NetworkVisibilityFilter,
} from '@/components/networks/network-exclusion';
import { useForm, usePage, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { ArrowLeft, Cable, RefreshCw } from 'lucide-react';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { MikrotikSourcesLoader } from '@/components/devices/mikrotik-sources-loader';
import type { MikrotikConnection } from '@/components/devices/mikrotik-device';
import { DataTable } from '@/components/data-table';
import {
    SourceDetails,
    sourceLabel,
    sourceIsDynamic,
    type MikrotikSource,
} from '@/components/networks/mikrotik-source';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Spinner } from '@/components/ui/spinner';
import {
    activate,
    sync,
    associate,
} from '@/routes/companies/sites/devices/mikrotik';
import { show as subnetShow } from '@/routes/companies/sites/subnets';
import { show as vlanShow } from '@/routes/companies/sites/vlans';

type Source = MikrotikSource & { can_associate: boolean };
export function MikrotikSyncPanel({
    connection,
    disabled,
    route,
    onVerify,
    verifying,
}: {
    connection: MikrotikConnection | null;
    disabled: boolean;
    onVerify: () => void;
    verifying: boolean;
    route: { company: number; site: number; device: number };
}) {
    const { mikrotikSources } = usePage<{
        mikrotikSources?: Source[];
    }>().props;
    const sources = mikrotikSources ?? [];
    const [allOpen, setAllOpen] = useState(false);
    const detailTitle = useRef<HTMLHeadingElement>(null);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const selected = sources.find((source) => source.id === selectedId) ?? null;
    const [confirmed, setConfirmed] = useState(false);
    const form = useForm({
        revision: connection?.revision ?? null,
        confirmed: true,
        source_id: 0,
    });
    const problems = sources.filter(
        (source) =>
            source.problem && source.state !== 'missing' && !source.exclusion,
    );
    const active = Boolean(connection?.activated_at);
    const verified = connection?.check_status === 'ok';
    const busy = disabled || form.processing || mikrotikSources === undefined;
    const select = (source: Source) => {
        setSelectedId(source.id);
        setConfirmed(false);
        form.clearErrors();
        requestAnimationFrame(() => detailTitle.current?.focus());
    };
    const backToList = () => {
        setSelectedId(null);
        requestAnimationFrame(() =>
            document
                .getElementById(`row-mikrotik-sources-${selectedId}`)
                ?.focus(),
        );
    };
    return (
        <>
            <MikrotikSourcesLoader />
            {connection ? (
                <Card aria-busy={verifying || form.processing}>
                    <CardHeader className="flex-row flex-wrap items-center justify-between gap-4">
                        <div className="flex flex-wrap items-center gap-3">
                            <CardTitle className="flex items-center gap-2.5">
                                <MikrotikMark className="h-5" />
                                Sincronizzazione IPAM
                            </CardTitle>
                            <Badge variant={active ? 'secondary' : 'outline'}>
                                {active
                                    ? 'Attiva · ogni ora'
                                    : connection?.sync_status === 'suspended' ||
                                        connection?.sync_status ===
                                            'source_changed'
                                      ? 'Sospesa'
                                      : 'Non attiva'}
                            </Badge>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {sources.length ? (
                                <Button
                                    variant="outline"
                                    onClick={() => setAllOpen(true)}
                                >
                                    Dati acquisiti
                                </Button>
                            ) : null}
                            <Button
                                disabled={busy}
                                onClick={() => {
                                    if (!verified) {
                                        onVerify();
                                        return;
                                    }
                                    form.submit(
                                        active ? sync(route) : activate(route),
                                        {
                                            preserveScroll: true,
                                            onError: (errors) =>
                                                toast.error(
                                                    Object.values(errors)[0],
                                                ),
                                        },
                                    );
                                }}
                            >
                                {verifying || form.processing ? (
                                    <Spinner />
                                ) : verified ? (
                                    <RefreshCw data-icon="inline-start" />
                                ) : (
                                    <Cable data-icon="inline-start" />
                                )}
                                {verifying
                                    ? 'Verifica in corso…'
                                    : form.processing
                                      ? 'Sincronizzazione in corso…'
                                      : verified
                                        ? 'Sincronizza ora'
                                        : 'Verifica connessione'}
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent
                        className="flex flex-col gap-4"
                        aria-live="polite"
                    >
                        <dl className="flex flex-wrap gap-x-10 gap-y-3 text-sm">
                            {connection?.synced_at ? (
                                <div className="flex flex-col gap-1">
                                    <dt className="text-muted-foreground">
                                        Ultimo aggiornamento riuscito
                                    </dt>
                                    <dd className="tabular-nums">
                                        {formatDateTime(connection.synced_at)}
                                    </dd>
                                </div>
                            ) : null}
                            {connection?.sync_attempted_at ? (
                                <div className="flex flex-col gap-1">
                                    <dt className="text-muted-foreground">
                                        Ultimo tentativo
                                    </dt>
                                    <dd className="flex flex-wrap items-center gap-2 tabular-nums">
                                        {formatDateTime(
                                            connection.sync_attempted_at,
                                        )}
                                        {connection.sync_status &&
                                        connection.sync_status !== 'ok' ? (
                                            <Badge variant="destructive">
                                                Non riuscito
                                            </Badge>
                                        ) : null}
                                    </dd>
                                </div>
                            ) : null}
                        </dl>
                        {connection?.sync_summary ? (
                            <p className="text-sm text-muted-foreground">
                                {connection.sync_summary.read} elementi letti ·{' '}
                                {connection.sync_summary.updated} aggiornati ·{' '}
                                {connection.sync_summary.created} creati ·{' '}
                                {connection.sync_summary.associated} nuove
                                associazioni
                            </p>
                        ) : null}
                        {!active && connection ? (
                            <p className="text-sm">
                                {connection.sync_status === 'suspended' ||
                                connection.sync_status === 'source_changed'
                                    ? 'Riconfermando la sorgente, le letture precedenti restano conservate con la loro provenienza.'
                                    : 'L’attivazione avvia la prima acquisizione e gli aggiornamenti orari di VLAN, subnet e IP configurati.'}
                            </p>
                        ) : null}
                    </CardContent>
                </Card>
            ) : sources.length ? (
                <div>
                    <Button variant="outline" onClick={() => setAllOpen(true)}>
                        Dati acquisiti
                    </Button>
                </div>
            ) : null}
            {problems.length ? (
                <section
                    className="flex min-w-0 flex-col gap-3"
                    aria-label="Dati da verificare"
                >
                    <h2 className="flex items-center gap-2 text-base font-medium">
                        Da verificare{' '}
                        <Badge variant="secondary">{problems.length}</Badge>
                    </h2>
                    <SourceTable
                        sources={problems}
                        onSelect={select}
                        name="mikrotik-problems"
                    />
                </section>
            ) : null}
            <Sheet
                open={allOpen || selected !== null}
                onOpenChange={(open) => {
                    if (!open && !form.processing) {
                        setSelectedId(null);
                        setAllOpen(false);
                    }
                }}
            >
                <SheetContent
                    className={cn(
                        'w-full overflow-y-auto',
                        allOpen ? 'sm:max-w-5xl' : 'sm:max-w-lg',
                    )}
                    aria-describedby={undefined}
                >
                    <SheetHeader className="gap-3 pr-12">
                        {selected && allOpen ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="w-fit"
                                onClick={backToList}
                            >
                                <ArrowLeft data-icon="inline-start" />
                                Torna all’elenco
                            </Button>
                        ) : null}
                        <SheetTitle ref={detailTitle} tabIndex={-1}>
                            {selected ? sourceValue(selected) : 'Dati MikroTik'}
                        </SheetTitle>
                        <p className="text-sm text-muted-foreground">
                            {selected
                                ? sourceLabel(selected)
                                : sources[0]?.source}
                            {selected
                                ? ` · Rilevato il ${formatDateTime(selected.seen_at)}`
                                : connection?.synced_at
                                  ? ` · Ultima acquisizione ${formatDateTime(connection.synced_at)}`
                                  : ''}
                        </p>
                    </SheetHeader>
                    {allOpen ? (
                        <div hidden={selected !== null} className="px-4 pb-6">
                            <SourceTable
                                sources={sources}
                                onSelect={select}
                                name="mikrotik-sources"
                            />
                        </div>
                    ) : null}
                    {selected ? (
                        <div className="flex flex-col gap-4 px-4 pb-6">
                            <SourceDetails sources={[selected]} />
                            {selected.exclusion ? (
                                <NetworkExclusionAction
                                    {...route}
                                    {...selected.exclusion}
                                    excluded
                                />
                            ) : selected.kind === 'subnet' ||
                              selected.kind === 'vlan' ? (
                                <NetworkExclusionAction
                                    {...route}
                                    kind={
                                        selected.kind === 'subnet' &&
                                        selected.subnet_id
                                            ? 'subnet'
                                            : selected.kind === 'vlan' &&
                                                selected.vlan_id
                                              ? 'vlan'
                                              : 'source'
                                    }
                                    id={
                                        (selected.kind === 'subnet'
                                            ? selected.subnet_id
                                            : selected.vlan_id) ?? selected.id
                                    }
                                    label={
                                        selected.kind === 'vlan'
                                            ? `VLAN ${selected.data.tag}`
                                            : (selected.data.cidr ?? 'Subnet')
                                    }
                                />
                            ) : null}
                            {selected.subnet_id ? (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={subnetShow({
                                            company: route.company,
                                            site: route.site,
                                            subnet: selected.subnet_id,
                                        })}
                                    >
                                        Apri subnet
                                    </Link>
                                </Button>
                            ) : selected.vlan_id ? (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={vlanShow({
                                            company: route.company,
                                            site: route.site,
                                            vlan: selected.vlan_id,
                                        })}
                                    >
                                        Apri VLAN
                                    </Link>
                                </Button>
                            ) : null}
                            {selected.can_associate ? (
                                <>
                                    <Field orientation="horizontal">
                                        <Checkbox
                                            id="confirm-mikrotik-association"
                                            checked={confirmed}
                                            onCheckedChange={(value) =>
                                                setConfirmed(value === true)
                                            }
                                        />
                                        <FieldLabel htmlFor="confirm-mikrotik-association">
                                            {selected.kind === 'vlan'
                                                ? 'Confermo che le fonti descrivono lo stesso segmento VLAN.'
                                                : 'Confermo l’attribuzione della VLAN rilevata alla subnet senza VLAN.'}
                                        </FieldLabel>
                                    </Field>
                                    <Button
                                        disabled={!confirmed || busy}
                                        onClick={() => {
                                            form.transform((data) => ({
                                                ...data,
                                                source_id: selected.id,
                                            }));
                                            form.submit(associate(route), {
                                                preserveScroll: true,
                                                onError: (errors) =>
                                                    toast.error(
                                                        Object.values(
                                                            errors,
                                                        )[0],
                                                    ),
                                                onSuccess: backToList,
                                            });
                                        }}
                                    >
                                        Associa a esistente
                                    </Button>
                                </>
                            ) : null}
                        </div>
                    ) : null}
                </SheetContent>
            </Sheet>
        </>
    );
}

function SourceTable({
    sources,
    onSelect,
    name,
}: {
    sources: Source[];
    onSelect: (source: Source) => void;
    name: string;
}) {
    const [visibility, setVisibility] = useState('active');
    const [section, setSection] = useState('configuration');
    const [type, setType] = useState('all');
    const catalog = name === 'mikrotik-sources';
    const sections = [
        { id: 'configuration', label: 'Configurazione' },
        { id: 'observations', label: 'Osservazioni' },
        { id: 'technical', label: 'Dettagli tecnici' },
        { id: 'all', label: 'Tutti' },
    ];
    const inSection = (source: Source, section: string) =>
        section === 'all' ||
        (section === 'observations'
            ? source.kind === 'observation'
            : section === 'technical'
              ? source.kind === 'context'
              : source.kind !== 'observation' && source.kind !== 'context');
    const sectionSources = catalog
        ? sources.filter((source) => inSection(source, section))
        : sources;
    const types = [...new Set(sectionSources.map(sourceLabel))].sort();
    const excludedCount = sectionSources.filter(
        (source) => source.exclusion,
    ).length;
    const value = (source: Source, column: string): string | number | null => {
        switch (column) {
            case 'kind':
                return sourceLabel(source);
            case 'value':
                return sourceValue(source);
            case 'interface':
                return (
                    source.data.interface ??
                    source.data['on-interface'] ??
                    source.data.context ??
                    source.data.bridge ??
                    null
                );
            case 'mac':
                return source.data.mac ?? source.data.mac_address ?? null;
            case 'hostname':
                return source.data.hostname_observed ?? null;
            case 'comment':
                return source.data.comment ?? null;
            case 'acquisition':
                return sourceIsDynamic(source) ? 'Dinamico' : null;
            case 'import_note':
                return source.import_note ?? null;
            case 'menu':
                return source.data.menu;
            case 'seen':
                return formatDateTime(source.seen_at);
            case 'status':
                return source.exclusion
                    ? 'Escluso'
                    : source.state === 'missing'
                      ? 'Non più presente'
                      : source.stale
                        ? 'Non aggiornato'
                        : source.problem
                          ? 'Da verificare'
                          : source.import_note
                            ? 'Non importato'
                            : 'Rilevato';
            default:
                return source.problem;
        }
    };
    return (
        <div className="flex min-w-0 flex-col gap-5">
            {catalog ? (
                <ToggleGroup
                    type="single"
                    value={section}
                    onValueChange={(next) => {
                        if (next) {
                            setSection(next);
                            setType('all');
                        }
                    }}
                    variant="outline"
                    className="w-fit max-w-full flex-wrap justify-start"
                    aria-label="Categorie dei dati MikroTik"
                >
                    {sections.map((item) => (
                        <ToggleGroupItem key={item.id} value={item.id}>
                            {item.label}
                            <Badge variant="secondary">
                                {
                                    sources.filter((source) =>
                                        inSection(source, item.id),
                                    ).length
                                }
                            </Badge>
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            ) : null}
            <DataTable
                name={name}
                variant="list"
                rows={
                    catalog
                        ? sectionSources.filter(
                              (source) =>
                                  Boolean(source.exclusion) ===
                                      (visibility === 'excluded') &&
                                  (type === 'all' ||
                                      sourceLabel(source) === type),
                          )
                        : sources
                }
                filters={
                    catalog ? (
                        <>
                            <Select value={type} onValueChange={setType}>
                                <SelectTrigger
                                    aria-label="Tipo di dato"
                                    className="w-full sm:w-56"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectItem value="all">
                                            Tutti i tipi
                                        </SelectItem>
                                        {types.map((label) => (
                                            <SelectItem
                                                key={label}
                                                value={label}
                                            >
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                            <NetworkVisibilityFilter
                                value={visibility}
                                onChange={setVisibility}
                                count={excludedCount}
                            />
                        </>
                    ) : undefined
                }
                filterKey={`${section}:${type}:${visibility}`}
                activeFilters={
                    catalog
                        ? Number(type !== 'all') +
                          Number(visibility === 'excluded')
                        : 0
                }
                onRowClick={onSelect}
                rowOpensDialog={!catalog}
                searchLabel={
                    catalog && section === 'all'
                        ? 'Cerca in tutti i dati MikroTik'
                        : 'Cerca nei dati MikroTik'
                }
                empty={
                    visibility === 'excluded'
                        ? 'Nessun dato escluso in questa categoria'
                        : 'Nessun dato in questa categoria'
                }
                columns={[
                    { id: 'value', label: 'Valore', visible: true },
                    { id: 'kind', label: 'Tipo', visible: true },
                    {
                        id: 'interface',
                        label: 'Interfaccia / bridge',
                        visible: catalog,
                    },
                    { id: 'status', label: 'Stato', visible: catalog },
                    {
                        id: 'problem',
                        label: 'Da verificare',
                        visible: !catalog,
                    },
                    { id: 'mac', label: 'MAC', visible: false },
                    {
                        id: 'hostname',
                        label: 'Hostname osservato',
                        visible: false,
                    },
                    { id: 'comment', label: 'Commento', visible: false },
                    { id: 'seen', label: 'Ultimo rilevamento', visible: false },
                    { id: 'menu', label: 'Menu RouterOS', visible: false },
                    {
                        id: 'acquisition',
                        label: 'Acquisizione',
                        visible: false,
                    },
                    {
                        id: 'import_note',
                        label: 'Motivo mancata importazione',
                        visible: false,
                    },
                ]}
                value={value}
                render={(source, column) =>
                    column === 'status' ? (
                        <Badge
                            variant={
                                source.problem &&
                                !source.exclusion &&
                                source.state !== 'missing'
                                    ? 'secondary'
                                    : 'outline'
                            }
                        >
                            {value(source, column)}
                        </Badge>
                    ) : column === 'value' ? (
                        <div className="flex flex-col gap-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <span>{sourceValue(source)}</span>
                                {sourceIsDynamic(source) ? (
                                    <Badge variant="secondary">Dinamico</Badge>
                                ) : null}
                            </div>
                            {source.ip && source.data.mac ? (
                                <span className="text-xs font-normal text-muted-foreground">
                                    {source.data.mac}
                                </span>
                            ) : null}
                            {source.data.comment ? (
                                <span className="text-xs font-normal text-muted-foreground">
                                    {source.data.comment}
                                </span>
                            ) : null}
                        </div>
                    ) : (
                        (value(source, column) ?? '—')
                    )
                }
                actions={(source) => (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onSelect(source)}
                    >
                        Dettaglio
                    </Button>
                )}
            />
        </div>
    );
}

function sourceValue(source: MikrotikSource) {
    return (
        source.ip ??
        source.data.cidr ??
        (source.data.tag ? `VLAN ${source.data.tag}` : null) ??
        source.data.name ??
        source.data.mac ??
        source.data.mac_address ??
        source.data.address ??
        source.data.interface ??
        source.data.context ??
        source.data.list ??
        source.data.menu
    );
}
