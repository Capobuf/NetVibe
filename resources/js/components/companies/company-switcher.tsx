import { router, usePage } from '@inertiajs/react';
import {
    Building2,
    Check,
    ChevronsUpDown,
    MapPin,
    Plus,
    Search,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import CompanyForm from '@/components/companies/company-form';
import { Button } from '@/components/ui/button';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { show } from '@/routes/companies';
import { index as deviceIndex } from '@/routes/companies/sites/devices';
import { index as rackIndex } from '@/routes/companies/sites/racks';
import { index as searchIndex } from '@/routes/companies/sites/search';
import { index as subnetIndex } from '@/routes/companies/sites/subnets';
import { index as topologyIndex } from '@/routes/companies/sites/topology';
import { index as vlanIndex } from '@/routes/companies/sites/vlans';

// Search only pays off once the list no longer fits at a glance.
const SEARCH_FROM = 8;

export function CompanySwitcher() {
    const {
        component,
        url,
        props: { auth, companyNavigation, navigationArea },
    } = usePage();
    const [open, setOpen] = useState(false);
    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState('');

    if (!companyNavigation) return null;
    const { companies, sites, currentCompanyId, currentSiteId } =
        companyNavigation;
    const company = companies.find((item) => item.id === currentCompanyId);
    const site = sites.find((item) => item.id === currentSiteId);
    const showSites = navigationArea !== 'administration' && sites.length > 1;
    const term = search.trim().toLocaleLowerCase();
    const visible = companies.filter((item) =>
        item.name.toLocaleLowerCase().includes(term),
    );

    // Switching site keeps the current section.
    const siteIndex =
        component === 'search/index'
            ? searchIndex
            : component.startsWith('racks/')
              ? rackIndex
              : component.startsWith('devices/')
                ? deviceIndex
                : component.startsWith('vlans/')
                  ? vlanIndex
                  : component.startsWith('topology/')
                    ? topologyIndex
                    : subnetIndex;
    function go(target: Parameters<typeof router.visit>[0]) {
        setOpen(false);
        router.visit(target);
    }
    function selectSite(id: number) {
        if (!currentCompanyId) return;
        go(
            siteIndex(
                { company: currentCompanyId, site: id },
                component === 'search/index'
                    ? {
                          query: Object.fromEntries(
                              new URL(url, window.location.origin)
                                  .searchParams,
                          ),
                      }
                    : undefined,
            ),
        );
    }

    return (
        <>
            <Popover
                open={open}
                onOpenChange={(value) => {
                    setOpen(value);
                    if (!value) setSearch('');
                }}
            >
                <PopoverTrigger asChild>
                    <Button
                        variant="outline"
                        aria-label="Cambia azienda o sede"
                        className="min-w-0 flex-1 justify-start sm:max-w-80 sm:flex-none"
                    >
                        <Building2 data-icon="inline-start" />
                        <span className="min-w-0 flex-1 truncate text-left">
                            {company?.name ?? 'Seleziona azienda'}
                            {showSites && site?.name ? (
                                <span className="text-muted-foreground">
                                    {' · '}
                                    {site.name}
                                </span>
                            ) : null}
                        </span>
                        <ChevronsUpDown
                            data-icon="inline-end"
                            className="text-muted-foreground"
                        />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="end" className="w-72 p-0">
                    {companies.length >= SEARCH_FROM ? (
                        <div className="border-b p-2">
                            <InputGroup>
                                <InputGroupAddon>
                                    <Search />
                                </InputGroupAddon>
                                <InputGroupInput
                                    type="search"
                                    aria-label="Cerca azienda"
                                    placeholder="Cerca azienda…"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    autoFocus
                                />
                            </InputGroup>
                        </div>
                    ) : null}
                    <ul
                        aria-label="Aziende"
                        className="flex max-h-80 flex-col gap-0.5 overflow-y-auto p-1"
                    >
                        {visible.map((item) => {
                            const current = item.id === currentCompanyId;
                            return (
                                <li key={item.id}>
                                    <SwitcherOption
                                        current={current}
                                        onClick={() =>
                                            current
                                                ? setOpen(false)
                                                : go(show(item.id))
                                        }
                                    >
                                        <Building2 />
                                        <span className="min-w-0 flex-1 truncate">
                                            {item.name}
                                        </span>
                                    </SwitcherOption>
                                    {current && showSites ? (
                                        <ul
                                            aria-label={`Sedi di ${item.name}`}
                                            className="flex flex-col gap-0.5 py-0.5 pl-6"
                                        >
                                            {sites.map((option) => (
                                                <li key={option.id}>
                                                    <SwitcherOption
                                                        current={
                                                            option.id ===
                                                            currentSiteId
                                                        }
                                                        onClick={() =>
                                                            selectSite(
                                                                option.id,
                                                            )
                                                        }
                                                    >
                                                        <MapPin />
                                                        <span className="min-w-0 flex-1 truncate">
                                                            {option.name}
                                                        </span>
                                                    </SwitcherOption>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : null}
                                </li>
                            );
                        })}
                        {visible.length === 0 ? (
                            <li className="px-2 py-6 text-center text-sm text-muted-foreground">
                                Nessuna azienda trovata
                            </li>
                        ) : null}
                    </ul>
                    {auth.user.is_superadmin ? (
                        <>
                            <Separator />
                            <div className="p-1">
                                <SwitcherOption
                                    onClick={() => {
                                        setOpen(false);
                                        setCreating(true);
                                    }}
                                >
                                    <Plus />
                                    Nuova azienda
                                </SwitcherOption>
                            </div>
                        </>
                    ) : null}
                </PopoverContent>
            </Popover>
            {auth.user.is_superadmin ? (
                <CompanyForm
                    open={creating}
                    onOpenChange={setCreating}
                    onCreated={(created) =>
                        router.visit(
                            subnetIndex({
                                company: created.id,
                                site: created.site_id,
                            }),
                        )
                    }
                />
            ) : null}
        </>
    );
}

function SwitcherOption({
    current = false,
    onClick,
    children,
}: {
    current?: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-current={current ? 'true' : undefined}
            onClick={onClick}
            className={cn(
                'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm outline-hidden hover:bg-accent hover:text-accent-foreground focus-visible:bg-accent focus-visible:ring-2 focus-visible:ring-ring [&_svg]:size-4 [&_svg]:shrink-0 [&_svg]:text-muted-foreground',
                current && 'font-medium',
            )}
        >
            {children}
            {current ? (
                <Check aria-hidden="true" className="ml-auto" />
            ) : null}
        </button>
    );
}
