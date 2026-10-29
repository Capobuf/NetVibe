import { Link, usePage } from '@inertiajs/react';
import { Layers, Network, Settings2 } from 'lucide-react';
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
import { home } from '@/routes';
import { index as administration } from '@/routes/administration';
import { index as subnetIndex } from '@/routes/companies/sites/subnets';
import { index as vlanIndex } from '@/routes/companies/sites/vlans';
import { show as companyShow } from '@/routes/companies';
import { show as companySettings } from '@/routes/companies/settings';
export function AppSidebar() {
    const { auth, companyNavigation } = usePage().props;
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
                            <Link href={home()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {networkContext ? (
                    <NavMain
                        label="IPAM"
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
                {companyNavigation?.currentCompanyId ? (
                    <NavMain
                        label="Azienda"
                        items={[
                            ...(!networkContext
                                ? [
                                      {
                                          title: 'IPAM',
                                          href: companyShow(
                                              companyNavigation.currentCompanyId,
                                          ),
                                          icon: Network,
                                      },
                                  ]
                                : []),
                            {
                                title: 'Impostazioni',
                                href: companySettings(
                                    companyNavigation.currentCompanyId,
                                ),
                                icon: Settings2,
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
