import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import AdministrationLayout from '@/layouts/administration/layout';
import CompanySettingsLayout from '@/layouts/companies/settings-layout';
import { usePage } from '@inertiajs/react';
import { show as companySettings } from '@/routes/companies/settings';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { index as administration } from '@/routes/administration';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { companyNavigation } = usePage().props;
    const company = companyNavigation?.companies.find(
        (item) => item.id === companyNavigation.currentCompanyId,
    );

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {isCurrentOrParentUrl(administration()) ? (
                <AdministrationLayout>{children}</AdministrationLayout>
            ) : company && isCurrentOrParentUrl(companySettings(company.id)) ? (
                <CompanySettingsLayout company={company}>
                    {children}
                </CompanySettingsLayout>
            ) : (
                children
            )}
        </AppLayoutTemplate>
    );
}
