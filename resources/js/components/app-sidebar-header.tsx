import { Breadcrumbs } from '@/components/breadcrumbs';
import { CompanySwitcher } from '@/components/companies/company-switcher';
import { SiteSwitcher } from '@/components/companies/site-switcher';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="flex min-h-16 shrink-0 flex-wrap items-center gap-3 border-b border-sidebar-border/50 px-4 py-3 md:px-6">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="ml-auto flex flex-wrap items-center gap-2">
                <CompanySwitcher />
                <SiteSwitcher />
            </div>
        </header>
    );
}
