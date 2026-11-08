import { Head, Link } from '@inertiajs/react';
import { ArrowRight, MapPin } from 'lucide-react';
import SiteForm from '@/components/companies/site-form';
import { DataTable } from '@/components/data-table';
import { IconButton } from '@/components/icon-button';
import { Badge } from '@/components/ui/badge';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
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
                        <DataTable
                            name="sites"
                            variant="list"
                            searchLabel="Cerca sedi"
                            rows={sites}
                            columns={[
                                { id: 'name', label: 'Nome sede', visible: true },
                            ]}
                            value={(site, column) => site[column]}
                            render={(site) => (
                                <Link
                                    href={siteShow({
                                        company: company.id,
                                        site: site.id,
                                    })}
                                    className="flex items-center gap-3 rounded-sm transition-colors hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                >
                                    <MapPin className="size-4 shrink-0 text-muted-foreground" />
                                    {site.name}
                                </Link>
                            )}
                            actions={(site) => (
                                <>
                                    <SiteForm
                                        company={company}
                                        sites={sites}
                                        site={site}
                                    />
                                    <IconButton
                                        label={'Apri subnet di ' + site.name}
                                        asChild
                                    >
                                        <Link
                                            href={siteShow({
                                                company: company.id,
                                                site: site.id,
                                            })}
                                        >
                                            <ArrowRight />
                                        </Link>
                                    </IconButton>
                                </>
                            )}
                            empty="Nessuna sede"
                        />
                    </section>
                ) : (
                    <Card>
                        <CardHeader className="flex-row flex-wrap items-center justify-between gap-4">
                            <CardTitle>Organizzazione</CardTitle>
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
