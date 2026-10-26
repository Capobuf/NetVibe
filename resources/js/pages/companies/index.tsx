import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    ArrowUpDown,
    Building2,
    ChevronDown,
    ChevronRight,
    MapPin,
    Search,
    Settings2,
    ShieldCheck,
    X,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import CompanyForm from '@/components/companies/company-form';
import SiteForm from '@/components/companies/site-form';
import {
    TableColumns,
    type CompanyColumn,
} from '@/components/companies/table-columns';
import { IconButton } from '@/components/icon-button';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    index as administrationIndex,
    show as administrationShow,
} from '@/routes/administration/companies';
import { index, show } from '@/routes/companies';
import { show as showSite } from '@/routes/companies/sites';
import type { Company, Site } from '@/types/company';

export default function Companies({
    companies,
    administration,
    query,
}: {
    companies: {
        data: (Company & { sites: Site[]; sites_count: number })[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    administration: boolean;
    query: {
        search: string;
        sort: CompanyColumn['id'];
        direction: 'asc' | 'desc';
    };
}) {
    const { auth } = usePage().props;
    const companyRoute = administration ? administrationShow : show;
    const indexRoute = administration ? administrationIndex : index;
    const [columns, setColumns] = useState<CompanyColumn[]>([
        { id: 'name', label: 'Nome azienda', visible: true },
        { id: 'sites_count', label: 'Sedi', visible: true },
    ]);
    const [expanded, setExpanded] = useState<number[]>([]);
    const visibleColumns = columns.filter((column) => column.visible);

    return (
        <>
            <Head
                title={administration ? 'Amministrazione · Aziende' : 'Aziende'}
            />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex items-center justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-2">
                        {administration ? (
                            <Badge variant="secondary">
                                <ShieldCheck />
                                Amministrazione
                            </Badge>
                        ) : null}
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Aziende
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {administration
                                ? 'Gestisci aziende, anagrafiche e sedi.'
                                : 'Scegli l’azienda in cui lavorare.'}
                        </p>
                    </div>
                    {administration ? (
                        <CompanyForm />
                    ) : auth.user.is_superadmin ? (
                        <IconButton label="Amministra aziende" asChild>
                            <Link href={administrationIndex()}>
                                <Settings2 />
                            </Link>
                        </IconButton>
                    ) : null}
                </header>
                <div className="flex flex-col gap-3">
                    <div className="flex items-start justify-between gap-3">
                        <Form
                            {...indexRoute.form()}
                            options={{
                                preserveState: true,
                                preserveScroll: true,
                            }}
                            className="w-full max-w-sm"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Label
                                        htmlFor="company-search"
                                        className="sr-only"
                                    >
                                        Cerca azienda per nome
                                    </Label>
                                    <div className="flex gap-2">
                                        <Input
                                            key={query.search}
                                            id="company-search"
                                            name="search"
                                            type="search"
                                            placeholder="Cerca azienda…"
                                            defaultValue={query.search}
                                            maxLength={255}
                                            aria-invalid={Boolean(
                                                errors.search,
                                            )}
                                            aria-describedby={
                                                errors.search
                                                    ? 'company-search-error'
                                                    : undefined
                                            }
                                        />
                                        <input
                                            type="hidden"
                                            name="sort"
                                            value={query.sort}
                                        />
                                        <input
                                            type="hidden"
                                            name="direction"
                                            value={query.direction}
                                        />
                                        <IconButton
                                            label="Cerca aziende"
                                            type="submit"
                                            disabled={processing}
                                        >
                                            <Search />
                                        </IconButton>
                                        {query.search ? (
                                            <IconButton
                                                label="Azzera ricerca"
                                                asChild
                                            >
                                                <Link
                                                    href={indexRoute({
                                                        query: {
                                                            sort: query.sort,
                                                            direction:
                                                                query.direction,
                                                        },
                                                    })}
                                                    preserveState
                                                    preserveScroll
                                                >
                                                    <X />
                                                </Link>
                                            </IconButton>
                                        ) : null}
                                    </div>
                                    <InputError
                                        id="company-search-error"
                                        message={errors.search}
                                    />
                                </>
                            )}
                        </Form>
                        <TableColumns columns={columns} onChange={setColumns} />
                    </div>
                    <div className="overflow-hidden rounded-lg border">
                        <Table aria-label="Aziende e sedi">
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-12">
                                        <span className="sr-only">
                                            Espandi sedi
                                        </span>
                                    </TableHead>
                                    {visibleColumns.map((column) => (
                                        <TableHead
                                            key={column.id}
                                            scope="col"
                                            aria-sort={
                                                query.sort === column.id
                                                    ? query.direction === 'asc'
                                                        ? 'ascending'
                                                        : 'descending'
                                                    : 'none'
                                            }
                                        >
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={indexRoute({
                                                        query: {
                                                            search: query.search,
                                                            sort: column.id,
                                                            direction:
                                                                query.sort ===
                                                                    column.id &&
                                                                query.direction ===
                                                                    'asc'
                                                                    ? 'desc'
                                                                    : 'asc',
                                                        },
                                                    })}
                                                    preserveState
                                                    preserveScroll
                                                >
                                                    {column.label}
                                                    {query.sort !==
                                                    column.id ? (
                                                        <ArrowUpDown />
                                                    ) : query.direction ===
                                                      'asc' ? (
                                                        <ArrowUp />
                                                    ) : (
                                                        <ArrowDown />
                                                    )}
                                                </Link>
                                            </Button>
                                        </TableHead>
                                    ))}
                                    <TableHead className="w-24 text-right">
                                        <span className="sr-only">Azioni</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {companies.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={visibleColumns.length + 2}
                                            className="h-40 text-center whitespace-normal"
                                        >
                                            <div className="flex flex-col items-center gap-2">
                                                <Building2 className="size-8 text-muted-foreground" />
                                                <p className="font-medium">
                                                    {query.search
                                                        ? 'Nessuna azienda trovata'
                                                        : 'Nessuna azienda'}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {query.search
                                                        ? 'Prova un altro nome o azzera la ricerca.'
                                                        : administration
                                                          ? 'Usa il pulsante + per creare la prima azienda.'
                                                          : 'Le aziende create dall’amministratore compariranno qui.'}
                                                </p>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    companies.data.map((company) => {
                                        const isExpanded = expanded.includes(
                                            company.id,
                                        );
                                        return (
                                            <Fragment key={company.id}>
                                                <TableRow>
                                                    <TableCell>
                                                        {company.sites.length >
                                                        1 ? (
                                                            <IconButton
                                                                variant="ghost"
                                                                label={`${isExpanded ? 'Nascondi' : 'Mostra'} sedi di ${company.name}`}
                                                                aria-expanded={
                                                                    isExpanded
                                                                }
                                                                aria-controls={`company-${company.id}-sites`}
                                                                onClick={() =>
                                                                    setExpanded(
                                                                        (
                                                                            current,
                                                                        ) =>
                                                                            current.includes(
                                                                                company.id,
                                                                            )
                                                                                ? current.filter(
                                                                                      (
                                                                                          id,
                                                                                      ) =>
                                                                                          id !==
                                                                                          company.id,
                                                                                  )
                                                                                : [
                                                                                      ...current,
                                                                                      company.id,
                                                                                  ],
                                                                    )
                                                                }
                                                            >
                                                                {isExpanded ? (
                                                                    <ChevronDown />
                                                                ) : (
                                                                    <ChevronRight />
                                                                )}
                                                            </IconButton>
                                                        ) : null}
                                                    </TableCell>
                                                    {visibleColumns.map(
                                                        (column) => (
                                                            <TableCell
                                                                key={column.id}
                                                            >
                                                                {column.id ===
                                                                'name' ? (
                                                                    <Link
                                                                        href={companyRoute(
                                                                            company.id,
                                                                        )}
                                                                        className="flex items-center gap-3 rounded-sm font-medium transition-colors hover:text-primary focus-visible:outline-ring"
                                                                    >
                                                                        <Building2 className="size-5 shrink-0 text-muted-foreground" />
                                                                        <span className="max-w-lg break-words whitespace-normal">
                                                                            {
                                                                                company.name
                                                                            }
                                                                        </span>
                                                                    </Link>
                                                                ) : company.sites_count >
                                                                  1 ? (
                                                                    <Badge variant="secondary">
                                                                        <MapPin />
                                                                        {
                                                                            company.sites_count
                                                                        }
                                                                    </Badge>
                                                                ) : (
                                                                    <span
                                                                        className="text-muted-foreground"
                                                                        aria-label="Contesto azienda"
                                                                    >
                                                                        —
                                                                    </span>
                                                                )}
                                                            </TableCell>
                                                        ),
                                                    )}
                                                    <TableCell>
                                                        <div className="flex justify-end gap-2">
                                                            {administration ? (
                                                                <CompanyForm
                                                                    company={
                                                                        company
                                                                    }
                                                                />
                                                            ) : null}
                                                            <IconButton
                                                                label={`${administration ? 'Gestisci' : 'Apri'} ${company.name}`}
                                                                variant="ghost"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={companyRoute(
                                                                        company.id,
                                                                    )}
                                                                >
                                                                    <ArrowRight />
                                                                </Link>
                                                            </IconButton>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                                {company.sites.length > 1 ? (
                                                    <TableRow
                                                        id={`company-${company.id}-sites`}
                                                        hidden={!isExpanded}
                                                    >
                                                        <TableCell
                                                            colSpan={
                                                                visibleColumns.length +
                                                                2
                                                            }
                                                            className="bg-muted/30 py-0 pl-14 whitespace-normal"
                                                        >
                                                            {isExpanded ? (
                                                                <ul
                                                                    aria-label={`Sedi di ${company.name}`}
                                                                    className="divide-y"
                                                                >
                                                                    {company.sites.map(
                                                                        (
                                                                            site,
                                                                        ) => (
                                                                            <li
                                                                                key={
                                                                                    site.id
                                                                                }
                                                                                className="flex items-center justify-between gap-3 py-3"
                                                                            >
                                                                                <Link
                                                                                    href={showSite(
                                                                                        {
                                                                                            company:
                                                                                                company.id,
                                                                                            site: site.id,
                                                                                        },
                                                                                    )}
                                                                                    className="flex min-w-0 items-center gap-3 rounded-sm transition-colors hover:text-primary focus-visible:outline-ring"
                                                                                >
                                                                                    <MapPin className="size-4 shrink-0 text-muted-foreground" />
                                                                                    <span className="break-words">
                                                                                        {
                                                                                            site.name
                                                                                        }
                                                                                    </span>
                                                                                </Link>
                                                                                <div className="flex shrink-0 gap-2">
                                                                                    {administration ? (
                                                                                        <SiteForm
                                                                                            company={
                                                                                                company
                                                                                            }
                                                                                            sites={
                                                                                                company.sites
                                                                                            }
                                                                                            site={
                                                                                                site
                                                                                            }
                                                                                        />
                                                                                    ) : null}
                                                                                    <IconButton
                                                                                        label={`Entra in ${site.name}`}
                                                                                        variant="ghost"
                                                                                        asChild
                                                                                    >
                                                                                        <Link
                                                                                            href={showSite(
                                                                                                {
                                                                                                    company:
                                                                                                        company.id,
                                                                                                    site: site.id,
                                                                                                },
                                                                                            )}
                                                                                        >
                                                                                            <ArrowRight />
                                                                                        </Link>
                                                                                    </IconButton>
                                                                                </div>
                                                                            </li>
                                                                        ),
                                                                    )}
                                                                </ul>
                                                            ) : null}
                                                        </TableCell>
                                                    </TableRow>
                                                ) : null}
                                            </Fragment>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    </div>
                    <div className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                        <p>
                            {companies.total}{' '}
                            {companies.total === 1 ? 'azienda' : 'aziende'}
                            {query.search ? ' trovate' : ''}
                        </p>
                        {companies.last_page > 1 ? (
                            <nav
                                aria-label="Pagine delle aziende"
                                className="flex items-center gap-3"
                            >
                                {companies.prev_page_url ? (
                                    <IconButton
                                        label="Pagina precedente"
                                        asChild
                                    >
                                        <Link
                                            href={companies.prev_page_url}
                                            preserveState
                                            preserveScroll
                                        >
                                            <ArrowLeft />
                                        </Link>
                                    </IconButton>
                                ) : (
                                    <IconButton
                                        label="Pagina precedente"
                                        disabled
                                    >
                                        <ArrowLeft />
                                    </IconButton>
                                )}
                                <span>
                                    {companies.current_page} /{' '}
                                    {companies.last_page}
                                </span>
                                {companies.next_page_url ? (
                                    <IconButton
                                        label="Pagina successiva"
                                        asChild
                                    >
                                        <Link
                                            href={companies.next_page_url}
                                            preserveState
                                            preserveScroll
                                        >
                                            <ArrowRight />
                                        </Link>
                                    </IconButton>
                                ) : (
                                    <IconButton
                                        label="Pagina successiva"
                                        disabled
                                    >
                                        <ArrowRight />
                                    </IconButton>
                                )}
                            </nav>
                        ) : null}
                    </div>
                </div>
            </div>
        </>
    );
}

Companies.layout = { breadcrumbs: [{ title: 'Aziende', href: index() }] };
