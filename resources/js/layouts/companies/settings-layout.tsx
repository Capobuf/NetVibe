import { router, usePage } from '@inertiajs/react';
import { MapPin, Users, Plug } from 'lucide-react';
import CompanyForm from '@/components/companies/company-form';
import type { PropsWithChildren } from 'react';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { show } from '@/routes/companies/settings';
import { show as integrations } from '@/routes/companies/settings/integrations';
import { index as users } from '@/routes/companies/settings/users';
import type { Company } from '@/types/company';

export default function CompanySettingsLayout({
    company,
    children,
}: PropsWithChildren<{ company: Company }>) {
    const { auth, companyNavigation } = usePage().props;
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const tab = isCurrentOrParentUrl(integrations(company.id))
        ? 'integrations'
        : isCurrentOrParentUrl(users(company.id))
          ? 'users'
          : 'sites';

    return (
        <section
            className="flex min-w-0 flex-1 flex-col gap-6 pt-4 md:pt-6"
            aria-labelledby="company-settings-heading"
        >
            <header className="flex flex-wrap items-start justify-between gap-4 px-4 md:px-6">
                <div className="flex min-w-0 flex-col gap-1">
                    <h1
                        id="company-settings-heading"
                        className="text-2xl font-medium tracking-tight"
                    >
                        Impostazioni azienda
                    </h1>
                    <p className="text-sm break-words text-muted-foreground">
                        {company.name}
                    </p>
                </div>
                {auth.user.is_superadmin ? (
                    <CompanyForm
                        company={{
                            ...company,
                            sites: companyNavigation?.sites ?? [],
                        }}
                    />
                ) : null}
            </header>
            <Tabs
                value={tab}
                activationMode="manual"
                onValueChange={(value) =>
                    router.visit(
                        value === 'integrations'
                            ? integrations(company.id)
                            : value === 'users'
                              ? users(company.id)
                              : show(company.id),
                    )
                }
                className="min-w-0 gap-0"
            >
                <TabsList
                    aria-label="Impostazioni azienda"
                    className="mx-4 md:mx-6"
                >
                    <TabsTrigger value="sites">
                        <MapPin />
                        Sedi
                    </TabsTrigger>
                    {auth.user.is_superadmin ? (
                        <>
                            <TabsTrigger value="users">
                                <Users />
                                Utenti
                            </TabsTrigger>
                        </>
                    ) : null}
                    <TabsTrigger value="integrations">
                        <Plug />
                        Integrazioni
                    </TabsTrigger>
                </TabsList>
                <TabsContent value={tab} className="min-w-0">
                    {children}
                </TabsContent>
            </Tabs>
        </section>
    );
}
