import { Breadcrumbs } from '@/components/breadcrumbs';
import { CompanySwitcher } from '@/components/companies/company-switcher';
import { SiteSwitcher } from '@/components/companies/site-switcher';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="flex min-h-16 shrink-0 flex-wrap items-center gap-3 border-b px-4 py-3 md:px-6">
            <div className="flex min-w-0 flex-1 items-center gap-3">
                <SidebarTrigger className="-ml-1" />
                <Separator orientation="vertical" className="h-4" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="flex w-full items-center gap-2 sm:ml-auto sm:w-auto">
                <CompanySwitcher />
                <SiteSwitcher />
            </div>
        </header>
    );
}
