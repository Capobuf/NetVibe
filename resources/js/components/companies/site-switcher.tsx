import { router, usePage } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as subnetIndex } from '@/routes/companies/sites/subnets';
import { index as vlanIndex } from '@/routes/companies/sites/vlans';

export function SiteSwitcher() {
    const {
        component,
        props: { companyNavigation },
    } = usePage();
    if (
        !companyNavigation?.currentCompanyId ||
        !companyNavigation.currentSiteId ||
        companyNavigation.sites.length < 2
    ) {
        return null;
    }

    const company = companyNavigation.currentCompanyId;
    const index = component === 'vlans/index' ? vlanIndex : subnetIndex;

    return (
        <Select
            value={String(companyNavigation.currentSiteId)}
            onValueChange={(value) =>
                router.visit(index({ company, site: Number(value) }))
            }
        >
            <SelectTrigger
                aria-label="Seleziona sede"
                className="w-44 max-w-[42vw]"
            >
                <MapPin />
                <span className="min-w-0 flex-1 truncate text-left">
                    <SelectValue />
                </span>
            </SelectTrigger>
            <SelectContent align="end">
                <SelectGroup>
                    {companyNavigation.sites.map((site) => (
                        <SelectItem key={site.id} value={String(site.id)}>
                            {site.name}
                        </SelectItem>
                    ))}
                </SelectGroup>
            </SelectContent>
        </Select>
    );
}
