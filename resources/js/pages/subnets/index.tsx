import {
    UnifiObservationIndicator,
    type UnifiObservation,
} from '@/components/networks/unifi-observation';
import { useSiteContext } from '@/hooks/use-site-context';
import { formatDateTime } from '@/lib/utils';
import {
    NetworkExclusionAction,
    NetworkVisibilityFilter,
} from '@/components/networks/network-exclusion';
import { Head, Link, usePage } from '@inertiajs/react';
import { Filter, Layers3, Network, X } from 'lucide-react';
import { Fragment, useState } from 'react';
import { ListPageHeader } from '@/components/list-page-header';
import { IpamExportMenu } from '@/components/networks/ipam-export-menu';
import { TableSearch } from '@/components/table-search';
import { useTablePagination } from '@/hooks/use-table-pagination';
import SubnetForm from '@/components/networks/subnet-form';
import { TableColumns } from '@/components/table-columns';
import { TablePagination } from '@/components/table-pagination';
import { TableSortHead } from '@/components/table-sort-head';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import { groupSubnetsByVlan } from '@/lib/subnet-list';
import { sortTableRows, type TableSort } from '@/lib/table-sort';
import { index, show } from '@/routes/companies/sites/subnets';
import type { Company, Site } from '@/types/company';
import type { Subnet, Vlan } from '@/types/network';

type SubnetColumn = 'cidr' | 'vlan' | 'range' | 'documented' | 'excluded_at';

type SubnetListItem = Subnet & {
    first_ip: string;
    last_ip: string;
    usable_addresses: number;
    ip_addresses_count: number;
};

type Props = {
    company: Company;
    site: Site;
    subnets: SubnetListItem[];
    vlans: Vlan[];
    unifiNetworks: UnifiObservation[];
    companyHasExcludedNetworks: boolean;
};

export default function SubnetIndex(props: Props) {
    return <SubnetList key={props.site.id} {...props} />;
}

function SubnetList({
    company,
    site,
    subnets,
    vlans,
    unifiNetworks = [],
    companyHasExcludedNetworks,
}: Props) {
    const { auth } = usePage().props;
    const [filter, setFilter] = useState('all');
    const [visibility, setVisibility] = useState('active');
    const isExcluded = (subnet: Subnet) =>
        Boolean(
            subnet.excluded_at ||
            vlans.find((vlan) => vlan.id === subnet.vlan_id)?.excluded_at,
        );
    const excludedCount = subnets.filter(isExcluded).length;
    const [search, setSearch] = useState('');
    const [grouped, setGrouped] = useState(false);
    const [sort, setSort] = useState<TableSort<SubnetColumn>>(null);
    const vlanIds = new Set(subnets.map((subnet) => subnet.vlan_id));
    const hasVlans = subnets.some((subnet) => subnet.vlan_id !== null);
    const canFilter = vlanIds.size > 1;
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences<SubnetColumn>('subnets', [
            { id: 'cidr', label: 'Subnet', visible: true },
            { id: 'vlan', label: 'VLAN', visible: hasVlans },
            { id: 'range', label: 'Intervallo utilizzabile', visible: true },
            { id: 'documented', label: 'IP documentati', visible: true },
            { id: 'excluded_at', label: 'Data esclusione', visible: false },
        ]);
    const visibleColumns = columns.filter((column) => column.visible);
    const context = useSiteContext(company, site);
    const formContext = {
        companyId: company.id,
        siteId: site.id,
        context,
        vlans: vlans.filter((vlan) => !vlan.excluded_at),
    };
    const vlansById = new Map(vlans.map((vlan) => [vlan.id, vlan]));
    const query = search.trim().toLocaleLowerCase();
    const filteredSubnets = subnets.filter((subnet) => {
        if (isExcluded(subnet) !== (visibility === 'excluded')) return false;
        if (filter !== 'all' && String(subnet.vlan_id ?? 'none') !== filter)
            return false;
        const vlan = vlansById.get(subnet.vlan_id ?? -1);
        return [
            subnet.cidr,
            subnet.name,
            subnet.description,
            subnet.first_ip,
            subnet.last_ip,
            vlan ? `VLAN ${vlan.tag} ${vlan.name ?? ''}` : 'Senza VLAN',
        ]
            .join(' ')
            .toLocaleLowerCase()
            .includes(query);
    });
    const sortedSubnets = sortTableRows(
        filteredSubnets,
        sort,
        (subnet, column) => {
            if (column === 'cidr') return subnet.cidr;
            if (column === 'range') return subnet.first_ip;
            if (column === 'documented') return subnet.ip_addresses_count;
            if (column === 'excluded_at')
                return (
                    subnet.excluded_at ??
                    vlansById.get(subnet.vlan_id ?? -1)?.excluded_at ??
                    null
                );
            return vlansById.get(subnet.vlan_id ?? -1)?.tag ?? null;
        },
    );
    const rows = grouped
        ? groupSubnetsByVlan(sortedSubnets, vlans)
        : sortedSubnets;
    const {
        pageRows,
        page,
        pageCount,
        pageSize,
        needsPagination,
        setPage,
        setPageSize,
    } = useTablePagination(
        rows,
        JSON.stringify([search, filter, sort, grouped, visibility]),
    );
    const filtered = search.trim() !== '' || filter !== 'all';

    return (
        <>
            <Head title={`Subnet · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col p-4 md:p-6">
                <section
                    aria-labelledby="subnets-title"
                    className="flex min-w-0 flex-col"
                >
                    <div className="flex flex-col gap-5 pb-4">
                        <ListPageHeader
                            id="subnets-title"
                            title="Subnet"
                            icon={Network}
                            actions={
                                <div className="flex flex-wrap gap-2">
                                    <UnifiObservationIndicator
                                        observations={unifiNetworks.filter(
                                            (source) => !source.subnet_id,
                                        )}
                                        company={company.id}
                                        site={site.id}
                                    >
                                        Reti rilevate da UniFi
                                    </UnifiObservationIndicator>
                                    <IpamExportMenu
                                        company={company}
                                        site={site}
                                        excludedInSite={
                                            excludedCount > 0 ||
                                            vlans.some(
                                                (vlan) => vlan.excluded_at,
                                            )
                                        }
                                        excludedInCompany={
                                            companyHasExcludedNetworks
                                        }
                                    />
                                    {auth.canEditIpam ? (
                                        <SubnetForm {...formContext} />
                                    ) : null}
                                </div>
                            }
                        />
                        <div className="flex flex-wrap items-center gap-3">
                            <TableSearch
                                label="Cerca subnet"
                                total={
                                    visibility === 'excluded'
                                        ? excludedCount
                                        : subnets.length - excludedCount
                                }
                                value={search}
                                onChange={setSearch}
                            />
                            <NetworkVisibilityFilter
                                value={visibility}
                                onChange={setVisibility}
                                count={excludedCount}
                            />
                            {canFilter || filter !== 'all' ? (
                                <div className="flex min-w-0 items-center gap-2">
                                    <Label
                                        htmlFor="subnet-vlan-filter"
                                        className="sr-only"
                                    >
                                        Filtra per VLAN
                                    </Label>
                                    <Select
                                        value={filter}
                                        onValueChange={(value) => {
                                            setFilter(value);
                                            setPage(1);
                                        }}
                                    >
                                        <SelectTrigger
                                            id="subnet-vlan-filter"
                                            className="w-52 max-w-full"
                                        >
                                            <Filter aria-hidden="true" />
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                <SelectItem value="all">
                                                    Tutte le VLAN
                                                </SelectItem>
                                                {vlanIds.has(null) ||
                                                filter === 'none' ? (
                                                    <SelectItem value="none">
                                                        Senza VLAN
                                                    </SelectItem>
                                                ) : null}
                                                {vlans
                                                    .filter(
                                                        (vlan) =>
                                                            vlanIds.has(
                                                                vlan.id,
                                                            ) ||
                                                            String(vlan.id) ===
                                                                filter,
                                                    )
                                                    .map((vlan) => (
                                                        <SelectItem
                                                            key={vlan.id}
                                                            value={String(
                                                                vlan.id,
                                                            )}
                                                        >
                                                            VLAN {vlan.tag}
                                                            {vlan.name
                                                                ? ` · ${vlan.name}`
                                                                : ''}
                                                        </SelectItem>
                                                    ))}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    {filter !== 'all' ? (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Rimuovi filtro VLAN"
                                            onClick={() => {
                                                setFilter('all');
                                                setPage(1);
                                            }}
                                        >
                                            <X />
                                        </Button>
                                    ) : null}
                                </div>
                            ) : null}
                            {canFilter || grouped ? (
                                <Button
                                    variant={grouped ? 'secondary' : 'outline'}
                                    aria-pressed={grouped}
                                    aria-label="Raggruppa per VLAN"
                                    onClick={() => {
                                        setGrouped(!grouped);
                                        setPage(1);
                                    }}
                                >
                                    <Layers3 aria-hidden="true" />
                                    Raggruppa
                                </Button>
                            ) : null}
                        </div>
                    </div>
                    <div className="min-w-0">
                        <Table
                            aria-label="Subnet della sede selezionata"
                            data-separators={separators}
                            className="data-[separators=true]:[&_tr>:not(:last-child)]:border-r"
                        >
                            <TableHeader>
                                <TableRow>
                                    {visibleColumns.map((column) => (
                                        <TableSortHead
                                            key={column.id}
                                            column={column}
                                            sort={sort}
                                            onSort={(value) => {
                                                setSort(value);
                                                setPage(1);
                                            }}
                                        />
                                    ))}
                                    <TableHead className="w-16 text-right">
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
                                {pageRows.map((subnet, position) => {
                                    const vlan = vlansById.get(
                                        subnet.vlan_id ?? -1,
                                    );
                                    const startsGroup =
                                        grouped &&
                                        (position === 0 ||
                                            pageRows[position - 1].vlan_id !==
                                                subnet.vlan_id);
                                    return (
                                        <Fragment key={subnet.id}>
                                            {startsGroup ? (
                                                <TableRow className="bg-muted/50">
                                                    <TableHead
                                                        scope="row"
                                                        colSpan={
                                                            visibleColumns.length +
                                                            1
                                                        }
                                                        className="h-10 text-xs whitespace-normal"
                                                    >
                                                        {vlan
                                                            ? `VLAN ${vlan.tag}${vlan.name ? ` · ${vlan.name}` : ''}`
                                                            : 'Senza VLAN'}
                                                    </TableHead>
                                                </TableRow>
                                            ) : null}
                                            <TableRow className="even:bg-muted/20 focus-within:bg-muted/50">
                                                {visibleColumns.map(
                                                    (column) => (
                                                        <TableCell
                                                            key={column.id}
                                                            className="max-w-lg min-w-40 py-3 whitespace-normal"
                                                        >
                                                            {column.id ===
                                                            'cidr' ? (
                                                                <div className="flex flex-col items-start gap-1 break-words">
                                                                    {subnet.name ? (
                                                                        <span className="max-w-full text-xs text-muted-foreground">
                                                                            {
                                                                                subnet.name
                                                                            }
                                                                        </span>
                                                                    ) : null}
                                                                    <div className="flex items-center gap-2">
                                                                        <Link
                                                                            href={show(
                                                                                {
                                                                                    company:
                                                                                        company.id,
                                                                                    site: site.id,
                                                                                    subnet: subnet.id,
                                                                                },
                                                                            )}
                                                                            className="rounded-sm text-sm font-medium whitespace-nowrap tabular-nums transition-colors hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                                                                        >
                                                                            {
                                                                                subnet.cidr
                                                                            }
                                                                        </Link>
                                                                        <UnifiObservationIndicator
                                                                            observations={unifiNetworks.filter(
                                                                                (
                                                                                    source,
                                                                                ) =>
                                                                                    source.subnet_id ===
                                                                                    subnet.id,
                                                                            )}
                                                                            company={
                                                                                company.id
                                                                            }
                                                                            site={
                                                                                site.id
                                                                            }
                                                                            documented={
                                                                                vlan
                                                                                    ? `VLAN ${vlan.tag}${vlan.name ? ` · ${vlan.name}` : ''}`
                                                                                    : 'Senza VLAN'
                                                                            }
                                                                        />
                                                                    </div>
                                                                    {isExcluded(
                                                                        subnet,
                                                                    ) ? (
                                                                        <Badge variant="outline">
                                                                            {vlan?.excluded_at
                                                                                ? `Esclusa tramite VLAN ${vlan.tag}`
                                                                                : 'Esclusa'}
                                                                        </Badge>
                                                                    ) : null}
                                                                    {subnet.description ? (
                                                                        <span className="max-w-full text-xs text-muted-foreground">
                                                                            {
                                                                                subnet.description
                                                                            }
                                                                        </span>
                                                                    ) : null}
                                                                </div>
                                                            ) : column.id ===
                                                              'excluded_at' ? (
                                                                (subnet.excluded_at ??
                                                                vlan?.excluded_at) ? (
                                                                    formatDateTime(
                                                                        (subnet.excluded_at ??
                                                                            vlan?.excluded_at)!,
                                                                    )
                                                                ) : (
                                                                    '—'
                                                                )
                                                            ) : column.id ===
                                                              'range' ? (
                                                                <div className="flex flex-col gap-1 text-sm tabular-nums">
                                                                    <span>
                                                                        {
                                                                            subnet.first_ip
                                                                        }
                                                                    </span>
                                                                    <span className="text-muted-foreground">
                                                                        {
                                                                            subnet.last_ip
                                                                        }
                                                                    </span>
                                                                </div>
                                                            ) : column.id ===
                                                              'documented' ? (
                                                                <div className="flex w-40 max-w-full flex-col gap-2">
                                                                    <div className="flex items-baseline gap-1.5 tabular-nums">
                                                                        <span className="text-sm font-medium">
                                                                            {subnet.ip_addresses_count.toLocaleString(
                                                                                'it-IT',
                                                                            )}
                                                                        </span>
                                                                        <span className="text-xs text-muted-foreground">
                                                                            su{' '}
                                                                            {subnet.usable_addresses.toLocaleString(
                                                                                'it-IT',
                                                                            )}
                                                                        </span>
                                                                    </div>
                                                                    <Progress
                                                                        value={
                                                                            (subnet.ip_addresses_count /
                                                                                subnet.usable_addresses) *
                                                                            100
                                                                        }
                                                                        aria-label={`IP documentati in ${subnet.cidr}`}
                                                                        getValueLabel={() =>
                                                                            `${subnet.ip_addresses_count} su ${subnet.usable_addresses}`
                                                                        }
                                                                        indicatorStyle={{
                                                                            backgroundColor:
                                                                                'var(--chart-2)',
                                                                        }}
                                                                        className="h-1.5 bg-muted"
                                                                    />
                                                                </div>
                                                            ) : vlan ? (
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <Badge variant="secondary">
                                                                        VLAN{' '}
                                                                        {
                                                                            vlan.tag
                                                                        }
                                                                    </Badge>
                                                                    {vlan.name ? (
                                                                        <span className="text-sm break-words text-muted-foreground">
                                                                            {
                                                                                vlan.name
                                                                            }
                                                                        </span>
                                                                    ) : null}
                                                                </div>
                                                            ) : (
                                                                <span className="text-sm text-muted-foreground">
                                                                    Senza VLAN
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                    ),
                                                )}
                                                <TableCell className="text-right">
                                                    {auth.canEditIpam ? (
                                                        <div className="flex items-center justify-end gap-2">
                                                            {!isExcluded(
                                                                subnet,
                                                            ) ? (
                                                                <SubnetForm
                                                                    {...formContext}
                                                                    subnet={
                                                                        subnet
                                                                    }
                                                                />
                                                            ) : null}
                                                            <NetworkExclusionAction
                                                                company={
                                                                    company.id
                                                                }
                                                                site={site.id}
                                                                kind="subnet"
                                                                id={subnet.id}
                                                                label={
                                                                    subnet.cidr
                                                                }
                                                                excluded={isExcluded(
                                                                    subnet,
                                                                )}
                                                                compact
                                                            />
                                                        </div>
                                                    ) : null}
                                                </TableCell>
                                            </TableRow>
                                        </Fragment>
                                    );
                                })}
                                {rows.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={visibleColumns.length + 1}
                                            className="h-48 whitespace-normal"
                                        >
                                            <Empty>
                                                <EmptyHeader>
                                                    <EmptyMedia variant="icon">
                                                        <Network />
                                                    </EmptyMedia>
                                                    <EmptyTitle>
                                                        {subnets.length === 0
                                                            ? 'Nessuna subnet'
                                                            : 'Nessuna subnet trovata'}
                                                    </EmptyTitle>
                                                </EmptyHeader>
                                                {filtered ? (
                                                    <Button
                                                        variant="outline"
                                                        onClick={() => {
                                                            setSearch('');
                                                            setFilter('all');
                                                            setPage(1);
                                                        }}
                                                    >
                                                        Azzera ricerca e filtri
                                                    </Button>
                                                ) : null}
                                            </Empty>
                                        </TableCell>
                                    </TableRow>
                                ) : null}
                            </TableBody>
                        </Table>
                    </div>
                    {needsPagination ? (
                        <TablePagination
                            summary={`${pageRows.length}/${rows.length}`}
                            page={page}
                            pageCount={pageCount}
                            pageSize={pageSize}
                            onPageChange={setPage}
                            onPageSizeChange={setPageSize}
                        />
                    ) : (
                        <p role="status" className="sr-only">
                            {rows.length} subnet trovate
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}

SubnetIndex.layout = ({ company, site }: Props) => ({
    breadcrumbs: [
        {
            title: 'Subnet',
            href: index({ company: company.id, site: site.id }),
        },
    ],
});
