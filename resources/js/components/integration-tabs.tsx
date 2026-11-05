import { UnifiMark } from '@/components/unifi-mark';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { SnipeItMark } from '@/components/snipe-it-mark';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { edit as adminSnipeIt } from '@/routes/administration/integrations/snipe-it';
import { index as adminUnifi } from '@/routes/administration/integrations/unifi';
import { show as companyIntegrations } from '@/routes/companies/settings/integrations';
import type { Company } from '@/types/company';

/**
 * Administration pages switch integration with tabs; company pages are
 * reached from the company integrations overview and link back to it.
 */
export function IntegrationTabs({
    value,
    company,
    children,
}: PropsWithChildren<{
    value: 'snipe-it' | 'unifi';
    company: Company | null;
}>) {
    const { auth } = usePage().props;
    if (company) {
        return (
            <Tabs value={value} className="min-w-0 gap-4">
                <Button
                    asChild
                    variant="ghost"
                    size="sm"
                    className="-mb-1 self-start"
                >
                    <Link href={companyIntegrations(company.id)}>
                        <ArrowLeft data-icon="inline-start" />
                        Integrazioni
                    </Link>
                </Button>
                {children}
            </Tabs>
        );
    }
    return (
        <Tabs
            value={value}
            activationMode="manual"
            className="min-w-0 gap-6"
            onValueChange={(next) =>
                router.visit(next === 'unifi' ? adminUnifi() : adminSnipeIt())
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
