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
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/data-table';
import { IconButton } from '@/components/icon-button';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { useSiteContext } from '@/hooks/use-site-context';
import { show as subnetShow } from '@/routes/companies/sites/subnets';
import { index, show } from '@/routes/companies/sites/vlans';
import type { Company, Site } from '@/types/company';
import type { Subnet, Vlan } from '@/types/network';

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
    const { auth } = usePage().props;
    const [visibility, setVisibility] = useState('active');
    const subnets = allSubnets.filter(
        (subnet) =>
            vlan.excluded_at ||
            Boolean(subnet.excluded_at) === (visibility === 'excluded'),
    );
    const excludedCount = allSubnets.filter(
        (subnet) => subnet.excluded_at,
    ).length;
    const context = useSiteContext(company, site);
    const subnetUrl = (subnet: Subnet) =>
        subnetShow({ company: company.id, site: site.id, subnet: subnet.id });

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
                <section
                    className="flex flex-col gap-4"
                    aria-labelledby="vlan-subnets-heading"
                >
                    <h2
                        id="vlan-subnets-heading"
                        className="text-base font-medium"
                    >
                        Subnet associate
                    </h2>
                    <DataTable
                        name="vlan-subnets"
                        variant="list"
                        searchLabel="Cerca subnet"
                        rows={subnets}
                        filterKey={visibility}
                        activeFilters={Number(visibility === 'excluded')}
                        filters={
                            !vlan.excluded_at ? (
                                <NetworkVisibilityFilter
                                    value={visibility}
                                    onChange={setVisibility}
                                    count={excludedCount}
                                />
                            ) : null
                        }
                        columns={[
                            { id: 'cidr', label: 'CIDR', visible: true },
                            { id: 'name', label: 'Nome', visible: true },
                            {
                                id: 'description',
                                label: 'Descrizione',
                                visible: true,
                            },
                        ]}
                        value={(subnet, column) => subnet[column]}
                        render={(subnet, column) =>
                            column === 'cidr' ? (
                                <TextLink href={subnetUrl(subnet)}>
                                    {subnet.cidr}
                                </TextLink>
                            ) : (
                                (subnet[column] ?? '—')
                            )
                        }
                        actions={(subnet) => (
                            <>
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
                                    <Link href={subnetUrl(subnet)}>
                                        <ArrowRight />
                                    </Link>
                                </IconButton>
                            </>
                        )}
                        empty="Nessuna subnet associata"
                    />
                </section>
            </div>
        </>
    );
}

VlanShow.layout = ({ company, site, vlan }: Props) => ({
    breadcrumbs: [
        { title: 'VLAN', href: index({ company: company.id, site: site.id }) },
        {
            title: `VLAN ${vlan.tag}`,
            href: show({ company: company.id, site: site.id, vlan: vlan.id }),
        },
    ],
});
