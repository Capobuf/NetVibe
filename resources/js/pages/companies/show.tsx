import { Head, Link } from '@inertiajs/react';
import { ArrowRight, MapPin, Search } from 'lucide-react';
import { useState } from 'react';
import SiteForm from '@/components/companies/site-form';
import { IconButton } from '@/components/icon-button';
import { TableColumns } from '@/components/table-columns';
import { TableSortHead } from '@/components/table-sort-head';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
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
import { show as companyShow } from '@/routes/companies';
import { show as siteShow } from '@/routes/companies/sites';
import { show as settingsShow } from '@/routes/companies/settings';
import type { Company, Site } from '@/types/company';

export default function CompanyShow({
    company,
    sites,
}: {
    company: Company;
    sites: Site[];
}) {
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<TableSort<'name'>>(null);
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences<'name'>('sites', [
            { id: 'name', label: 'Nome sede', visible: true },
        ]);
    const visibleColumns = columns.filter((column) => column.visible);
    const rows = sortTableRows(
        sites.filter((site) =>
            (site.name ?? '')
                .toLocaleLowerCase()
                .includes(search.trim().toLocaleLowerCase()),
        ),
        sort,
        (site, column) => site[column],
    );

    return (
        <>
            <Head
                title={`Sedi · ${company.name}`}
            />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6 lg:gap-8">
                {sites.length > 1 ? (
                    <section
                        className="flex flex-col gap-4"
                        aria-labelledby="sites-heading"
                    >
                        <header className="flex items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <MapPin className="size-5 text-muted-foreground" />
                                <h2
                                    id="sites-heading"
                                    className="text-base font-medium"
                                >
                                    Sedi
                                </h2>
                                <Badge variant="secondary">
                                    {sites.length}
                                </Badge>
                            </div>
                            <SiteForm
                                company={company}
                                sites={sites}
                            />
                        </header>
                        <Card className="gap-0 overflow-hidden py-0">
                            <CardHeader className="gap-3 py-4">
                                <Label
                                    htmlFor="site-search"
                                    className="sr-only"
                                >
                                    Cerca sedi
                                </Label>
                                <InputGroup className="max-w-sm">
                                    <InputGroupInput
                                        id="site-search"
                                        type="search"
                                        placeholder="Cerca sedi…"
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                    />
                                    <InputGroupAddon>
                                        <Search aria-hidden="true" />
                                    </InputGroupAddon>
                                </InputGroup>
                                <p
                                    role="status"
                                    className="text-sm text-muted-foreground tabular-nums"
                                >
                                    {rows.length} di {sites.length} sedi
                                </p>
                            </CardHeader>
                            <CardContent className="px-0">
                                <Table
                                    aria-label="Sedi dell’azienda"
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
                                                    onSort={setSort}
                                                />
                                            ))}
                                            <TableHead className="w-24 text-right">
                                                <span className="sr-only">
                                                    Azioni
                                                </span>
                                                <TableColumns
                                                    columns={columns}
                                                    onChange={setColumns}
                                                    separators={separators}
                                                    onSeparatorsChange={
                                                        setSeparators
                                                    }
                                                />
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rows.map((site) => (
                                            <TableRow key={site.id}>
                                                {visibleColumns.map(
                                                    (column) => (
                                                        <TableCell
                                                            key={column.id}
                                                        >
                                                            <Link
                                                                href={siteShow({
                                                                    company:
                                                                        company.id,
                                                                    site: site.id,
                                                                })}
                                                                className="flex items-center gap-3 rounded-sm font-medium transition-colors hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                                            >
                                                                <MapPin className="size-4 shrink-0 text-muted-foreground" />
                                                                <span className="max-w-lg break-words whitespace-normal">
                                                                    {
                                                                        site[
                                                                            column
                                                                                .id
                                                                        ]
                                                                    }
                                                                </span>
                                                            </Link>
                                                        </TableCell>
                                                    ),
                                                )}
                                                <TableCell>
                                                    <div className="flex justify-end gap-2">
                                                        <SiteForm
                                                            company={company}
                                                            sites={sites}
                                                            site={site}
                                                        />
                                                        <IconButton
                                                            label={
                                                                'Apri subnet di ' +
                                                                site.name
                                                            }
                                                            asChild
                                                        >
                                                            <Link
                                                                href={siteShow({
                                                                    company:
                                                                        company.id,
                                                                    site: site.id,
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
                                                    colSpan={
                                                        visibleColumns.length +
                                                        1
                                                    }
                                                >
                                                    <Empty>
                                                        <EmptyHeader>
                                                            <EmptyTitle>
                                                                Nessuna sede
                                                                trovata
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
                    </section>
                ) : (
                    <Card>
                        <CardHeader className="flex-row flex-wrap items-center justify-between gap-4">
                            <div className="flex flex-col gap-2">
                                <CardTitle>Organizzazione</CardTitle>
                                <CardDescription>
                                    Puoi lavorare direttamente sull’azienda.
                                    Aggiungi altre sedi quando necessario.
                                </CardDescription>
                            </div>
                            <SiteForm
                                company={company}
                                sites={sites}
                            />
                        </CardHeader>
                    </Card>
                )}
            </div>
        </>
    );
}

CompanyShow.layout = ({ company }: { company: Company }) => ({
    breadcrumbs: [
        { title: company.name, href: companyShow(company.id) },
        { title: 'Impostazioni', href: settingsShow(company.id) },
    ],
});
