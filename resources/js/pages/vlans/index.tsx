import {
    ArrowRight,
    ChevronDown,
    ChevronRight,
    Layers,
    Network,
    Search,
} from 'lucide-react';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Head, Link, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { IconButton } from '@/components/icon-button';
import TextLink from '@/components/text-link';
import { TableColumns } from '@/components/table-columns';
import { TableSortHead } from '@/components/table-sort-head';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import { sortTableRows, type TableSort } from '@/lib/table-sort';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import VlanForm from '@/components/networks/vlan-form';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as companiesIndex } from '@/routes/companies';
import { index, show } from '@/routes/companies/sites/vlans';
import { show as subnetShow } from '@/routes/companies/sites/subnets';
import type { Company, Site } from '@/types/company';
import type { Subnet, Vlan } from '@/types/network';

type VlanColumn = 'tag' | 'name' | 'subnets_count';

type Props = {
    company: Company;
    site: Site;
    vlans: (Vlan & { subnets_count: number; subnets: Subnet[] })[];
};

export default function VlanIndex({ company, site, vlans }: Props) {
    const { auth, companyNavigation } = usePage().props;
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<TableSort<VlanColumn>>(null);
    const [expanded, setExpanded] = useState<Record<number, boolean>>({});
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences<VlanColumn>('vlans', [
            { id: 'tag', label: 'ID VLAN', visible: true },
            { id: 'name', label: 'Nome', visible: true },
            { id: 'subnets_count', label: 'Subnet', visible: true },
        ]);
    const visibleColumns = columns.filter((column) => column.visible);
    const filtered = vlans.filter((vlan) =>
        [
            vlan.tag,
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
    const rows = sortTableRows(filtered, sort, (vlan, column) => vlan[column]);
    const context =
        (companyNavigation?.sites.length ?? 0) > 1
            ? `${company.name} · ${site.name}`
            : company.name;
    const formContext = { companyId: company.id, siteId: site.id, context };

    function toggleVlan(id: number) {
        setExpanded((current) => ({ ...current, [id]: !current[id] }));
    }

    return (
        <>
            <Head title={`VLAN · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6 lg:gap-8">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex flex-col gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight text-balance">
                            VLAN
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {context}
                        </p>
                    </div>
                    {auth.user.is_superadmin ? (
                        <VlanForm {...formContext} />
                    ) : null}
                </header>
                <Card className="gap-0 overflow-hidden py-0">
                    <CardHeader className="py-4">
                        <Label htmlFor="vlan-search" className="sr-only">
                            Cerca VLAN o subnet
                        </Label>
                        <InputGroup className="max-w-sm">
                            <InputGroupInput
                                id="vlan-search"
                                type="search"
                                placeholder="Cerca VLAN o subnet…"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                            <InputGroupAddon>
                                <Search aria-hidden="true" />
                            </InputGroupAddon>
                        </InputGroup>
                        <CardDescription className="tabular-nums" role="status">
                            {rows.length} di {vlans.length} VLAN
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-0">
                        <Table
                            aria-label="VLAN della sede selezionata"
                            data-separators={separators}
                            className="data-[separators=true]:[&_tr>:not(:last-child)]:border-r"
                        >
                            <TableHeader className="bg-muted/50">
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
                                {rows.map((vlan) => (
                                    <Fragment key={vlan.id}>
                                        <TableRow
                                            className="cursor-pointer"
                                            onClick={(event) => {
                                                const target =
                                                    event.target as HTMLElement;
                                                if (
                                                    event.currentTarget.contains(
                                                        target,
                                                    ) &&
                                                    !target.closest('a, button')
                                                ) {
                                                    toggleVlan(vlan.id);
                                                }
                                            }}
                                        >
                                            <TableCell>
                                                <IconButton
                                                    variant="ghost"
                                                    label={`${expanded[vlan.id] ? 'Nascondi' : 'Mostra'} subnet della VLAN ${vlan.tag}`}
                                                    aria-expanded={Boolean(
                                                        expanded[vlan.id],
                                                    )}
                                                    onClick={() =>
                                                        toggleVlan(vlan.id)
                                                    }
                                                >
                                                    {expanded[vlan.id] ? (
                                                        <ChevronDown />
                                                    ) : (
                                                        <ChevronRight />
                                                    )}
                                                </IconButton>
                                            </TableCell>
                                            {visibleColumns.map((column) => (
                                                <TableCell
                                                    key={column.id}
                                                    className={
                                                        column.id === 'name'
                                                            ? 'max-w-lg break-words whitespace-normal'
                                                            : 'font-mono tabular-nums'
                                                    }
                                                >
                                                    {column.id === 'tag' ? (
                                                        <TextLink
                                                            href={show({
                                                                company:
                                                                    company.id,
                                                                site: site.id,
                                                                vlan: vlan.id,
                                                            })}
                                                        >
                                                            {vlan.tag}
                                                        </TextLink>
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
                                                                company:
                                                                    company.id,
                                                                site: site.id,
                                                                vlan: vlan.id,
                                                            })}
                                                        >
                                                            <ArrowRight />
                                                        </Link>
                                                    </IconButton>
                                                    {auth.user.is_superadmin ? (
                                                        <VlanForm
                                                            {...formContext}
                                                            vlan={vlan}
                                                        />
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                        {expanded[vlan.id] ? (
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
                                                                        className="shrink-0 font-mono tabular-nums"
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

VlanIndex.layout = ({ company, site }: Props) => ({
    breadcrumbs: [
        { title: 'Aziende', href: companiesIndex() },
        { title: 'VLAN', href: index({ company: company.id, site: site.id }) },
    ],
});
