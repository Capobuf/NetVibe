import { router, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { ObservationRetention } from '@/components/observation-retention';
import { SnipeItMark } from '@/components/snipe-it-mark';
import { UnifiMark } from '@/components/unifi-mark';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { mikrotik as adminMikrotik } from '@/routes/administration/integrations';
import { edit as adminSnipeIt } from '@/routes/administration/integrations/snipe-it';
import { index as adminUnifi } from '@/routes/administration/integrations/unifi';
import { mikrotik as companyMikrotik } from '@/routes/companies/settings/integrations';
import { records as companySnipeIt } from '@/routes/companies/settings/integrations/snipe-it';
import { index as companyUnifi } from '@/routes/companies/settings/integrations/unifi';
import type { Company } from '@/types/company';

export type IntegrationTab = 'snipe-it' | 'unifi' | 'mikrotik';

/** Top selector shared by every integration page, in administration and in company settings. */
export function IntegrationTabs({
    value,
    company,
    children,
}: PropsWithChildren<{
    value: IntegrationTab;
    company: Company | null;
}>) {
    const { auth } = usePage().props;
    const href = (tab: IntegrationTab) =>
        company
            ? {
                  'snipe-it': companySnipeIt(company.id),
                  unifi: companyUnifi(company.id),
                  mikrotik: companyMikrotik(company.id),
              }[tab]
            : {
                  'snipe-it': adminSnipeIt(),
                  unifi: adminUnifi(),
                  mikrotik: adminMikrotik(),
              }[tab];

    return (
        <Tabs
            value={value}
            activationMode="manual"
            className="min-w-0 gap-6"
            onValueChange={(next) => router.visit(href(next as IntegrationTab))}
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
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
                <TabsTrigger value="mikrotik">
                    <MikrotikMark />
                    MikroTik
                </TabsTrigger>
            </TabsList>
            {company && auth.user.is_superadmin && company.observation_retention_days ? (
                <ObservationRetention company={company.id} days={company.observation_retention_days} />
            ) : null}
            </div>
            {children}
        </Tabs>
    );
}
