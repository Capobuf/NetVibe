import { usePage } from '@inertiajs/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { CompanySwitcher } from '@/components/companies/company-switcher';
import { SiteSwitcher } from '@/components/companies/site-switcher';
import { GlobalSearch } from '@/components/global-search';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { navigationArea } = usePage().props;
    return (
        <header className="flex min-h-16 shrink-0 flex-wrap items-center gap-3 border-b px-4 py-3 md:px-6">
            <div className="flex min-w-0 flex-1 items-center gap-3">
                <SidebarTrigger className="-ml-1" />
                <Separator orientation="vertical" className="h-4" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {navigationArea !== 'administration' && (
                <div className="flex w-full flex-col gap-3 sm:flex-row sm:items-center xl:ml-auto xl:w-auto">
                    <GlobalSearch />
                    <div className="flex min-w-0 items-center gap-2">
                        <CompanySwitcher />
                        <SiteSwitcher />
                    </div>
                </div>
            )}
        </header>
    );
}
