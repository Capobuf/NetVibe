import { Head, usePage } from '@inertiajs/react';
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
import { index } from '@/routes/companies/sites/vlans';
import type { Company, Site } from '@/types/company';
import type { Vlan } from '@/types/network';

type Props = {
    company: Company;
    site: Site;
    vlans: (Vlan & { subnets_count: number })[];
};

export default function VlanIndex({ company, site, vlans }: Props) {
    const { auth, companyNavigation } = usePage().props;
    const context =
        (companyNavigation?.sites.length ?? 0) > 1
            ? `${company.name} · ${site.name}`
            : company.name;
    const formContext = { companyId: company.id, siteId: site.id, context };

    return (
        <>
            <Head title={`VLAN · ${context}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex flex-col gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            VLAN
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            VLAN di {context}, anche senza subnet.
                        </p>
                    </div>
                    {auth.user.is_superadmin ? (
                        <VlanForm {...formContext} />
                    ) : null}
                </header>
                <p className="text-sm text-muted-foreground">
                    {vlans.length} VLAN
                </p>
                <div className="overflow-hidden rounded-lg border">
                    <Table aria-label="VLAN della sede selezionata">
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-4">ID VLAN</TableHead>
                                <TableHead>Nome</TableHead>
                                <TableHead>Subnet</TableHead>
                                {auth.user.is_superadmin ? (
                                    <TableHead>
                                        <span className="sr-only">Azioni</span>
                                    </TableHead>
                                ) : null}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {vlans.map((vlan) => (
                                <TableRow key={vlan.id}>
                                    <TableCell className="pl-4">
                                        <span className="font-mono font-medium">
                                            {vlan.tag}
                                        </span>
                                    </TableCell>
                                    <TableCell className="max-w-lg break-words whitespace-normal">
                                        {vlan.name ?? '—'}
                                    </TableCell>
                                    <TableCell>{vlan.subnets_count}</TableCell>
                                    {auth.user.is_superadmin ? (
                                        <TableCell className="pr-4 text-right">
                                            <VlanForm
                                                {...formContext}
                                                vlan={vlan}
                                            />
                                        </TableCell>
                                    ) : null}
                                </TableRow>
                            ))}
                            {vlans.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={
                                            auth.user.is_superadmin ? 4 : 3
                                        }
                                        className="h-40 text-center whitespace-normal"
                                    >
                                        <p className="font-medium">
                                            Nessuna VLAN
                                        </p>
                                        <p className="mt-2 text-muted-foreground">
                                            Puoi creare una VLAN qui oppure
                                            durante la creazione di una subnet.
                                        </p>
                                    </TableCell>
                                </TableRow>
                            ) : null}
                        </TableBody>
                    </Table>
                </div>
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
