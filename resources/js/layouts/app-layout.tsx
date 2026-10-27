import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import AdministrationLayout from '@/layouts/administration/layout';
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

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {isCurrentOrParentUrl(administration()) ? (
                <AdministrationLayout>{children}</AdministrationLayout>
            ) : (
                children
            )}
        </AppLayoutTemplate>
    );
}
