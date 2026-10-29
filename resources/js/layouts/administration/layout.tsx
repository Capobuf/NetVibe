import { router } from '@inertiajs/react';
import { Building2, Tags, Users } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { index as companies } from '@/routes/administration/companies';
import { index as ipStatuses } from '@/routes/administration/ip-statuses';
import { index as users } from '@/routes/administration/users';

export default function AdministrationLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const tab = isCurrentOrParentUrl(ipStatuses())
        ? 'ip-statuses'
        : isCurrentOrParentUrl(users())
          ? 'users'
          : 'companies';

    return (
        <section
            className="flex min-w-0 flex-1 flex-col gap-6 pt-4 md:pt-6"
            aria-labelledby="administration-heading"
        >
            <h1
                id="administration-heading"
                className="px-4 text-2xl font-semibold tracking-tight md:px-6"
            >
                Amministrazione
            </h1>
            <Tabs
                value={tab}
                activationMode="manual"
                onValueChange={(value) =>
                    router.visit(
                        value === 'companies'
                            ? companies()
                            : value === 'users'
                              ? users()
                              : ipStatuses(),
                    )
                }
                className="min-w-0 gap-0"
            >
                <TabsList aria-label="Amministrazione" className="mx-4 md:mx-6">
                    <TabsTrigger value="companies">
                        <Building2 />
                        Aziende
                    </TabsTrigger>
                    <TabsTrigger value="ip-statuses">
                        <Tags />
                        Stati IP
                    </TabsTrigger>
                    <TabsTrigger value="users">
                        <Users />
                        Utenti
                    </TabsTrigger>
                </TabsList>
                <TabsContent value={tab} className="min-w-0">
                    {children}
                </TabsContent>
            </Tabs>
        </section>
    );
}
