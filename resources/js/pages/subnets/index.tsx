import { Network } from 'lucide-react';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
    EmptyDescription,
} from '@/components/ui/empty';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
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

type Props = { company: Company; site: Site; subnets: Subnet[]; vlans: Vlan[] };

export default function SubnetIndex({ company, site, subnets, vlans }: Props) {
    const { auth, companyNavigation } = usePage().props;
    const [filter, setFilter] = useState('all');
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
    const visibleSubnets = subnets.filter(
        (subnet) =>
            filter === 'all' ||
            (filter === 'none'
                ? subnet.vlan_id === null
                : String(subnet.vlan_id) === filter),
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
                            Reti IPv4 di {context}.
                        </p>
                    </div>
                    {auth.user.is_superadmin ? (
                        <SubnetForm {...formContext} />
                    ) : null}
                </header>
                <Card className="gap-0 overflow-hidden py-0">
                    <CardHeader className="flex-row flex-wrap items-center justify-between gap-3 py-4">
                        <CardDescription className="tabular-nums">
                            {visibleSubnets.length} di {subnets.length} subnet
                        </CardDescription>
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
                        <Table aria-label="Subnet della sede selezionata">
                            <TableHeader className="bg-muted/50">
                                <TableRow>
                                    <TableHead className="pl-4">CIDR</TableHead>
                                    <TableHead>Nome e descrizione</TableHead>
                                    <TableHead>VLAN</TableHead>
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
                                {visibleSubnets.map((subnet) => {
                                    const vlan =
                                        subnet.vlan_id === null
                                            ? null
                                            : vlansById.get(subnet.vlan_id);
                                    return (
                                        <TableRow key={subnet.id}>
                                            <TableCell className="pl-4">
                                                <Link
                                                    href={show({
                                                        company: company.id,
                                                        site: site.id,
                                                        subnet: subnet.id,
                                                    })}
                                                    className="rounded-sm font-mono font-medium tabular-nums transition-colors hover:text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                                >
                                                    {subnet.cidr}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="max-w-lg min-w-48 whitespace-normal">
                                                <div className="flex flex-col gap-1 break-words">
                                                    {subnet.name ? (
                                                        <span className="font-medium">
                                                            {subnet.name}
                                                        </span>
                                                    ) : null}
                                                    {subnet.description ? (
                                                        <span className="text-muted-foreground">
                                                            {subnet.description}
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                            <TableCell>
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
                                            </TableCell>
                                            {auth.user.is_superadmin ? (
                                                <TableCell className="pr-4 text-right">
                                                    <SubnetForm
                                                        {...formContext}
                                                        subnet={subnet}
                                                    />
                                                </TableCell>
                                            ) : null}
                                        </TableRow>
                                    );
                                })}
                                {visibleSubnets.length === 0 ? (
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
                                                        <Network />
                                                    </EmptyMedia>
                                                    <EmptyTitle>
                                                        {subnets.length === 0
                                                            ? 'Nessuna subnet'
                                                            : 'Nessuna subnet per questa VLAN'}
                                                    </EmptyTitle>
                                                    <EmptyDescription>
                                                        {subnets.length === 0
                                                            ? 'Per creare la prima rete basta il CIDR. La VLAN è facoltativa.'
                                                            : 'Scegli un’altra VLAN o mostra tutte le subnet.'}
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

SubnetIndex.layout = ({ company, site }: Props) => ({
    breadcrumbs: [
        { title: 'Aziende', href: companiesIndex() },
        {
            title: 'Subnet',
            href: index({ company: company.id, site: site.id }),
        },
    ],
});
