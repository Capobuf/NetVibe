import { router, usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index, show } from '@/routes/companies';

export function CompanySwitcher() {
    const { companyNavigation } = usePage().props;

    if (!companyNavigation) {
        return null;
    }

    return (
        <Select
            value={companyNavigation.currentCompanyId?.toString() ?? ''}
            onValueChange={(value) =>
                router.visit(value === 'all' ? index() : show(Number(value)))
            }
        >
            <SelectTrigger
                aria-label="Seleziona azienda"
                className="ml-auto w-56 max-w-[48vw]"
            >
                <Building2 />
                <span className="min-w-0 flex-1 truncate text-left">
                    <SelectValue placeholder="Aziende" />
                </span>
            </SelectTrigger>
            <SelectContent align="end">
                <SelectGroup>
                    <SelectLabel>Aziende</SelectLabel>
                    {companyNavigation.companies.map((company) => (
                        <SelectItem key={company.id} value={String(company.id)}>
                            {company.name}
                        </SelectItem>
                    ))}
                </SelectGroup>
                <SelectSeparator />
                <SelectGroup>
                    <SelectItem value="all">Tutte le aziende</SelectItem>
                </SelectGroup>
            </SelectContent>
        </Select>
    );
}
