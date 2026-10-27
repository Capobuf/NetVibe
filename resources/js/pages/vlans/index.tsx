import { Layers } from 'lucide-react';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
    EmptyDescription,
} from '@/components/ui/empty';
import { Head, usePage } from '@inertiajs/react';
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
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6 lg:gap-8">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex flex-col gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight text-balance">
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
                <Card className="gap-0 overflow-hidden py-0">
                    <CardHeader className="py-4">
                        <CardDescription className="tabular-nums">
                            {vlans.length} VLAN
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-0">
                        <Table aria-label="VLAN della sede selezionata">
                            <TableHeader className="bg-muted/50">
                                <TableRow>
                                    <TableHead className="pl-4">
                                        ID VLAN
                                    </TableHead>
                                    <TableHead>Nome</TableHead>
                                    <TableHead>Subnet</TableHead>
                                    {auth.user.is_superadmin ? (
                                        <TableHead>
                                            <span className="sr-only">
                                                Azioni
                                            </span>
                                        </TableHead>
                                    ) : null}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {vlans.map((vlan) => (
                                    <TableRow key={vlan.id}>
                                        <TableCell className="pl-4">
                                            <span className="font-mono font-medium tabular-nums">
                                                {vlan.tag}
                                            </span>
                                        </TableCell>
                                        <TableCell className="max-w-lg break-words whitespace-normal">
                                            {vlan.name ?? ''}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {vlan.subnets_count}
                                        </TableCell>
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
                                            <Empty>
                                                <EmptyHeader>
                                                    <EmptyMedia variant="icon">
                                                        <Layers />
                                                    </EmptyMedia>
                                                    <EmptyTitle>
                                                        Nessuna VLAN
                                                    </EmptyTitle>
                                                    <EmptyDescription>
                                                        Puoi creare una VLAN qui
                                                        oppure durante la
                                                        creazione di una subnet.
                                                    </EmptyDescription>
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
