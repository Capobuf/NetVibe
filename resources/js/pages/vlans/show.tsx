import {
    NetworkExclusionAction,
    NetworkVisibilityFilter,
} from '@/components/networks/network-exclusion';
import { Badge } from '@/components/ui/badge';
import {
    MikrotikIndicator,
    type MikrotikSource,
} from '@/components/networks/mikrotik-source';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Network, Search } from 'lucide-react';
import { useState } from 'react';
import { IconButton } from '@/components/icon-button';
import { TableColumns } from '@/components/table-columns';
import { TableSortHead } from '@/components/table-sort-head';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import { sortTableRows, type TableSort } from '@/lib/table-sort';
import { cn } from '@/lib/utils';
import { index as companiesIndex } from '@/routes/companies';
import { show as subnetShow } from '@/routes/companies/sites/subnets';
import { index, show } from '@/routes/companies/sites/vlans';
import type { Company, Site } from '@/types/company';
import type { Subnet, Vlan } from '@/types/network';

type SubnetColumn = 'cidr' | 'name' | 'description';
type Props = {
    company: Company;
    site: Site;
    vlan: Vlan;
    subnets: Subnet[];
    mikrotikSources: MikrotikSource[];
};

export default function VlanShow({
    company,
    site,
    vlan,
    subnets: allSubnets,
    mikrotikSources,
}: Props) {
    const { auth, companyNavigation } = usePage().props;
    const [visibility, setVisibility] = useState('active');
    const subnets = allSubnets.filter(
        (subnet) =>
            vlan.excluded_at ||
            Boolean(subnet.excluded_at) === (visibility === 'excluded'),
    );
    const excludedCount = allSubnets.filter(
        (subnet) => subnet.excluded_at,
    ).length;
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<TableSort<SubnetColumn>>(null);
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences<SubnetColumn>('vlan-subnets', [
            { id: 'cidr', label: 'CIDR', visible: true },
            { id: 'name', label: 'Nome', visible: true },
            { id: 'description', label: 'Descrizione', visible: true },
        ]);
    const visibleColumns = columns.filter((column) => column.visible);
    const filtered = subnets.filter((subnet) =>
        [subnet.cidr, subnet.name, subnet.description]
            .join(' ')
            .toLocaleLowerCase()
            .includes(search.trim().toLocaleLowerCase()),
    );
    const rows = sortTableRows(
        filtered,
        sort,
        (subnet, column) => subnet[column],
    );
    const context =
        (companyNavigation?.sites.length ?? 0) > 1
            ? `${company.name} · ${site.name}`
            : company.name;

    return (
        <>
            <Head title={`VLAN ${vlan.tag} · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6 lg:gap-8">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-2">
                        <h1 className="text-2xl font-medium tracking-tight text-balance">
                            VLAN {vlan.tag}
                            <MikrotikIndicator sources={mikrotikSources} />
                        </h1>
                        {vlan.excluded_at ? (
                            <Badge variant="outline">
                                Esclusa ·{' '}
                                {new Date(vlan.excluded_at).toLocaleDateString(
                                    'it-IT',
                                )}
                            </Badge>
                        ) : null}
                        {vlan.name ? (
                            <p className="text-base break-words">{vlan.name}</p>
                        ) : null}
                        <p className="text-sm text-muted-foreground">
                            {context}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {auth.canEditIpam ? (
                            <NetworkExclusionAction
                                company={company.id}
                                site={site.id}
                                kind="vlan"
                                id={vlan.id}
                                label={`VLAN ${vlan.tag}`}
                                excluded={Boolean(vlan.excluded_at)}
                                compact={!vlan.excluded_at}
                            />
                        ) : null}
                        <Button variant="outline" asChild>
                            <Link
                                href={index({
                                    company: company.id,
                                    site: site.id,
                                })}
                            >
                                <ArrowLeft data-icon="inline-start" />
                                Elenco VLAN
                            </Link>
                        </Button>
                    </div>
                </header>
                <Card className="gap-0 overflow-hidden py-0">
                    <CardHeader className="flex-row flex-wrap items-center justify-between gap-4 py-4">
                        <div className="flex flex-col gap-2">
                            <CardTitle>Subnet associate</CardTitle>
                            <CardDescription
                                className="tabular-nums"
                                role="status"
                            >
                                {rows.length} di {subnets.length} subnet
                            </CardDescription>
                        </div>
                        {!vlan.excluded_at ? (
                            <NetworkVisibilityFilter
                                value={visibility}
                                onChange={setVisibility}
                                count={excludedCount}
                            />
                        ) : null}
                        <div className="w-full sm:w-72">
                            <Label
                                htmlFor="vlan-subnet-search"
                                className="sr-only"
                            >
                                Cerca subnet
                            </Label>
                            <InputGroup>
                                <InputGroupInput
                                    id="vlan-subnet-search"
                                    type="search"
                                    placeholder="Cerca subnet…"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                                <InputGroupAddon>
                                    <Search aria-hidden="true" />
                                </InputGroupAddon>
                            </InputGroup>
                        </div>
                    </CardHeader>
                    <CardContent className="px-0">
                        <Table
                            aria-label={`Subnet della VLAN ${vlan.tag}`}
                            data-separators={separators}
                            className="data-[separators=true]:[&_tr>:not(:last-child)]:border-r"
                        >
                            <TableHeader className="bg-muted/50">
                                <TableRow>
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
                                {rows.map((subnet) => (
                                    <TableRow key={subnet.id}>
                                        {visibleColumns.map((column) => (
                                            <TableCell
                                                key={column.id}
                                                className={cn(
                                                    column.id === 'cidr'
                                                        ? 'tabular-nums'
                                                        : 'max-w-lg min-w-40 break-words whitespace-normal',
                                                )}
                                            >
                                                {column.id === 'cidr' ? (
                                                    <TextLink
                                                        href={subnetShow({
                                                            company: company.id,
                                                            site: site.id,
                                                            subnet: subnet.id,
                                                        })}
                                                    >
                                                        {subnet.cidr}
                                                    </TextLink>
                                                ) : (
                                                    (subnet[column.id] ?? '—')
                                                )}
                                            </TableCell>
                                        ))}
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                {auth.canEditIpam ? (
                                                    <NetworkExclusionAction
                                                        company={company.id}
                                                        site={site.id}
                                                        kind="subnet"
                                                        id={subnet.id}
                                                        label={subnet.cidr}
                                                        excluded={Boolean(
                                                            subnet.excluded_at ||
                                                            vlan.excluded_at,
                                                        )}
                                                        compact
                                                    />
                                                ) : null}
                                                <IconButton
                                                    label={`Apri subnet ${subnet.cidr}`}
                                                    asChild
                                                >
                                                    <Link
                                                        href={subnetShow({
                                                            company: company.id,
                                                            site: site.id,
                                                            subnet: subnet.id,
                                                        })}
                                                    >
                                                        <ArrowRight />
                                                    </Link>
                                                </IconButton>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {rows.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={visibleColumns.length + 1}
                                            className="h-40 whitespace-normal"
                                        >
                                            <Empty>
                                                <EmptyHeader>
                                                    <EmptyMedia variant="icon">
                                                        <Network />
                                                    </EmptyMedia>
                                                    <EmptyTitle>
                                                        {subnets.length === 0
                                                            ? 'Nessuna subnet associata'
                                                            : 'Nessuna subnet trovata'}
                                                    </EmptyTitle>
                                                </EmptyHeader>
                                            </Empty>
                                        </TableCell>
                                    </TableRow>
                                ) : null}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

VlanShow.layout = ({ company, site, vlan }: Props) => ({
    breadcrumbs: [
        { title: 'Aziende', href: companiesIndex() },
        { title: 'VLAN', href: index({ company: company.id, site: site.id }) },
        {
            title: `VLAN ${vlan.tag}`,
            href: show({ company: company.id, site: site.id, vlan: vlan.id }),
        },
    ],
});
