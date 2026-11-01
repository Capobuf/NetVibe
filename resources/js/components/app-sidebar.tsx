import { index as deviceIndex } from '@/routes/companies/sites/devices';
import { index as rackIndex } from '@/routes/companies/sites/racks';
import { index as modelIndex } from '@/routes/device-models';
import { index as companyIndex } from '@/routes/companies';
import { Link, usePage } from '@inertiajs/react';
import {
    Layers3,
    Server as RackIcon,
    Network,
    Settings2,
    Shield,
    Monitor,
    LayoutTemplate,
    Building2,
    Tags,
    Mail,
    Plug,
    Users,
    ArrowLeft,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { SidebarNavigation } from '@/components/sidebar-navigation';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { home } from '@/routes';
import { index as administration } from '@/routes/administration';
import { index as adminCompanies } from '@/routes/administration/companies';
import { index as adminUsers } from '@/routes/administration/users';
import { index as ipStatuses } from '@/routes/administration/ip-statuses';
import { edit as integrations } from '@/routes/administration/integrations/snipe-it';
import { edit as email } from '@/routes/administration/email';
import { index as subnetIndex } from '@/routes/companies/sites/subnets';
import { index as vlanIndex } from '@/routes/companies/sites/vlans';
import { show as companySettings } from '@/routes/companies/settings';
export function AppSidebar() {
    const {
        props: { auth, companyNavigation, catalogModel, navigationArea },
        component,
    } = usePage();
    const inAdministration = navigationArea === 'administration';
    const catalogActive =
        component === 'devices/models' ||
        (component === 'administration/hardware-editor' &&
            catalogModel !== undefined);
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
                        <SidebarMenuButton
                            size="lg"
                            className="h-20 justify-center"
                            asChild
                        >
                            <Link
                                href={
                                    inAdministration ? administration() : home()
                                }
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <SidebarNavigation key={navigationArea}>
                    {inAdministration ? (
                        <NavMain
                            label="Amministrazione"
                            items={[
                                {
                                    title: 'Aziende',
                                    href: adminCompanies(),
                                    icon: Building2,
                                },
                                {
                                    title: 'Utenti',
                                    href: adminUsers(),
                                    icon: Users,
                                },
                                {
                                    title: 'Stati IP',
                                    href: ipStatuses(),
                                    icon: Tags,
                                },
                                { title: 'Email', href: email(), icon: Mail },
                                {
                                    title: 'Integrazioni',
                                    href: integrations(),
                                    icon: Plug,
                                },
                            ]}
                        />
                    ) : (
                        <>
                            <NavMain
                                label="Rete"
                                items={[
                                    {
                                        title: 'Subnet',
                                        href: networkContext
                                            ? subnetIndex(networkContext)
                                            : companyIndex(),
                                        icon: Network,
                                        isActive:
                                            !!networkContext &&
                                            component.startsWith('subnets/'),
                                    },
                                    {
                                        title: 'VLAN',
                                        href: networkContext
                                            ? vlanIndex(networkContext)
                                            : companyIndex(),
                                        icon: Layers3,
                                        isActive:
                                            !!networkContext &&
                                            component.startsWith('vlans/'),
                                    },
                                ]}
                            />
                            <NavMain
                                label="Inventario"
                                items={[
                                    {
                                        title: 'Dispositivi',
                                        href: networkContext
                                            ? deviceIndex(networkContext)
                                            : companyIndex(),
                                        icon: Monitor,
                                        isActive:
                                            component === 'devices/index' ||
                                            component === 'devices/show',
                                        items: auth.user.is_superadmin
                                            ? [
                                                  {
                                                      title: 'Catalogo',
                                                      href: modelIndex(),
                                                      icon: LayoutTemplate,
                                                      isActive: catalogActive,
                                                  },
                                              ]
                                            : [],
                                    },
                                    {
                                        title: 'Rack',
                                        href: networkContext
                                            ? rackIndex(networkContext)
                                            : companyIndex(),
                                        icon: RackIcon,
                                        isActive:
                                            !!networkContext &&
                                            component.startsWith('racks/'),
                                    },
                                ]}
                            />
                        </>
                    )}
                </SidebarNavigation>
            </SidebarContent>

            <SidebarFooter>
                {!inAdministration && (
                    <NavMain
                        items={[
                            {
                                title: 'Impostazioni',
                                href: companyNavigation?.currentCompanyId
                                    ? companySettings(
                                          companyNavigation.currentCompanyId,
                                      )
                                    : companyIndex(),
                                icon: Settings2,
                                isActive: companyNavigation?.currentCompanyId
                                    ? undefined
                                    : false,
                            },
                        ]}
                    />
                )}
                <SidebarSeparator />
                {inAdministration ? (
                    <NavMain
                        items={[
                            {
                                title: networkContext
                                    ? 'Torna all’azienda'
                                    : 'Aziende',
                                href: networkContext
                                    ? subnetIndex(networkContext)
                                    : companyIndex(),
                                icon: ArrowLeft,
                            },
                        ]}
                    />
                ) : auth.user.is_superadmin ? (
                    <NavMain
                        items={[
                            {
                                title: 'Amministrazione',
                                href: administration(),
                                icon: Shield,
                            },
                        ]}
                    />
                ) : null}

                {(inAdministration || auth.user.is_superadmin) && (
                    <SidebarSeparator />
                )}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
