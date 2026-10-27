import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Building2, MapPin } from 'lucide-react';
import CompanyForm from '@/components/companies/company-form';
import SiteForm from '@/components/companies/site-form';
import { IconButton } from '@/components/icon-button';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index } from '@/routes/administration/companies';
import { index as administration } from '@/routes/administration';
import { show as companyShow } from '@/routes/companies';
import { show as siteShow } from '@/routes/companies/sites';
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
            <Head title={'Amministrazione · ' + company.name} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6 lg:gap-8">
                <div className="flex items-center gap-3">
                    <IconButton label="Torna alle aziende" asChild>
                        <Link href={index()}>
                            <ArrowLeft />
                        </Link>
                    </IconButton>
                </div>
                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex min-w-0 items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-muted">
                                    <Building2 className="size-6 text-muted-foreground" />
                                </div>
                                <div className="flex min-w-0 flex-col gap-2">
                                    <CardDescription>Azienda</CardDescription>
                                    <CardTitle>
                                        <h2 className="break-words">
                                            {company.name}
                                        </h2>
                                    </CardTitle>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <CompanyForm company={{ ...company, sites }} />
                                <IconButton
                                    label="Apri subnet dell’azienda"
                                    asChild
                                >
                                    <Link href={companyShow(company.id)}>
                                        <ArrowRight />
                                    </Link>
                                </IconButton>
                            </div>
                        </div>
                    </CardHeader>
                </Card>
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
                                    className="text-lg font-semibold"
                                >
                                    Sedi
                                </h2>
                                <Badge variant="secondary">
                                    {sites.length}
                                </Badge>
                            </div>
                            <SiteForm company={company} sites={sites} />
                        </header>
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {sites.map((site) => (
                                <Card key={site.id}>
                                    <CardHeader>
                                        <CardTitle>
                                            <span className="break-words">
                                                {site.name}
                                            </span>
                                        </CardTitle>
                                        <CardDescription>
                                            {company.name}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardFooter className="mt-auto justify-end gap-2">
                                        <SiteForm
                                            company={company}
                                            sites={sites}
                                            site={site}
                                        />
                                        <IconButton
                                            label={
                                                'Apri subnet di ' + site.name
                                            }
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
                                    </CardFooter>
                                </Card>
                            ))}
                        </div>
                    </section>
                ) : (
                    <Card>
                        <CardHeader className="flex-row items-center justify-between gap-4">
                            <div className="flex flex-col gap-2">
                                <CardTitle>Organizzazione</CardTitle>
                                <CardDescription>
                                    Puoi lavorare direttamente sull’azienda.
                                    Aggiungi altre sedi quando necessario.
                                </CardDescription>
                            </div>
                            <SiteForm company={company} sites={sites} />
                        </CardHeader>
                    </Card>
                )}
            </div>
        </>
    );
}

CompanyShow.layout = {
    breadcrumbs: [
        { title: 'Amministrazione', href: administration() },
        { title: 'Aziende', href: index() },
    ],
};
