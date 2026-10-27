import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    ArrowUpDown,
    Search,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { IconButton } from '@/components/icon-button';
import InputError from '@/components/input-error';
import { TableColumns, type TableColumn } from '@/components/table-columns';
import IpAddressForm from '@/components/networks/ip-address-form';
import ExcludedRanges from '@/components/networks/excluded-ranges';
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
import { index as companiesIndex } from '@/routes/companies';
import { index, show } from '@/routes/companies/sites/subnets';
import type { Company, Site } from '@/types/company';
import type { ExcludedRange, IpAddress, Subnet, Vlan } from '@/types/network';

type IpColumn = TableColumn<'ip' | 'hostname' | 'mac_address' | 'description'>;

type Props = {
    company: Company;
    site: Site;
    subnet: Subnet;
    vlan: Vlan | null;
    excludedRanges: ExcludedRange[];
    firstProposableIp: string | null;
    query: {
        search: string;
        sort: IpColumn['id'] | null;
        direction: 'asc' | 'desc';
    };
    ipAddresses: {
        data: IpAddress[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
};

export default function SubnetShow({
    company,
    site,
    subnet,
    vlan,
    ipAddresses,
    excludedRanges,
    firstProposableIp,
    query,
}: Props) {
    const { auth, companyNavigation } = usePage().props;
    const [columns, setColumns] = useState<IpColumn[]>([
        { id: 'hostname', label: 'Hostname', visible: true },
        { id: 'ip', label: 'IP', visible: true },
        { id: 'mac_address', label: 'MAC Address', visible: true },
        { id: 'description', label: 'Descrizione', visible: true },
    ]);
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
    };

    return (
        <>
            <Head title={`${subnet.cidr} · ${context}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-2">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="font-mono text-2xl font-semibold tracking-tight">
                                {subnet.cidr}
                            </h1>
                            <Badge variant={vlan ? 'secondary' : 'outline'}>
                                {vlan
                                    ? `VLAN ${vlan.tag}${vlan.name ? ` · ${vlan.name}` : ''}`
                                    : 'Senza VLAN'}
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {context}
                            {subnet.name ? ` · ${subnet.name}` : ''}
                        </p>
                        {subnet.description ? (
                            <p className="max-w-3xl text-sm break-words text-muted-foreground">
                                {subnet.description}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <ExcludedRanges
                            key={subnet.id}
                            {...formContext}
                            subnetCidr={subnet.cidr}
                        />
                        {auth.user.is_superadmin ? (
                            <IpAddressForm
                                key={`ip-${subnet.id}`}
                                {...formContext}
                            />
                        ) : null}
                    </div>
                </header>
                <div className="flex flex-wrap items-center gap-3">
                    {firstProposableIp !== null ? (
                        auth.user.is_superadmin ? (
                            <IpAddressForm
                                key={`suggestion-${subnet.id}`}
                                {...formContext}
                                suggestedIp={firstProposableIp}
                            />
                        ) : (
                            <p className="text-sm">
                                Primo IP proponibile:{' '}
                                <span className="font-mono">
                                    {firstProposableIp}
                                </span>
                            </p>
                        )
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Nessun IP proponibile: gli indirizzi utilizzabili
                            sono tutti documentati o esclusi.
                        </p>
                    )}
                    {firstProposableIp !== null ? (
                        <p className="text-sm text-muted-foreground">
                            Il suggerimento non prenota l’indirizzo e non
                            verifica la rete.
                        </p>
                    ) : null}
                </div>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h2 className="font-semibold">IP documentati</h2>
                        <p className="text-sm text-muted-foreground">
                            {ipAddresses.total}{' '}
                            {ipAddresses.total === 1
                                ? 'indirizzo registrato'
                                : 'indirizzi registrati'}
                            {query.search ? ' trovati' : ''}
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link
                            href={index({ company: company.id, site: site.id })}
                        >
                            <ArrowLeft data-icon="inline-start" />
                            Subnet
                        </Link>
                    </Button>
                </div>
                <div className="flex flex-col gap-3">
                    <div className="flex items-start justify-between gap-3">
                        <Form
                            {...show.form(route)}
                            options={{
                                preserveState: true,
                                preserveScroll: true,
                            }}
                            className="w-full max-w-lg"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Label
                                        htmlFor="ip-search"
                                        className="sr-only"
                                    >
                                        Cerca per IP, hostname, MAC o
                                        descrizione
                                    </Label>
                                    <div className="flex gap-2">
                                        <Input
                                            key={query.search}
                                            id="ip-search"
                                            name="search"
                                            type="search"
                                            placeholder="Cerca IP, hostname, MAC…"
                                            defaultValue={query.search}
                                            maxLength={255}
                                            aria-invalid={Boolean(
                                                errors.search,
                                            )}
                                            aria-describedby={
                                                errors.search
                                                    ? 'ip-search-error'
                                                    : undefined
                                            }
                                        />
                                        <input
                                            type="hidden"
                                            name="sort"
                                            value={query.sort ?? ''}
                                        />
                                        <input
                                            type="hidden"
                                            name="direction"
                                            value={query.direction}
                                        />
                                        <IconButton
                                            label="Cerca IP"
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
                                                    href={show(route, {
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
                                        id="ip-search-error"
                                        message={errors.search}
                                    />
                                </>
                            )}
                        </Form>
                    </div>
                    <div className="overflow-hidden rounded-lg border">
                        <Table
                            aria-label={`IP documentati nella subnet ${subnet.cidr}`}
                        >
                            <TableHeader>
                                <TableRow>
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
                                                    href={show(route, {
                                                        query:
                                                            query.sort ===
                                                                column.id &&
                                                            query.direction ===
                                                                'desc'
                                                                ? {
                                                                      search: query.search,
                                                                  }
                                                                : {
                                                                      search: query.search,
                                                                      sort: column.id,
                                                                      direction:
                                                                          query.sort ===
                                                                          column.id
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
                                    <TableHead className="w-12 text-right">
                                        <span className="sr-only">Azioni</span>
                                        <TableColumns
                                            columns={columns}
                                            onChange={setColumns}
                                        />
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {ipAddresses.data.map((ipAddress) => (
                                    <TableRow key={ipAddress.id}>
                                        {visibleColumns.map((column) => (
                                            <TableCell
                                                key={column.id}
                                                className={
                                                    column.id === 'ip' ||
                                                    column.id === 'mac_address'
                                                        ? 'px-4 font-mono'
                                                        : 'max-w-lg break-words whitespace-pre-wrap'
                                                }
                                            >
                                                {ipAddress[column.id] ?? '—'}
                                            </TableCell>
                                        ))}
                                        <TableCell className="text-right">
                                            <IpAddressForm
                                                {...formContext}
                                                ipAddress={ipAddress}
                                                iconTrigger
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {ipAddresses.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={visibleColumns.length + 1}
                                            className="h-40 text-center whitespace-normal"
                                        >
                                            <p className="font-medium">
                                                {query.search
                                                    ? 'Nessun IP trovato'
                                                    : ipAddresses.total === 0
                                                      ? 'Nessun IP documentato'
                                                      : 'Nessun IP in questa pagina'}
                                            </p>
                                            <p className="mt-2 text-muted-foreground">
                                                {query.search
                                                    ? 'Prova un altro termine o azzera la ricerca.'
                                                    : ipAddresses.total === 0
                                                      ? 'Gli indirizzi compariranno qui dopo la registrazione.'
                                                      : 'Torna alla pagina precedente per consultare gli indirizzi rimasti.'}
                                            </p>
                                        </TableCell>
                                    </TableRow>
                                ) : null}
                            </TableBody>
                        </Table>
                    </div>
                </div>
                {ipAddresses.last_page > 1 || ipAddresses.current_page > 1 ? (
                    <nav
                        aria-label="Pagine degli IP documentati"
                        className="flex items-center justify-end gap-3 text-sm text-muted-foreground"
                    >
                        {ipAddresses.prev_page_url ? (
                            <IconButton label="Pagina precedente" asChild>
                                <Link
                                    href={ipAddresses.prev_page_url}
                                    preserveState
                                    preserveScroll
                                >
                                    <ArrowLeft />
                                </Link>
                            </IconButton>
                        ) : (
                            <IconButton label="Pagina precedente" disabled>
                                <ArrowLeft />
                            </IconButton>
                        )}
                        <span>Pagina {ipAddresses.current_page}</span>
                        {ipAddresses.next_page_url ? (
                            <IconButton label="Pagina successiva" asChild>
                                <Link
                                    href={ipAddresses.next_page_url}
                                    preserveState
                                    preserveScroll
                                >
                                    <ArrowRight />
                                </Link>
                            </IconButton>
                        ) : (
                            <IconButton label="Pagina successiva" disabled>
                                <ArrowRight />
                            </IconButton>
                        )}
                    </nav>
                ) : null}
            </div>
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
