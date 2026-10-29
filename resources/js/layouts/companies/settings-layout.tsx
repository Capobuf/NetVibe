import { router, usePage } from '@inertiajs/react';
import { MapPin, Users } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { show } from '@/routes/companies/settings';
import { index as users } from '@/routes/companies/settings/users';
import type { Company } from '@/types/company';

export default function CompanySettingsLayout({
    company,
    children,
}: PropsWithChildren<{ company: Company }>) {
    const { auth } = usePage().props;
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const tab = isCurrentOrParentUrl(users(company.id)) ? 'users' : 'sites';

    return (
        <section
            className="flex min-w-0 flex-1 flex-col gap-6 pt-4 md:pt-6"
            aria-labelledby="company-settings-heading"
        >
            <header className="flex flex-col gap-1 px-4 md:px-6">
                <h1
                    id="company-settings-heading"
                    className="text-2xl font-semibold tracking-tight"
                >
                    Impostazioni azienda
                </h1>
                <p className="text-sm text-muted-foreground">{company.name}</p>
            </header>
            <Tabs
                value={tab}
                activationMode="manual"
                onValueChange={(value) =>
                    router.visit(
                        value === 'users'
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
                        <TabsTrigger value="users">
                            <Users />
                            Utenti
                        </TabsTrigger>
                    ) : null}
                </TabsList>
                <TabsContent value={tab} className="min-w-0">
                    {children}
                </TabsContent>
            </Tabs>
        </section>
    );
}
