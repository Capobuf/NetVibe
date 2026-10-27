import { Link, usePage } from '@inertiajs/react';
import { Building2, Layers, Network, Settings2 } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { index as administration } from '@/routes/administration';
import { index as companies } from '@/routes/companies';
import { index as subnetIndex } from '@/routes/companies/sites/subnets';
import { index as vlanIndex } from '@/routes/companies/sites/vlans';
export function AppSidebar() {
    const { auth, companyNavigation } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();
    const networkContext =
        companyNavigation?.currentCompanyId && companyNavigation.currentSiteId
            ? {
                  company: companyNavigation.currentCompanyId,
                  site: companyNavigation.currentSiteId,
              }
            : null;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={companies()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain
                    label="IPAM"
                    items={[
                        {
                            title: 'Aziende',
                            href: companies(),
                            icon: Building2,
                            isActive: isCurrentUrl(companies()),
                        },
                    ]}
                />
                {networkContext ? (
                    <NavMain
                        items={[
                            {
                                title: 'Subnet',
                                href: subnetIndex(networkContext),
                                icon: Network,
                            },
                            {
                                title: 'VLAN',
                                href: vlanIndex(networkContext),
                                icon: Layers,
                            },
                        ]}
                    />
                ) : null}
            </SidebarContent>

            <SidebarFooter>
                {auth.user?.is_superadmin ? (
                    <NavMain
                        items={[
                            {
                                title: 'Amministrazione',
                                href: administration(),
                                icon: Settings2,
                            },
                        ]}
                    />
                ) : null}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
