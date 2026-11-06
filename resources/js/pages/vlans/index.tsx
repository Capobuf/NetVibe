import {
    NetworkExclusionAction,
    NetworkVisibilityFilter,
} from '@/components/networks/network-exclusion';
import { Badge } from '@/components/ui/badge';
import {
    ArrowRight,
    ChevronDown,
    ChevronRight,
    Layers,
    Network,
} from 'lucide-react';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Head, Link, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { ListPageHeader } from '@/components/list-page-header';
import { TableSearch } from '@/components/table-search';
import { TablePagination } from '@/components/table-pagination';
import { useTablePagination } from '@/hooks/use-table-pagination';
import { Button } from '@/components/ui/button';
import { cn, formatDateTime } from '@/lib/utils';
import { IconButton } from '@/components/icon-button';
import TextLink from '@/components/text-link';
import { TableColumns } from '@/components/table-columns';
import { TableSortHead } from '@/components/table-sort-head';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import { sortTableRows, type TableSort } from '@/lib/table-sort';
import VlanForm from '@/components/networks/vlan-form';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index, show } from '@/routes/companies/sites/vlans';
import { show as subnetShow } from '@/routes/companies/sites/subnets';
import type { Company, Site } from '@/types/company';
import type { Subnet, Vlan } from '@/types/network';

type VlanColumn = 'tag' | 'name' | 'subnets_count' | 'excluded_at';

type Props = {
    company: Company;
    site: Site;
    vlans: (Vlan & { subnets: Subnet[] })[];
};

export default function VlanIndex(props: Props) {
    return <VlanList key={props.site.id} {...props} />;
}

function VlanList({ company, site, vlans: allVlans }: Props) {
    const [visibility, setVisibility] = useState('active');
    const [collapsed, setCollapsed] = useState<Record<number, boolean>>({});
    const excludedCount = allVlans.filter((vlan) => vlan.excluded_at).length;
    const vlans = allVlans
        .filter(
            (vlan) => Boolean(vlan.excluded_at) === (visibility === 'excluded'),
        )
        .map((vlan) => {
            const subnets = vlan.subnets.filter(
                (subnet) => vlan.excluded_at || !subnet.excluded_at,
            );
            return {
                ...vlan,
                subnets,
                subnets_count: subnets.length,
                isCollapsed: collapsed[vlan.id] ?? subnets.length === 0,
            };
        });
    const { auth, companyNavigation } = usePage().props;
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<TableSort<VlanColumn>>(null);
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences<VlanColumn>('vlans', [
            { id: 'tag', label: 'VLAN', visible: true },
            { id: 'name', label: 'Nome', visible: false },
            { id: 'subnets_count', label: 'Subnet', visible: true },
            { id: 'excluded_at', label: 'Data esclusione', visible: false },
        ]);
    const visibleColumns = columns.filter((column) => column.visible);
    const separateName = visibleColumns.some((column) => column.id === 'name');
    const filtered = vlans.filter((vlan) =>
        [
            `VLAN ${vlan.tag}`,
            vlan.name,
            vlan.subnets_count,
            ...vlan.subnets.flatMap((subnet) => [
                subnet.cidr,
                subnet.name,
                subnet.description,
            ]),
        ]
            .join(' ')
            .toLocaleLowerCase()
            .includes(search.trim().toLocaleLowerCase()),
    );
    const rows = sortTableRows(
        filtered,
        sort,
        (vlan, column) => vlan[column] ?? null,
    );
    const {
        pageRows,
        page,
        pageCount,
        pageSize,
        needsPagination,
        setPage,
        setPageSize,
    } = useTablePagination(rows, JSON.stringify([search, sort, visibility]));
    const context =
        (companyNavigation?.sites.length ?? 0) > 1
            ? `${company.name} · ${site.name}`
            : company.name;
    const formContext = { companyId: company.id, siteId: site.id, context };

    function toggleVlan(id: number, isCollapsed: boolean) {
        setCollapsed((current) => ({ ...current, [id]: !isCollapsed }));
    }

    return (
        <>
            <Head title={`VLAN · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col p-4 md:p-6">
                <div className="flex flex-col gap-5 pb-4">
                    <ListPageHeader
                        title="VLAN"
                        icon={Layers}
                        actions={
                            auth.canEditIpam ? (
                                <VlanForm {...formContext} />
                            ) : null
                        }
                    />
                    <div className="flex flex-wrap items-center gap-3">
                        <TableSearch
                            label="Cerca VLAN o subnet"
                            total={vlans.length}
                            value={search}
                            onChange={setSearch}
                        />
                        <NetworkVisibilityFilter
                            value={visibility}
                            onChange={setVisibility}
                            count={excludedCount}
                        />
                    </div>
                </div>
                <div className="min-w-0">
                    <Table
                        aria-label="VLAN della sede selezionata"
                        data-separators={separators}
                        className="data-[separators=true]:[&_tr>:not(:last-child)]:border-r"
                    >
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-12">
                                    <span className="sr-only">
                                        Mostra subnet
                                    </span>
                                </TableHead>
                                {visibleColumns.map((column) => (
                                    <TableSortHead
                                        key={column.id}
                                        column={column}
                                        sort={sort}
                                        onSort={setSort}
                                    />
                                ))}
                                <TableHead className="w-12 text-right">
                                    <span className="sr-only">Azioni</span>
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
                            {pageRows.map((vlan, position) => (
                                <Fragment key={vlan.id}>
                                    <TableRow
                                        className={cn(
                                            'cursor-pointer focus-within:bg-muted/40',
                                            position % 2 === 1 && 'bg-muted/20',
                                        )}
                                        onClick={(event) => {
                                            const target =
                                                event.target as HTMLElement;
                                            if (
                                                event.currentTarget.contains(
                                                    target,
                                                ) &&
                                                window.getSelection()
                                                    ?.isCollapsed !== false &&
                                                !target.closest('a, button')
                                            ) {
                                                toggleVlan(
                                                    vlan.id,
                                                    vlan.isCollapsed,
                                                );
                                            }
                                        }}
                                    >
                                        <TableCell>
                                            <IconButton
                                                variant="ghost"
                                                label={`${vlan.isCollapsed ? 'Mostra' : 'Nascondi'} subnet della VLAN ${vlan.tag}`}
                                                aria-expanded={
                                                    !vlan.isCollapsed
                                                }
                                                onClick={() =>
                                                    toggleVlan(
                                                        vlan.id,
                                                        vlan.isCollapsed,
                                                    )
                                                }
                                            >
                                                {!vlan.isCollapsed ? (
                                                    <ChevronDown />
                                                ) : (
                                                    <ChevronRight />
                                                )}
                                            </IconButton>
                                        </TableCell>
                                        {visibleColumns.map((column) => (
                                            <TableCell
                                                key={column.id}
                                                className={cn(
                                                    'py-4',
                                                    column.id === 'name'
                                                        ? 'max-w-lg break-words whitespace-normal'
                                                        : 'tabular-nums',
                                                    column.id === 'tag' &&
                                                        'text-sm font-medium',
                                                )}
                                            >
                                                {column.id === 'tag' ? (
                                                    <div className="flex flex-col items-start gap-1">
                                                        {!separateName &&
                                                        vlan.name ? (
                                                            <span className="max-w-sm text-xs font-normal break-words whitespace-normal text-muted-foreground">
                                                                {vlan.name}
                                                            </span>
                                                        ) : null}
                                                        <TextLink
                                                            href={show({
                                                                company:
                                                                    company.id,
                                                                site: site.id,
                                                                vlan: vlan.id,
                                                            })}
                                                        >
                                                            VLAN {vlan.tag}
                                                        </TextLink>
                                                        {vlan.excluded_at ? (
                                                            <Badge variant="outline">
                                                                Esclusa
                                                            </Badge>
                                                        ) : null}
                                                    </div>
                                                ) : column.id ===
                                                  'excluded_at' ? (
                                                    vlan.excluded_at ? (
                                                        formatDateTime(
                                                            vlan.excluded_at,
                                                        )
                                                    ) : (
                                                        '—'
                                                    )
                                                ) : (
                                                    (vlan[column.id] ?? '—')
                                                )}
                                            </TableCell>
                                        ))}
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                <IconButton
                                                    label={`Apri VLAN ${vlan.tag}`}
                                                    asChild
                                                >
                                                    <Link
                                                        href={show({
                                                            company: company.id,
                                                            site: site.id,
                                                            vlan: vlan.id,
                                                        })}
                                                    >
                                                        <ArrowRight />
                                                    </Link>
                                                </IconButton>
                                                {auth.canEditIpam ? (
                                                    <>
                                                        {!vlan.excluded_at ? (
                                                            <VlanForm
                                                                {...formContext}
                                                                vlan={vlan}
                                                            />
                                                        ) : null}
                                                        <NetworkExclusionAction
                                                            company={company.id}
                                                            site={site.id}
                                                            kind="vlan"
                                                            id={vlan.id}
                                                            label={`VLAN ${vlan.tag}`}
                                                            excluded={Boolean(
                                                                vlan.excluded_at,
                                                            )}
                                                            compact
                                                        />
                                                    </>
                                                ) : null}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                    {!vlan.isCollapsed ? (
                                        vlan.subnets.length > 0 ? (
                                            vlan.subnets.map((subnet) => (
                                                <TableRow
                                                    key={subnet.id}
                                                    className="bg-muted/20"
                                                >
                                                    <TableCell
                                                        colSpan={
                                                            visibleColumns.length +
                                                            2
                                                        }
                                                        className="py-3 whitespace-normal"
                                                    >
                                                        <div className="flex items-center gap-4 pl-10 sm:pl-14">
                                                            <Network
                                                                className="size-4 shrink-0 text-muted-foreground"
                                                                aria-hidden="true"
                                                            />
                                                            <div className="flex min-w-0 flex-1 flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-6">
                                                                <TextLink
                                                                    href={subnetShow(
                                                                        {
                                                                            company:
                                                                                company.id,
                                                                            site: site.id,
                                                                            subnet: subnet.id,
                                                                        },
                                                                    )}
                                                                    className="shrink-0 tabular-nums"
                                                                >
                                                                    {
                                                                        subnet.cidr
                                                                    }
                                                                </TextLink>
                                                                <div className="flex min-w-0 flex-col gap-1 break-words">
                                                                    {subnet.name ? (
                                                                        <span className="font-medium">
                                                                            {
                                                                                subnet.name
                                                                            }
                                                                        </span>
                                                                    ) : null}
                                                                    {subnet.description ? (
                                                                        <span className="text-muted-foreground">
                                                                            {
                                                                                subnet.description
                                                                            }
                                                                        </span>
                                                                    ) : null}
                                                                </div>
                                                            </div>
                                                            <IconButton
                                                                label={`Apri subnet ${subnet.cidr}`}
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={subnetShow(
                                                                        {
                                                                            company:
                                                                                company.id,
                                                                            site: site.id,
                                                                            subnet: subnet.id,
                                                                        },
                                                                    )}
                                                                >
                                                                    <ArrowRight />
                                                                </Link>
                                                            </IconButton>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        ) : (
                                            <TableRow className="bg-muted/20">
                                                <TableCell
                                                    colSpan={
                                                        visibleColumns.length +
                                                        2
                                                    }
                                                    className="py-4 pl-14 whitespace-normal text-muted-foreground sm:pl-18"
                                                >
                                                    Nessuna subnet associata
                                                </TableCell>
                                            </TableRow>
                                        )
                                    ) : null}
                                </Fragment>
                            ))}
                            {rows.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={visibleColumns.length + 2}
                                        className="h-40 text-center whitespace-normal"
                                    >
                                        <Empty>
                                            <EmptyHeader>
                                                <EmptyMedia variant="icon">
                                                    <Layers />
                                                </EmptyMedia>
                                                <EmptyTitle>
                                                    {search
                                                        ? 'Nessuna VLAN trovata'
                                                        : 'Nessuna VLAN'}
                                                </EmptyTitle>
                                            </EmptyHeader>
                                            {search ? (
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        setSearch('')
                                                    }
                                                >
                                                    Azzera ricerca
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
                        {rows.length} VLAN trovate
                    </p>
                )}
            </div>
        </>
    );
}

VlanIndex.layout = ({ company, site }: Props) => ({
    breadcrumbs: [
        { title: 'VLAN', href: index({ company: company.id, site: site.id }) },
    ],
});
