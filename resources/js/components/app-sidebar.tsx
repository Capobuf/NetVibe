import { index as deviceIndex } from '@/routes/companies/sites/devices';
import { index as rackIndex } from '@/routes/companies/sites/racks';
import { index as modelIndex } from '@/routes/device-models';
import { index as companyIndex } from '@/routes/companies';
import { Link, usePage } from '@inertiajs/react';
import {
    Layers,
    PanelsTopLeft as RackIcon,
    Network,
    Settings2,
    Server,
    PanelsTopLeft,
    Component,
    Building2,
    Mail,
    Tags,
    Users,
    ArrowLeft,
} from 'lucide-react';
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
import {
    index as administration,
    hardwareEditor,
} from '@/routes/administration';
import { index as adminCompanies } from '@/routes/administration/companies';
import { index as adminUsers } from '@/routes/administration/users';
import { index as ipStatuses } from '@/routes/administration/ip-statuses';
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
                        <SidebarMenuButton size="lg" asChild>
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
                                title: 'Stati IP',
                                href: ipStatuses(),
                                icon: Tags,
                            },
                            {
                                title: 'Utenti',
                                href: adminUsers(),
                                icon: Users,
                            },
                            { title: 'Email', href: email(), icon: Mail },
                            {
                                title: 'Editor',
                                href: hardwareEditor(),
                                icon: PanelsTopLeft,
                            },
                        ]}
                    />
                ) : (
                    <>
                        <NavMain
                            label="IPAM"
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
                                    icon: Layers,
                                    isActive:
                                        !!networkContext &&
                                        component.startsWith('vlans/'),
                                },
                                {
                                    title: 'Dispositivi',
                                    href: networkContext
                                        ? deviceIndex(networkContext)
                                        : companyIndex(),
                                    icon: Server,
                                    isActive:
                                        component === 'devices/index' ||
                                        component === 'devices/show',
                                    items: auth.user.is_superadmin
                                        ? [
                                              {
                                                  title: 'Modelli',
                                                  href: modelIndex(),
                                                  icon: Component,
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
                        <NavMain
                            label="Azienda"
                            items={[
                                {
                                    title: 'Impostazioni',
                                    href: companyNavigation?.currentCompanyId
                                        ? companySettings(
                                              companyNavigation.currentCompanyId,
                                          )
                                        : companyIndex(),
                                    icon: Settings2,
                                    isActive:
                                        companyNavigation?.currentCompanyId
                                            ? undefined
                                            : false,
                                },
                            ]}
                        />
                    </>
                )}
            </SidebarContent>

            <SidebarFooter>
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
