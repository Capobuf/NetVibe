import { Network, Search } from 'lucide-react';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
    EmptyDescription,
} from '@/components/ui/empty';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { TableColumns } from '@/components/table-columns';
import { TableSortHead } from '@/components/table-sort-head';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import { sortTableRows, type TableSort } from '@/lib/table-sort';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import SubnetForm from '@/components/networks/subnet-form';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
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
import { index as companiesIndex } from '@/routes/companies';
import { index, show } from '@/routes/companies/sites/subnets';
import type { Company, Site } from '@/types/company';
import type { Subnet, Vlan } from '@/types/network';

type SubnetColumn = 'cidr' | 'details' | 'vlan';

type Props = { company: Company; site: Site; subnets: Subnet[]; vlans: Vlan[] };

export default function SubnetIndex({ company, site, subnets, vlans }: Props) {
    const { auth, companyNavigation } = usePage().props;
    const [filter, setFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<TableSort<SubnetColumn>>(null);
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences<SubnetColumn>('subnets', [
            { id: 'cidr', label: 'CIDR', visible: true },
            { id: 'details', label: 'Nome e descrizione', visible: true },
            { id: 'vlan', label: 'VLAN', visible: true },
        ]);
    const visibleColumns = columns.filter((column) => column.visible);
    const context =
        (companyNavigation?.sites.length ?? 0) > 1
            ? `${company.name} · ${site.name}`
            : company.name;
    const formContext = {
        companyId: company.id,
        siteId: site.id,
        context,
        vlans,
    };
    const vlansById = new Map(vlans.map((vlan) => [vlan.id, vlan]));
    const filteredSubnets = subnets.filter((subnet) => {
        const vlan =
            subnet.vlan_id === null ? null : vlansById.get(subnet.vlan_id);
        const matchesVlan =
            filter === 'all' ||
            (filter === 'none'
                ? subnet.vlan_id === null
                : String(subnet.vlan_id) === filter);
        const text = [
            subnet.cidr,
            subnet.name,
            subnet.description,
            vlan ? `VLAN ${vlan.tag} ${vlan.name ?? ''}` : 'Senza VLAN',
        ]
            .join(' ')
            .toLocaleLowerCase();
        return matchesVlan && text.includes(search.trim().toLocaleLowerCase());
    });
    const visibleSubnets = sortTableRows(
        filteredSubnets,
        sort,
        (subnet, column) => {
            if (column === 'cidr') return subnet.cidr;
            if (column === 'details')
                return [subnet.name, subnet.description]
                    .filter(Boolean)
                    .join(' ');
            return subnet.vlan_id === null
                ? null
                : (vlansById.get(subnet.vlan_id)?.tag ?? null);
        },
    );

    return (
        <>
            <Head title={`Subnet · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6 lg:gap-8">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex flex-col gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight text-balance">
                            Subnet
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {context}
                        </p>
                    </div>
                    {auth.user.is_superadmin ? (
                        <SubnetForm {...formContext} />
                    ) : null}
                </header>
                <Card className="gap-0 overflow-hidden py-0">
                    <CardHeader className="flex-row flex-wrap items-center justify-between gap-3 py-4">
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-1">
                            <Label htmlFor="subnet-search" className="sr-only">
                                Cerca subnet
                            </Label>
                            <InputGroup className="max-w-sm">
                                <InputGroupInput
                                    id="subnet-search"
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
                            <CardDescription
                                className="tabular-nums"
                                role="status"
                            >
                                {visibleSubnets.length} di {subnets.length}{' '}
                                subnet
                            </CardDescription>
                        </div>
                        <div className="flex items-center gap-2">
                            <Label htmlFor="subnet-vlan-filter">VLAN</Label>
                            <Select value={filter} onValueChange={setFilter}>
                                <SelectTrigger
                                    id="subnet-vlan-filter"
                                    className="w-48 sm:w-56"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectItem value="all">
                                            Tutte le subnet
                                        </SelectItem>
                                        <SelectItem value="none">
                                            Senza VLAN
                                        </SelectItem>
                                        {vlans.map((vlan) => (
                                            <SelectItem
                                                key={vlan.id}
                                                value={String(vlan.id)}
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
                        </div>
                    </CardHeader>
                    <CardContent className="px-0">
                        <Table
                            aria-label="Subnet della sede selezionata"
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
                                {visibleSubnets.map((subnet) => {
                                    const vlan =
                                        subnet.vlan_id === null
                                            ? null
                                            : vlansById.get(subnet.vlan_id);
                                    return (
                                        <TableRow key={subnet.id}>
                                            {visibleColumns.map((column) => (
                                                <TableCell
                                                    key={column.id}
                                                    className={
                                                        column.id === 'cidr'
                                                            ? 'whitespace-nowrap'
                                                            : 'max-w-lg min-w-40 whitespace-normal'
                                                    }
                                                >
                                                    {column.id === 'cidr' ? (
                                                        <Link
                                                            href={show({
                                                                company:
                                                                    company.id,
                                                                site: site.id,
                                                                subnet: subnet.id,
                                                            })}
                                                            className="rounded-sm font-mono font-medium tabular-nums transition-colors hover:text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                                        >
                                                            {subnet.cidr}
                                                        </Link>
                                                    ) : column.id ===
                                                      'details' ? (
                                                        <div className="flex flex-col gap-1 break-words">
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
                                                    ) : (
                                                        <>
                                                            <Badge
                                                                variant={
                                                                    vlan
                                                                        ? 'secondary'
                                                                        : 'outline'
                                                                }
                                                            >
                                                                {vlan
                                                                    ? `VLAN ${vlan.tag}`
                                                                    : 'Senza VLAN'}
                                                            </Badge>
                                                            {vlan?.name ? (
                                                                <p className="mt-1 max-w-48 text-xs break-words whitespace-normal text-muted-foreground">
                                                                    {vlan.name}
                                                                </p>
                                                            ) : null}
                                                        </>
                                                    )}
                                                </TableCell>
                                            ))}
                                            <TableCell className="text-right">
                                                {auth.user.is_superadmin ? (
                                                    <SubnetForm
                                                        {...formContext}
                                                        subnet={subnet}
                                                    />
                                                ) : null}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {visibleSubnets.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={visibleColumns.length + 1}
                                            className="h-40 text-center whitespace-normal"
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
                                                    {subnets.length > 0 ? (
                                                        <EmptyDescription>
                                                            Modifica la ricerca
                                                            o il filtro VLAN.
                                                        </EmptyDescription>
                                                    ) : null}
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

SubnetIndex.layout = ({ company, site }: Props) => ({
    breadcrumbs: [
        { title: 'Aziende', href: companiesIndex() },
        {
            title: 'Subnet',
            href: index({ company: company.id, site: site.id }),
        },
    ],
});
