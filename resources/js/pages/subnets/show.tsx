import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { IconButton } from '@/components/icon-button';
import IpAddressForm from '@/components/networks/ip-address-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import type { IpAddress, Subnet, Vlan } from '@/types/network';

type Props = {
    company: Company;
    site: Site;
    subnet: Subnet;
    vlan: Vlan | null;
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
}: Props) {
    const { auth, companyNavigation } = usePage().props;
    const context =
        (companyNavigation?.sites.length ?? 0) > 1
            ? `${company.name} · ${site.name}`
            : company.name;
    const formContext = {
        companyId: company.id,
        siteId: site.id,
        subnetId: subnet.id,
        context: `${context} · ${subnet.cidr}`,
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
                    {auth.user.is_superadmin ? (
                        <IpAddressForm key={subnet.id} {...formContext} />
                    ) : null}
                </header>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h2 className="font-semibold">IP documentati</h2>
                        <p className="text-sm text-muted-foreground">
                            {ipAddresses.total}{' '}
                            {ipAddresses.total === 1
                                ? 'indirizzo registrato'
                                : 'indirizzi registrati'}
                            . La documentazione non verifica l’utilizzo reale
                            sulla rete.
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
                <div className="overflow-hidden rounded-lg border">
                    <Table
                        aria-label={`IP documentati nella subnet ${subnet.cidr}`}
                    >
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-4">IPv4</TableHead>
                                <TableHead>Descrizione</TableHead>
                                <TableHead>Hostname</TableHead>
                                <TableHead className="pr-4">MAC</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ipAddresses.data.map((ipAddress) => (
                                <TableRow key={ipAddress.id}>
                                    <TableCell className="pl-4">
                                        <IpAddressForm
                                            {...formContext}
                                            ipAddress={ipAddress}
                                        />
                                    </TableCell>
                                    <TableCell className="max-w-lg min-w-48 break-words whitespace-normal">
                                        {ipAddress.description ?? '—'}
                                    </TableCell>
                                    <TableCell className="max-w-64 break-words whitespace-normal">
                                        {ipAddress.hostname ?? '—'}
                                    </TableCell>
                                    <TableCell className="pr-4 font-mono">
                                        {ipAddress.mac_address ?? '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {ipAddresses.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="h-40 text-center whitespace-normal"
                                    >
                                        <p className="font-medium">
                                            {ipAddresses.total === 0
                                                ? 'Nessun IP documentato'
                                                : 'Nessun IP in questa pagina'}
                                        </p>
                                        <p className="mt-2 text-muted-foreground">
                                            {ipAddresses.total === 0
                                                ? 'Gli indirizzi compariranno qui dopo la registrazione.'
                                                : 'Torna alla pagina precedente per consultare gli indirizzi rimasti.'}
                                        </p>
                                    </TableCell>
                                </TableRow>
                            ) : null}
                        </TableBody>
                    </Table>
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
