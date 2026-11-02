import { UnifiMark } from '@/components/unifi-mark';
import { router, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { SnipeItMark } from '@/components/snipe-it-mark';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { edit as adminSnipeIt } from '@/routes/administration/integrations/snipe-it';
import { index as adminUnifi } from '@/routes/administration/integrations/unifi';
import { edit as companySnipeIt } from '@/routes/companies/settings/integrations/snipe-it';
import { index as companyUnifi } from '@/routes/companies/settings/integrations/unifi';
import type { Company } from '@/types/company';

export function IntegrationTabs({
    value,
    company,
    children,
}: PropsWithChildren<{
    value: 'snipe-it' | 'unifi';
    company: Company | null;
}>) {
    const { auth } = usePage().props;
    return (
        <Tabs
            value={value}
            activationMode="manual"
            className="min-w-0 gap-6"
            onValueChange={(next) =>
                router.visit(
                    next === 'unifi'
                        ? company
                            ? companyUnifi(company.id)
                            : adminUnifi()
                        : company
                          ? companySnipeIt(company.id)
                          : adminSnipeIt(),
                )
            }
        >
            <TabsList aria-label="Integrazioni">
                {auth.user.is_superadmin ? (
                    <TabsTrigger value="snipe-it">
                        <SnipeItMark />
                        Snipe-IT
                    </TabsTrigger>
                ) : null}
                <TabsTrigger value="unifi">
                    <UnifiMark />
                    UniFi
                </TabsTrigger>
            </TabsList>
            {children}
        </Tabs>
    );
}
