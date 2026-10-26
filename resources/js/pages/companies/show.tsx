import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Building2,
    Check,
    MapPin,
    Settings2,
    ShieldCheck,
} from 'lucide-react';
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
import { cn } from '@/lib/utils';
import {
    index as administrationIndex,
    show as administrationShow,
} from '@/routes/administration/companies';
import { index, show as companyShow } from '@/routes/companies';
import { show as siteShow } from '@/routes/companies/sites';
import type { Company, Site } from '@/types/company';

export default function CompanyShow({
    company,
    sites,
    selectedSite,
    administration,
}: {
    company: Company;
    sites: Site[];
    selectedSite: Site;
    administration: boolean;
}) {
    const { auth } = usePage().props;
    const multipleSites = sites.length > 1;

    return (
        <>
            <Head title={company.name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex items-center gap-3">
                    <IconButton label="Torna alle aziende" asChild>
                        <Link
                            href={
                                administration ? administrationIndex() : index()
                            }
                        >
                            <ArrowLeft />
                        </Link>
                    </IconButton>
                    {administration ? (
                        <Badge variant="secondary">
                            <ShieldCheck />
                            Amministrazione
                        </Badge>
                    ) : (
                        <span className="text-sm text-muted-foreground">
                            Aziende
                        </span>
                    )}
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
                                        <h1 className="break-words">
                                            {company.name}
                                        </h1>
                                    </CardTitle>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                {administration ? (
                                    <>
                                        <CompanyForm
                                            company={{ ...company, sites }}
                                        />
                                        <IconButton
                                            label="Apri contesto azienda"
                                            asChild
                                        >
                                            <Link
                                                href={companyShow(company.id)}
                                            >
                                                <ArrowRight />
                                            </Link>
                                        </IconButton>
                                    </>
                                ) : auth.user.is_superadmin ? (
                                    <IconButton
                                        label="Amministra azienda"
                                        asChild
                                    >
                                        <Link
                                            href={administrationShow(
                                                company.id,
                                            )}
                                        >
                                            <Settings2 />
                                        </Link>
                                    </IconButton>
                                ) : null}
                            </div>
                        </div>
                    </CardHeader>
                </Card>
                {multipleSites ? (
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
                            {administration ? (
                                <SiteForm company={company} sites={sites} />
                            ) : null}
                        </header>
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {sites.map((site) => {
                                const selected =
                                    !administration &&
                                    selectedSite.id === site.id;
                                return (
                                    <Card
                                        key={site.id}
                                        className={cn(
                                            selected && 'ring-2 ring-primary',
                                        )}
                                    >
                                        <CardHeader>
                                            <div className="mb-3 flex items-center justify-between gap-3">
                                                <div className="flex size-10 items-center justify-center rounded-lg bg-muted">
                                                    <MapPin className="size-5 text-muted-foreground" />
                                                </div>
                                                {selected ? (
                                                    <Badge>
                                                        <Check />
                                                        Selezionata
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="outline">
                                                        Sede
                                                    </Badge>
                                                )}
                                            </div>
                                            <CardTitle>
                                                {administration ? (
                                                    <span className="break-words">
                                                        {site.name}
                                                    </span>
                                                ) : (
                                                    <Link
                                                        href={siteShow({
                                                            company: company.id,
                                                            site: site.id,
                                                        })}
                                                        className="break-words transition-colors hover:text-primary"
                                                    >
                                                        {site.name}
                                                    </Link>
                                                )}
                                            </CardTitle>
                                            <CardDescription className="break-words">
                                                {company.name}
                                            </CardDescription>
                                        </CardHeader>
                                        <CardFooter className="mt-auto justify-end gap-2">
                                            {administration ? (
                                                <SiteForm
                                                    company={company}
                                                    sites={sites}
                                                    site={site}
                                                />
                                            ) : null}
                                            <IconButton
                                                label={`Apri sede ${site.name}`}
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
                                );
                            })}
                        </div>
                    </section>
                ) : administration ? (
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
                ) : null}
            </div>
        </>
    );
}

CompanyShow.layout = { breadcrumbs: [{ title: 'Aziende', href: index() }] };
