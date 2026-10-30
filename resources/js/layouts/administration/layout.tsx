import { router } from '@inertiajs/react';
import { Building2, Mail, Tags, Users, PanelsTopLeft } from 'lucide-react';
import { hardwareEditor } from '@/routes/administration';
import type { PropsWithChildren } from 'react';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { index as companies } from '@/routes/administration/companies';
import { edit as email } from '@/routes/administration/email';
import { index as ipStatuses } from '@/routes/administration/ip-statuses';
import { index as users } from '@/routes/administration/users';

export default function AdministrationLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const tab = isCurrentOrParentUrl(hardwareEditor())
        ? 'hardware-editor'
        : isCurrentOrParentUrl(email())
          ? 'email'
          : isCurrentOrParentUrl(ipStatuses())
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
                        value === 'hardware-editor'
                            ? hardwareEditor()
                            : value === 'email'
                              ? email()
                              : value === 'companies'
                                ? companies()
                                : value === 'users'
                                  ? users()
                                  : ipStatuses(),
                    )
                }
                className="min-w-0 gap-0"
            >
                <div className="overflow-x-auto px-4 py-1 md:px-6">
                    <TabsList aria-label="Amministrazione">
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
                        <TabsTrigger value="email">
                            <Mail />
                            Email
                        </TabsTrigger>
                        <TabsTrigger value="hardware-editor">
                            <PanelsTopLeft />
                            Editor pannelli
                        </TabsTrigger>
                    </TabsList>
                </div>
                <TabsContent value={tab} className="min-w-0">
                    {children}
                </TabsContent>
            </Tabs>
        </section>
    );
}
