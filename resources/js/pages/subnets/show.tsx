import { NetworkExclusionAction } from '@/components/networks/network-exclusion';
import { show as vlanShow } from '@/routes/companies/sites/vlans';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
    EmptyDescription,
} from '@/components/ui/empty';
import {
    MikrotikIndicator,
    MikrotikValue,
    type MikrotikSource,
} from '@/components/networks/mikrotik-source';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    ArrowLeft,
    Ban,
    Network,
    ArrowUpRight,
    Search,
    Eye,
    EyeOff,
} from 'lucide-react';
import { DeviceAssociation } from '@/components/devices/device-association';
import { IconButton } from '@/components/icon-button';
import { CopyValue } from '@/components/copy-value';
import { TableColumns, type TableColumn } from '@/components/table-columns';
import { TableSearch } from '@/components/table-search';
import { TablePagination } from '@/components/table-pagination';
import { TableSortHead } from '@/components/table-sort-head';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import IpAddressForm from '@/components/networks/ip-address-form';
import ExcludedRanges from '@/components/networks/excluded-ranges';
import { TagBadge } from '@/components/networks/tag-badge';
import { Badge } from '@/components/ui/badge';
import SubnetOccupancyChart from '@/components/networks/subnet-occupancy-chart';
import SubnetSummary from '@/components/networks/subnet-summary';
import { Separator } from '@/components/ui/separator';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardFooter,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { index as companiesIndex } from '@/routes/companies';
import { index, show } from '@/routes/companies/sites/subnets';
import type { Company, Site } from '@/types/company';
import type {
    ExcludedRange,
    IpAddress,
    IpStatus,
    Tag,
    Subnet,
    SubnetSummaryEntry,
    Vlan,
} from '@/types/network';

type IpColumn = TableColumn<
    | 'ip'
    | 'hostname'
    | 'mac_address'
    | 'description'
    | 'status'
    | 'tags'
    | 'device'
>;

type IpRow = Omit<IpAddress, 'id'> & { id: number | null; excluded: boolean };

type Props = {
    company: Company;
    site: Site;
    subnet: Subnet;
    summaryEntries: SubnetSummaryEntry[];
    vlan: Vlan | null;
    excludedRanges: ExcludedRange[];
    statuses: IpStatus[];
    tags: Tag[];
    initialIpAddress: IpRow | null;
    mikrotikSources: MikrotikSource[];
    firstProposableIp: string | null;
    addressCounts: {
        documented: number;
        excluded: number;
        external: number;
        proposable: number;
    };
    query: {
        search: string;
        hide_free: 0 | 1;
        sort: IpColumn['id'] | null;
        direction: 'asc' | 'desc';
    };
    ipAddresses: {
        data: IpRow[];
        total: number;
        current_page: number;
        last_page: number;
        per_page: number;
        from: number | null;
        to: number | null;
    };
};

export default function SubnetShow({
    company,
    site,
    subnet,
    summaryEntries,
    vlan,
    ipAddresses,
    excludedRanges,
    firstProposableIp,
    addressCounts,
    query,
    statuses,
    tags,
    initialIpAddress,
    mikrotikSources,
}: Props) {
    const { auth, companyNavigation, errors } = usePage().props;
    const [selectedIp, setSelectedIp] = useState<IpRow | null>(
        initialIpAddress,
    );
    const [search, setSearch] = useState(query.search);
    const [suggestionOpen, setSuggestionOpen] = useState(false);
    useEffect(() => setSearch(query.search), [query.search]);
    const dialogOpener = useRef<HTMLElement | null>(null);
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences<IpColumn['id']>(
            'ip-addresses',
            [
                { id: 'hostname', label: 'Hostname', visible: true },
                { id: 'ip', label: 'IP', visible: true },
                { id: 'mac_address', label: 'MAC Address', visible: true },
                {
                    id: 'device',
                    label: 'Dispositivo / Interfaccia',
                    visible: true,
                },
                { id: 'description', label: 'Descrizione', visible: true },
                { id: 'status', label: 'Stato', visible: true },
                { id: 'tags', label: 'Tag', visible: true },
            ],
            true,
        );
    const sourcesByIp = new Map<string, MikrotikSource[]>();
    for (const source of mikrotikSources) {
        if (source.ip)
            sourcesByIp.set(source.ip, [
                ...(sourcesByIp.get(source.ip) ?? []),
                source,
            ]);
    }
    const visibleColumns = columns.filter((column) => column.visible);
    const route = { company: company.id, site: site.id, subnet: subnet.id };
    const context =
        (companyNavigation?.sites.length ?? 0) > 1
            ? `${company.name} · ${site.name}`
            : company.name;
    const formContext = {
        companyId: company.id,
        siteId: site.id,
        subnetId: subnet.id,
        context: `${context} · ${subnet.cidr}`,
        excludedRanges,
        statuses,
        tags,
    };

    function updateTable(
        options: Partial<Props['query']> & { page?: number; per_page?: number },
    ) {
        router.get(
            show.url(route),
            {
                ...query,
                search,
                per_page: ipAddresses.per_page,
                page: 1,
                ...options,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title={`${subnet.cidr} · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6 lg:gap-8">
                <Button
                    variant="ghost"
                    size="sm"
                    className="-mb-3 self-start"
                    asChild
                >
                    <Link href={index({ company: company.id, site: site.id })}>
                        <ArrowLeft data-icon="inline-start" />
                        Subnet
                    </Link>
                </Button>
                <Card
                    className="gap-0 overflow-hidden py-0 motion-safe:animate-in motion-safe:duration-500 motion-safe:fade-in-0 motion-safe:slide-in-from-bottom-2"
                    role="region"
                    aria-labelledby="subnet-title"
                >
                    <CardHeader className="gap-6 p-5 sm:p-7">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div className="flex min-w-0 items-center gap-3">
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border bg-muted/50 text-muted-foreground">
                                    <Network
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </div>
                                <CardDescription className="break-words">
                                    {context}
                                </CardDescription>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {auth.canEditIpam ? (
                                    <NetworkExclusionAction
                                        company={company.id}
                                        site={site.id}
                                        kind="subnet"
                                        id={subnet.id}
                                        label={subnet.cidr}
                                        excluded={Boolean(
                                            subnet.excluded_at ||
                                            vlan?.excluded_at,
                                        )}
                                        compact={
                                            !subnet.excluded_at &&
                                            !vlan?.excluded_at
                                        }
                                    />
                                ) : null}
                                <ExcludedRanges
                                    key={subnet.id}
                                    {...formContext}
                                    subnetCidr={subnet.cidr}
                                />
                                {auth.canEditIpam ? (
                                    <IpAddressForm
                                        key={`ip-${subnet.id}`}
                                        {...formContext}
                                    />
                                ) : null}
                            </div>
                        </div>
                        <div className="flex flex-col gap-3">
                            <h1
                                id="subnet-title"
                                tabIndex={-1}
                                className="text-2xl font-medium tracking-tight break-all tabular-nums"
                            >
                                {subnet.cidr}
                                <MikrotikIndicator
                                    sources={mikrotikSources.filter(
                                        (source) => source.kind === 'subnet',
                                    )}
                                />
                            </h1>
                            <div className="flex flex-wrap items-center gap-3">
                                {vlan?.excluded_at ? (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={vlanShow({
                                                company: company.id,
                                                site: site.id,
                                                vlan: vlan.id,
                                            })}
                                        >
                                            Esclusa tramite VLAN {vlan.tag}
                                        </Link>
                                    </Button>
                                ) : subnet.excluded_at ? (
                                    <Badge variant="outline">
                                        Esclusa ·{' '}
                                        {new Date(
                                            subnet.excluded_at,
                                        ).toLocaleDateString('it-IT')}
                                    </Badge>
                                ) : null}
                                {subnet.name ? (
                                    <p className="text-base font-medium break-words">
                                        {subnet.name}
                                    </p>
                                ) : null}
                                <Badge variant="secondary">
                                    {vlan
                                        ? `VLAN ${vlan.tag}${vlan.name ? ` · ${vlan.name}` : ''}`
                                        : 'Senza VLAN'}
                                </Badge>
                            </div>
                            {subnet.description ? (
                                <CardDescription className="max-w-3xl break-words">
                                    {subnet.description}
                                </CardDescription>
                            ) : null}
                        </div>
                    </CardHeader>
                    <Separator />
                    <CardContent className="grid items-center gap-6 p-5 sm:p-7 lg:grid-cols-[minmax(0,1fr)_20rem] lg:gap-10">
                        <SubnetSummary
                            key={subnet.id}
                            route={route}
                            entries={summaryEntries}
                        />
                        <SubnetOccupancyChart counts={addressCounts} />
                    </CardContent>
                    <Separator />
                    <CardFooter className="flex-wrap justify-between gap-4 bg-muted/30 px-5 py-4 sm:px-7">
                        <div className="flex min-w-0 items-center gap-3">
                            <ArrowUpRight
                                className="size-5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <div className="flex min-w-0 flex-col gap-1">
                                <p className="text-xs text-muted-foreground">
                                    Prossimo IP suggerito
                                </p>
                                {firstProposableIp && auth.canEditIpam ? (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="-ml-2 h-auto self-start py-1 tabular-nums"
                                        aria-label={`Aggiungi IP ${firstProposableIp}`}
                                        onClick={(event) => {
                                            dialogOpener.current =
                                                event.currentTarget;
                                            setSuggestionOpen(true);
                                        }}
                                    >
                                        {firstProposableIp}
                                    </Button>
                                ) : (
                                    <p className="text-sm font-medium tabular-nums">
                                        {firstProposableIp ??
                                            'Nessun IP proponibile'}
                                    </p>
                                )}
                            </div>
                        </div>
                    </CardFooter>
                </Card>
                <Card className="gap-0 overflow-hidden py-0">
                    <CardHeader className="gap-4 py-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex flex-col gap-1">
                                <h2 className="text-base font-medium">
                                    Indirizzi
                                </h2>
                                <CardDescription className="tabular-nums">
                                    {ipAddresses.total.toLocaleString('it-IT')}{' '}
                                    {ipAddresses.total === 1
                                        ? 'indirizzo'
                                        : 'indirizzi'}
                                    {query.search
                                        ? ' trovati'
                                        : query.hide_free
                                          ? ' documentati o rilevati'
                                          : ' utilizzabili'}
                                </CardDescription>
                            </div>
                        </div>
                        <TableSearch
                            value={search}
                            maxLength={255}
                            error={errors.search}
                            total={Object.values(addressCounts).reduce(
                                (sum, value) => sum + value,
                                0,
                            )}
                            label="Cerca IP, hostname, MAC, stato, tag"
                            onChange={(value) => {
                                setSearch(value);
                                updateTable({ search: value });
                            }}
                        />
                    </CardHeader>
                    <CardContent className="px-0">
                        <Table
                            className="text-left [&_td]:h-10 [&_td]:px-3 [&_td]:py-1 data-[separators=true]:[&_tr>:not(:last-child)]:border-r"
                            data-separators={separators}
                            aria-label={`Indirizzi nella subnet ${subnet.cidr}`}
                        >
                            <TableHeader className="bg-muted/50">
                                <TableRow>
                                    <TableHead className="w-12">
                                        <IconButton
                                            label={
                                                query.hide_free
                                                    ? 'Mostra tutti gli IP'
                                                    : 'Nascondi IP liberi'
                                            }
                                            variant={
                                                query.hide_free
                                                    ? 'secondary'
                                                    : 'ghost'
                                            }
                                            aria-pressed={Boolean(
                                                query.hide_free,
                                            )}
                                            onClick={() =>
                                                updateTable({
                                                    hide_free: query.hide_free
                                                        ? 0
                                                        : 1,
                                                })
                                            }
                                        >
                                            {query.hide_free ? (
                                                <EyeOff />
                                            ) : (
                                                <Eye />
                                            )}
                                        </IconButton>
                                    </TableHead>
                                    {visibleColumns.map((column) => (
                                        <TableSortHead
                                            key={column.id}
                                            column={column}
                                            sort={
                                                query.sort
                                                    ? {
                                                          column: query.sort,
                                                          direction:
                                                              query.direction,
                                                      }
                                                    : null
                                            }
                                            onSort={(sort) =>
                                                updateTable({
                                                    sort: sort?.column ?? null,
                                                    direction:
                                                        sort?.direction ??
                                                        'asc',
                                                })
                                            }
                                        />
                                    ))}
                                    <TableHead className="w-12 text-right">
                                        <TableColumns
                                            columns={columns}
                                            onChange={setColumns}
                                            separators={separators}
                                            onSeparatorsChange={setSeparators}
                                        />
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {ipAddresses.data.map((ipAddress) => {
                                    const sources =
                                        sourcesByIp.get(ipAddress.ip) ?? [];
                                    const current = sources.filter(
                                        (source) => source.state !== 'missing',
                                    );
                                    const externalOnly =
                                        ipAddress.id === null &&
                                        current.length > 0;
                                    const sourceNotice = current.some(
                                        (source) => source.problem,
                                    )
                                        ? 'Da verificare'
                                        : current.length > 0 &&
                                            current.every(
                                                (source) => source.stale,
                                            )
                                          ? 'Non aggiornato'
                                          : null;
                                    const canOpen =
                                        ipAddress.id !== null ||
                                        auth.canEditIpam;
                                    const openAddress = (
                                        opener: HTMLElement,
                                    ) => {
                                        dialogOpener.current = opener;
                                        setSelectedIp(ipAddress);
                                    };

                                    return (
                                        <TableRow
                                            key={ipAddress.ip}
                                            className={
                                                canOpen
                                                    ? 'cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring'
                                                    : undefined
                                            }
                                            tabIndex={canOpen ? 0 : undefined}
                                            aria-label={
                                                canOpen
                                                    ? `${ipAddress.id !== null ? 'Visualizza' : 'Documenta'} IP ${ipAddress.ip}`
                                                    : undefined
                                            }
                                            aria-haspopup={
                                                canOpen ? 'dialog' : undefined
                                            }
                                            onClick={
                                                canOpen
                                                    ? (event) => {
                                                          if (
                                                              event.currentTarget.contains(
                                                                  event.target as Node,
                                                              ) &&
                                                              window.getSelection()
                                                                  ?.isCollapsed !==
                                                                  false &&
                                                              !(
                                                                  event.target as HTMLElement
                                                              ).closest(
                                                                  'a,button,input,select,textarea,[role="button"]',
                                                              )
                                                          )
                                                              openAddress(
                                                                  event.currentTarget,
                                                              );
                                                      }
                                                    : undefined
                                            }
                                            onKeyDown={
                                                canOpen
                                                    ? (event) => {
                                                          if (
                                                              event.target ===
                                                                  event.currentTarget &&
                                                              (event.key ===
                                                                  'Enter' ||
                                                                  event.key ===
                                                                      ' ')
                                                          ) {
                                                              event.preventDefault();
                                                              openAddress(
                                                                  event.currentTarget,
                                                              );
                                                          }
                                                      }
                                                    : undefined
                                            }
                                        >
                                            <TableCell>
                                                {externalOnly ||
                                                sourceNotice ? (
                                                    <MikrotikIndicator
                                                        sources={sources}
                                                    />
                                                ) : null}
                                            </TableCell>
                                            {visibleColumns.map((column) => (
                                                <TableCell
                                                    key={column.id}
                                                    className={
                                                        column.id === 'ip' ||
                                                        column.id ===
                                                            'mac_address'
                                                            ? 'px-4 tabular-nums'
                                                            : 'whitespace-nowrap [&>span]:flex-nowrap'
                                                    }
                                                >
                                                    {column.id === 'ip' ? (
                                                        <div className="flex items-center gap-2 whitespace-nowrap">
                                                            <CopyValue
                                                                value={
                                                                    ipAddress.ip
                                                                }
                                                                className="py-1 tabular-nums"
                                                            />
                                                            {ipAddress.excluded ? (
                                                                <Tooltip>
                                                                    <TooltipTrigger
                                                                        asChild
                                                                    >
                                                                        <span
                                                                            tabIndex={
                                                                                0
                                                                            }
                                                                            onClick={(
                                                                                event,
                                                                            ) =>
                                                                                event.stopPropagation()
                                                                            }
                                                                            role="img"
                                                                            aria-label="IP escluso dai suggerimenti"
                                                                            className="inline-flex rounded-sm text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                                        >
                                                                            <Ban
                                                                                className="size-3.5"
                                                                                aria-hidden="true"
                                                                            />
                                                                        </span>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>
                                                                        Escluso
                                                                        dai
                                                                        suggerimenti
                                                                    </TooltipContent>
                                                                </Tooltip>
                                                            ) : null}
                                                            {sourceNotice ? (
                                                                <span className="text-xs text-muted-foreground">
                                                                    {
                                                                        sourceNotice
                                                                    }
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                    ) : column.id ===
                                                      'device' ? (
                                                        <DeviceAssociation
                                                            company={company.id}
                                                            site={site.id}
                                                            device={
                                                                ipAddress.device
                                                            }
                                                            item={
                                                                ipAddress.device_interface
                                                            }
                                                            label={
                                                                ipAddress.assignment_label
                                                            }
                                                        />
                                                    ) : column.id ===
                                                          'hostname' ||
                                                      column.id ===
                                                          'mac_address' ? (
                                                        <MikrotikValue
                                                            showIndicator={
                                                                !externalOnly
                                                            }
                                                            value={
                                                                ipAddress[
                                                                    column.id
                                                                ]
                                                            }
                                                            field={
                                                                column.id ===
                                                                'mac_address'
                                                                    ? 'mac'
                                                                    : 'hostname'
                                                            }
                                                            sources={sources}
                                                        />
                                                    ) : column.id ===
                                                      'status' ? (
                                                        ipAddress.status ? (
                                                            <Badge variant="secondary">
                                                                {
                                                                    ipAddress
                                                                        .status
                                                                        .name
                                                                }
                                                            </Badge>
                                                        ) : (
                                                            ''
                                                        )
                                                    ) : column.id === 'tags' ? (
                                                        ipAddress.tags
                                                            .length ? (
                                                            <div className="flex gap-1 whitespace-nowrap">
                                                                {ipAddress.tags.map(
                                                                    (tag) => (
                                                                        <TagBadge
                                                                            key={
                                                                                tag.id
                                                                            }
                                                                            color={
                                                                                tag.color
                                                                            }
                                                                        >
                                                                            {
                                                                                tag.name
                                                                            }
                                                                        </TagBadge>
                                                                    ),
                                                                )}
                                                            </div>
                                                        ) : (
                                                            ''
                                                        )
                                                    ) : (
                                                        <span
                                                            className="block max-w-lg truncate"
                                                            title={
                                                                ipAddress[
                                                                    column.id
                                                                ] ?? undefined
                                                            }
                                                        >
                                                            {ipAddress[
                                                                column.id
                                                            ] ?? ''}
                                                        </span>
                                                    )}
                                                </TableCell>
                                            ))}
                                            <TableCell />
                                        </TableRow>
                                    );
                                })}
                                {ipAddresses.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={visibleColumns.length + 2}
                                            className="h-40 text-center whitespace-normal"
                                        >
                                            <Empty>
                                                <EmptyHeader>
                                                    <EmptyMedia variant="icon">
                                                        <Search />
                                                    </EmptyMedia>
                                                    <EmptyTitle>
                                                        {query.search
                                                            ? 'Nessun IP trovato'
                                                            : query.hide_free
                                                              ? 'Nessun IP documentato o rilevato'
                                                              : 'Nessun IP in questa pagina'}
                                                    </EmptyTitle>
                                                    <EmptyDescription>
                                                        {query.search
                                                            ? 'Prova un altro termine o azzera la ricerca.'
                                                            : query.hide_free
                                                              ? 'Usa l’occhio per mostrare tutti gli IP.'
                                                              : 'Torna alla pagina precedente.'}
                                                    </EmptyDescription>
                                                </EmptyHeader>
                                            </Empty>
                                        </TableCell>
                                    </TableRow>
                                ) : null}
                            </TableBody>
                        </Table>
                    </CardContent>
                    {ipAddresses.total > 25 || ipAddresses.last_page > 1 ? (
                        <TablePagination
                            summary={`${ipAddresses.from ?? 0}–${ipAddresses.to ?? 0} di ${ipAddresses.total.toLocaleString('it-IT')} indirizzi`}
                            page={ipAddresses.current_page}
                            pageCount={ipAddresses.last_page}
                            pageSize={ipAddresses.per_page}
                            onPageChange={(page) => updateTable({ page })}
                            onPageSizeChange={(per_page) =>
                                updateTable({ per_page })
                            }
                        />
                    ) : null}
                </Card>
            </div>
            {suggestionOpen && firstProposableIp ? (
                <IpAddressForm
                    key={`suggestion-${subnet.id}`}
                    {...formContext}
                    suggestedIp={firstProposableIp}
                    open
                    onOpenChange={setSuggestionOpen}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        dialogOpener.current?.focus();
                    }}
                />
            ) : null}
            {selectedIp ? (
                <IpAddressForm
                    key={selectedIp.ip}
                    {...formContext}
                    ipAddress={
                        selectedIp.id !== null
                            ? { ...selectedIp, id: selectedIp.id }
                            : undefined
                    }
                    suggestedIp={selectedIp.ip}
                    open
                    onOpenChange={(open) => {
                        if (!open) setSelectedIp(null);
                    }}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        (
                            dialogOpener.current ??
                            document.getElementById('subnet-title')
                        )?.focus();
                    }}
                />
            ) : null}
        </>
    );
}

SubnetShow.layout = ({ company, site, subnet }: Props) => ({
    breadcrumbs: [
        { title: 'Aziende', href: companiesIndex() },
        {
            title: 'Subnet',
            href: index({ company: company.id, site: site.id }),
        },
        {
            title: subnet.cidr,
            href: show({
                company: company.id,
                site: site.id,
                subnet: subnet.id,
            }),
        },
    ],
});
