import { usePage } from '@inertiajs/react';
import type { Company, Site } from '@/types/company';

// The site name is only shown when the company has more than one site.
export function useSiteContext(company: Company, site: Site) {
    const { companyNavigation } = usePage().props;
    return (companyNavigation?.sites.length ?? 0) > 1
        ? `${company.name} · ${site.name}`
        : company.name;
}
