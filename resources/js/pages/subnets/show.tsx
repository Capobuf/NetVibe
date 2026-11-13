import {
    SubnetIpRow,
    type IpColumn,
    type IpRow,
} from '@/components/networks/subnet-ip-row';
import {
    UnifiObservationIndicator,
    type UnifiObservation,
} from '@/components/networks/unifi-observation';
import { useSiteContext } from '@/hooks/use-site-context';
import { NetworkExclusionAction } from '@/components/networks/network-exclusion';
import { show as vlanShow } from '@/routes/companies/sites/vlans';
import { update as updatePreferences } from '@/routes/preferences';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    MikrotikIndicator,
    type MikrotikSource,
} from '@/components/networks/mikrotik-source';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    ArrowLeft,
    ArrowUpRight,
    Search,
    Eye,
    EyeOff,
    MoreHorizontal,
    Pencil,
    SlidersHorizontal,
} from 'lucide-react';
import { IconButton } from '@/components/icon-button';
import { TableColumns } from '@/components/table-columns';
import { TableSearch } from '@/components/table-search';
import { TablePagination } from '@/components/table-pagination';
import { TableSortHead } from '@/components/table-sort-head';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import IpAddressForm from '@/components/networks/ip-address-form';
import ExcludedRanges from '@/components/networks/excluded-ranges';
import SubnetForm from '@/components/networks/subnet-form';
import { ExportFormatItems } from '@/components/networks/ipam-export-menu';
import { Badge } from '@/components/ui/badge';
import SubnetOccupancyChart from '@/components/networks/subnet-occupancy-chart';
import SubnetSummary from '@/components/networks/subnet-summary';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { download, index, show } from '@/routes/companies/sites/subnets';
import type { Company, Site } from '@/types/company';
import type {
    ExcludedRange,
    IpStatus,
    Tag,
    Subnet,
    SubnetSummaryEntry,
    Vlan,
} from '@/types/network';

type Props = {
    company: Company;
    site: Site;
    subnet: Subnet;
    summaryEntries: SubnetSummaryEntry[];
    vlan: Vlan | null;
    vlans: Vlan[];
    excludedRanges: ExcludedRange[];
    statuses: IpStatus[];
    tags: Tag[];
    initialIpAddress: IpRow | null;
    mikrotikSources: MikrotikSource[];
    unifiObservations: UnifiObservation[];
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
    vlans,
    ipAddresses,
    excludedRanges,
    firstProposableIp,
    addressCounts,
    query,
    statuses,
    tags,
    initialIpAddress,
    mikrotikSources,
    unifiObservations = [],
}: Props) {
    const { auth, errors } = usePage().props;
    const [selectedIp, setSelectedIp] = useState<IpRow | null>(
        initialIpAddress,
    );
    const [search, setSearch] = useState(query.search);
    const [suggestionOpen, setSuggestionOpen] = useState(false);
    const [actionDialog, setActionDialog] = useState<
        'edit' | 'ranges' | 'visibility' | null
    >(null);
    const menuTrigger = useRef<HTMLButtonElement>(null);
    const closeActionDialog = (open: boolean) => {
        if (!open) setActionDialog(null);
    };
    const restoreMenuFocus = (event: Event) => {
        event.preventDefault();
        menuTrigger.current?.focus();
    };
    useEffect(() => setSearch(query.search), [query.search]);
    const dialogOpener = useRef<HTMLElement | null>(null);
    const createdIp = useRef<string | null>(null);
    const [separators, setSeparators] = useState(
        auth.user.subnet_table_separators,
    );
    // The grid choice lives on the profile, so it follows the user across browsers.
    function saveSeparators(value: boolean) {
        setSeparators(value);
        router.patch(
            updatePreferences.url(),
            { subnet_table_separators: value },
            {
                only: ['auth'],
                preserveState: true,
                preserveScroll: true,
                onError: () => setSeparators(!value),
            },
        );
    }
    const { columns, setColumns } =
        useTablePreferences<IpColumn['id']>(
            'ip-addresses',
            [
                { id: 'hostname', label: 'Hostname', visible: true },
                { id: 'ip', label: 'IP', visible: true },
                { id: 'sources', label: 'Fonti', visible: true },
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
        );
    const sourcesByIp = new Map<string, MikrotikSource[]>();
    for (const source of mikrotikSources) {
        if (source.ip)
            sourcesByIp.set(source.ip, [
                ...(sourcesByIp.get(source.ip) ?? []),
                source,
            ]);
    }
    const unifiByIp = new Map<string, UnifiObservation[]>();
    for (const observation of unifiObservations) {
        if (observation.ip)
            unifiByIp.set(observation.ip, [
                ...(unifiByIp.get(observation.ip) ?? []),
                observation,
            ]);
    }
    const visibleColumns = columns.filter((column) => column.visible);
    const route = { company: company.id, site: site.id, subnet: subnet.id };
    const context = useSiteContext(company, site);
    const formContext = {
        companyId: company.id,
        siteId: site.id,
        subnetId: subnet.id,
        context: `${context} · ${subnet.cidr}`,
        excludedRanges,
        statuses,
        tags,
        onCreated: (ip: string) => {
            createdIp.current = ip;
        },
        onCloseAutoFocus: revealCreatedIp,
    };

    function revealCreatedIp(event: Event) {
        const ip = createdIp.current;
        if (!ip) return false;
        event.preventDefault();
        createdIp.current = null;
        const scrollToIp = () => {
            requestAnimationFrame(() => {
                const row = document.getElementById(`ip-row-${ip}`);
                row?.focus({ preventScroll: true });
                row?.scrollIntoView({
                    block: 'center',
                    behavior: window.matchMedia(
                        '(prefers-reduced-motion: reduce)',
                    ).matches
                        ? 'instant'
                        : 'smooth',
                });
            });
        };
        if (document.getElementById(`ip-row-${ip}`)) {
            scrollToIp();
        } else {
            router.get(
                show.url(route),
                {
                    ...query,
                    page: ipAddresses.current_page,
                    per_page: ipAddresses.per_page,
                    focus_ip: ip,
                },
                {
                    only: ['ipAddresses', 'query'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    onSuccess: scrollToIp,
                },
            );
        }
        return true;
    }

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
            {
                // Observations and form data cover the whole subnet, so paging, sorting and searching reuse them.
                only: ['ipAddresses', 'query'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
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
                    <CardHeader className="gap-3 px-5 pt-5 pb-4 sm:px-7 sm:pt-7">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            {vlan ? (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="-ml-2 h-auto max-w-full justify-start py-2"
                                    asChild
                                >
                                    <Link
                                        href={vlanShow({
                                            company: company.id,
                                            site: site.id,
                                            vlan: vlan.id,
                                        })}
                                    >
                                        <span className="flex flex-col items-start gap-1">
                                            <span className="text-xs text-muted-foreground">
                                                VLAN ID
                                            </span>
                                            <span className="text-base font-medium tabular-nums">
                                                {vlan.tag}
                                            </span>
                                        </span>
                                        {vlan.name ? (
                                            <span className="min-w-0 truncate">
                                                {vlan.name}
                                            </span>
                                        ) : null}
                                    </Link>
                                </Button>
                            ) : (
                                <span className="text-sm text-muted-foreground">
                                    Senza VLAN
                                </span>
                            )}
                            <div className="flex flex-wrap items-center gap-2">
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            ref={menuTrigger}
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Azioni per ${subnet.cidr}`}
                                        >
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        onCloseAutoFocus={(event) => {
                                            if (actionDialog)
                                                event.preventDefault();
                                        }}
                                    >
                                        <DropdownMenuGroup>
                                            {auth.canEditIpam ? (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setActionDialog('edit')
                                                    }
                                                >
                                                    <Pencil /> Modifica subnet
                                                </DropdownMenuItem>
                                            ) : null}
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setActionDialog('ranges')
                                                }
                                            >
                                                <SlidersHorizontal /> Esclusioni
                                                <Badge
                                                    variant="secondary"
                                                    className="ml-auto tabular-nums"
                                                >
                                                    {excludedRanges.length}
                                                </Badge>
                                            </DropdownMenuItem>
                                        </DropdownMenuGroup>
                                        <DropdownMenuSeparator />
                                        <ExportFormatItems
                                            label="Esporta"
                                            href={(format) =>
                                                download.url(
                                                    {
                                                        company: company.id,
                                                        site: site.id,
                                                        subnet: subnet.id,
                                                    },
                                                    { query: { format } },
                                                )
                                            }
                                        />
                                        {auth.canEditIpam ? (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuGroup>
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            setActionDialog(
                                                                'visibility',
                                                            )
                                                        }
                                                    >
                                                        {subnet.excluded_at ||
                                                        vlan?.excluded_at ? (
                                                            <Eye />
                                                        ) : (
                                                            <EyeOff />
                                                        )}
                                                        {subnet.excluded_at ||
                                                        vlan?.excluded_at
                                                            ? 'Reincludi'
                                                            : 'Escludi da NetVibe…'}
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                            </>
                                        ) : null}
                                    </DropdownMenuContent>
                                </DropdownMenu>
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
                                <UnifiObservationIndicator
                                    company={company.id}
                                    site={site.id}
                                    observations={unifiObservations.filter(
                                        (observation) =>
                                            observation.kind === 'network',
                                    )}
                                    documented={
                                        vlan
                                            ? `VLAN ${vlan.tag}${vlan.name ? ` · ${vlan.name}` : ''}`
                                            : 'Senza VLAN'
                                    }
                                />
                            </h1>
                            {subnet.name ||
                            subnet.excluded_at ||
                            vlan?.excluded_at ? (
                                <div className="flex flex-wrap items-center gap-3">
                                    {vlan?.excluded_at ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
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
                                </div>
                            ) : null}
                            {subnet.description ? (
                                <CardDescription className="max-w-3xl break-words">
                                    {subnet.description}
                                </CardDescription>
                            ) : null}
                        </div>
                    </CardHeader>
                    <CardContent className="grid items-start gap-6 px-5 pb-5 sm:px-7 sm:pb-7 lg:grid-cols-[minmax(0,1fr)_25rem] lg:gap-10">
                        <SubnetSummary
                            key={subnet.id}
                            route={route}
                            entries={summaryEntries}
                        />
                        <div className="flex min-w-0 flex-col gap-3">
                            <SubnetOccupancyChart counts={addressCounts} />
                            {firstProposableIp && auth.canEditIpam ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-auto min-h-11 w-full justify-start rounded-lg bg-muted/40 px-3 py-2.5 text-left whitespace-normal"
                                    aria-label={`Aggiungi IP ${firstProposableIp}`}
                                    onClick={(event) => {
                                        dialogOpener.current =
                                            event.currentTarget;
                                        setSuggestionOpen(true);
                                    }}
                                >
                                    <ArrowUpRight
                                        className="size-5 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span className="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                        <span className="text-xs font-normal text-muted-foreground">
                                            Prossimo IP suggerito
                                        </span>
                                        <span className="tabular-nums">
                                            {firstProposableIp}
                                        </span>
                                    </span>
                                </Button>
                            ) : (
                                <div className="flex min-w-0 items-center gap-2 rounded-lg bg-muted/40 px-3 py-2.5">
                                    <ArrowUpRight
                                        className="size-5 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <div className="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                        <p className="text-xs text-muted-foreground">
                                            Prossimo IP suggerito
                                        </p>
                                        <p className="text-sm font-medium tabular-nums">
                                            {firstProposableIp ??
                                                'Nessun IP proponibile'}
                                        </p>
                                    </div>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
                <Card className="gap-0 overflow-hidden py-0">
                    <CardHeader className="gap-4 py-4">
                        <div className="flex items-center justify-between gap-3">
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
                        </div>
                    </CardHeader>
                    <CardContent className="px-0">
                        <Table
                            className="text-left [&_td]:h-10 [&_td]:px-3 [&_td]:py-1 data-[separators=true]:[&_tr>:not(:last-child)]:border-r"
                            data-separators={separators}
                            aria-label={`Indirizzi nella subnet ${subnet.cidr}`}
                        >
                            <TableHeader className="bg-muted/50">
                                <TableRow>
                                    <TableHead className="w-12" scope="col">
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
                                    {visibleColumns.map((column) =>
                                        column.id === 'sources' ? (
                                            <TableHead
                                                key={column.id}
                                                scope="col"
                                            >
                                                Fonti
                                            </TableHead>
                                        ) : (
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
                                                        sort:
                                                            sort?.column ??
                                                            null,
                                                        direction:
                                                            sort?.direction ??
                                                            'asc',
                                                    })
                                                }
                                            />
                                        ),
                                    )}
                                    <TableHead className="w-12 text-right">
                                        <TableColumns
                                            columns={columns}
                                            onChange={setColumns}
                                            separators={separators}
                                            onSeparatorsChange={saveSeparators}
                                        />
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {ipAddresses.data.map((ipAddress) => (
                                    <SubnetIpRow
                                        key={ipAddress.ip}
                                        ipAddress={ipAddress}
                                        visibleColumns={visibleColumns}
                                        sources={
                                            sourcesByIp.get(ipAddress.ip) ?? []
                                        }
                                        unifi={unifiByIp.get(ipAddress.ip) ?? []}
                                        company={company.id}
                                        site={site.id}
                                        canEdit={auth.canEditIpam}
                                        onOpen={(address, opener) => {
                                            dialogOpener.current = opener;
                                            setSelectedIp(address);
                                        }}
                                    />
                                ))}
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
            {actionDialog === 'edit' && auth.canEditIpam ? (
                <SubnetForm
                    companyId={company.id}
                    siteId={site.id}
                    context={context}
                    subnet={subnet}
                    vlans={vlans}
                    open
                    onOpenChange={closeActionDialog}
                    onCloseAutoFocus={restoreMenuFocus}
                />
            ) : null}
            {actionDialog === 'ranges' ? (
                <ExcludedRanges
                    {...formContext}
                    subnetCidr={subnet.cidr}
                    open
                    onOpenChange={closeActionDialog}
                    onCloseAutoFocus={restoreMenuFocus}
                />
            ) : null}
            {actionDialog === 'visibility' && auth.canEditIpam ? (
                <NetworkExclusionAction
                    company={company.id}
                    site={site.id}
                    kind="subnet"
                    id={subnet.id}
                    label={subnet.cidr}
                    excluded={Boolean(subnet.excluded_at || vlan?.excluded_at)}
                    open
                    onOpenChange={closeActionDialog}
                    onRestoreFocus={() => menuTrigger.current?.focus()}
                />
            ) : null}
            {suggestionOpen && firstProposableIp ? (
                <IpAddressForm
                    key={`suggestion-${subnet.id}`}
                    {...formContext}
                    suggestedIp={firstProposableIp}
                    open
                    onOpenChange={setSuggestionOpen}
                    onCloseAutoFocus={(event) => {
                        if (revealCreatedIp(event)) return;
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
                        if (revealCreatedIp(event)) return;
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
