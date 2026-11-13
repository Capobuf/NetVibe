import { usePage } from '@inertiajs/react';
import { Download, FileSpreadsheet, FileText } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { download } from '@/routes/companies/sites/ipam';
import type { Company, Site } from '@/types/company';

type Format = 'xlsx' | 'csv';

export function ExportFormatItems({
    href,
    label,
}: {
    href: (format: Format) => string;
    label?: string;
}) {
    // Plain navigation: the attachment downloads in place, an error returns to the page with a toast.
    return (
        <DropdownMenuGroup>
            {label ? <DropdownMenuLabel>{label}</DropdownMenuLabel> : null}
            <DropdownMenuItem asChild>
                <a href={href('xlsx')}>
                    <FileSpreadsheet /> Excel (.xlsx)
                </a>
            </DropdownMenuItem>
            <DropdownMenuItem asChild>
                <a href={href('csv')}>
                    <FileText /> CSV (.csv)
                </a>
            </DropdownMenuItem>
        </DropdownMenuGroup>
    );
}

export function IpamExportMenu({
    company,
    site,
    excludedInSite,
    excludedInCompany,
}: {
    company: Company;
    site: Site;
    excludedInSite: boolean;
    excludedInCompany: boolean;
}) {
    const { companyNavigation } = usePage().props;
    const multiSite = (companyNavigation?.sites.length ?? 0) > 1;
    const [scope, setScope] = useState('site');
    const [excluded, setExcluded] = useState(false);
    const hasExcluded =
        scope === 'company' ? excludedInCompany : excludedInSite;
    const keepOpen = (event: Event) => event.preventDefault();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline">
                    <Download data-icon="inline-start" />
                    Esporta
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-60">
                {multiSite ? (
                    <>
                        <DropdownMenuGroup>
                            <DropdownMenuLabel>Ambito</DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                                value={scope}
                                onValueChange={setScope}
                            >
                                <DropdownMenuRadioItem
                                    value="site"
                                    onSelect={keepOpen}
                                >
                                    <span className="truncate">
                                        Sede {site.name}
                                    </span>
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem
                                    value="company"
                                    onSelect={keepOpen}
                                >
                                    Tutte le sedi
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                        </DropdownMenuGroup>
                        <DropdownMenuSeparator />
                    </>
                ) : null}
                {hasExcluded ? (
                    <>
                        <DropdownMenuCheckboxItem
                            checked={excluded}
                            onCheckedChange={(checked) =>
                                setExcluded(checked === true)
                            }
                            onSelect={keepOpen}
                        >
                            Includi reti escluse
                        </DropdownMenuCheckboxItem>
                        <DropdownMenuSeparator />
                    </>
                ) : null}
                <ExportFormatItems
                    href={(format) =>
                        download.url(
                            { company: company.id, site: site.id },
                            {
                                query: {
                                    format,
                                    scope:
                                        multiSite && scope === 'company'
                                            ? 'company'
                                            : undefined,
                                    excluded:
                                        hasExcluded && excluded
                                            ? 1
                                            : undefined,
                                },
                            },
                        )
                    }
                />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
