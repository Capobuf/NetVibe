import { index as labelIndex } from '@/routes/administration/labels';
import { index as deviceIndex } from '@/routes/companies/sites/devices';
import { index as rackIndex } from '@/routes/companies/sites/racks';
import { index as topologyIndex } from '@/routes/companies/sites/topology';
import { index as modelIndex } from '@/routes/device-models';
import { index as companyIndex } from '@/routes/companies';
import { Link, usePage } from '@inertiajs/react';
import {
    Layers3,
    Workflow,
    Server as RackIcon,
    Network,
    Settings2,
    Shield,
    Monitor,
    BookOpen,
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
import { index as integrations } from '@/routes/administration/integrations';
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
    // The company list is "Tutte le aziende": the remembered company is not selected there.
    const settingsCompany =
        component === 'companies/index'
            ? null
            : (companyNavigation?.currentCompanyId ?? null);
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
                            className="h-16 justify-center hover:bg-transparent active:bg-transparent"
                            asChild
                        >
                            <Link
                                href={
                                    inAdministration ? administration() : home()
                                }
                            >
                                <AppLogo className="size-12" />
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
                                {
                                    title: 'Etichette',
                                    href: labelIndex(),
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
                    ) : networkContext ? (
                        <>
                            <NavMain
                                label="Rete"
                                items={[
                                    {
                                        title: 'Topologia',
                                        href: topologyIndex(networkContext),
                                        icon: Workflow,
                                        isActive: component.startsWith('topology/'),
                                    },
                                    {
                                        title: 'Subnet',
                                        href: subnetIndex(networkContext),
                                        icon: Network,
                                        isActive: component.startsWith('subnets/'),
                                    },
                                    {
                                        title: 'VLAN',
                                        href: vlanIndex(networkContext),
                                        icon: Layers3,
                                        isActive: component.startsWith('vlans/'),
                                    },
                                ]}
                            />
                            <NavMain
                                label="Inventario"
                                items={[
                                    {
                                        title: 'Dispositivi',
                                        href: deviceIndex(networkContext),
                                        icon: Monitor,
                                        isActive:
                                            component === 'devices/index' ||
                                            component === 'devices/show',
                                    },
                                    {
                                        title: 'Rack',
                                        href: rackIndex(networkContext),
                                        icon: RackIcon,
                                        isActive: component.startsWith('racks/'),
                                    },
                                    {
                                        title: 'Catalogo',
                                        href: modelIndex(),
                                        icon: BookOpen,
                                        isActive: catalogActive,
                                    },
                                ]}
                            />
                        </>
                    ) : (
                        // Site-scoped sections need a company and site; only the global catalog applies here.
                        <NavMain
                            label="Inventario"
                            items={[
                                {
                                    title: 'Catalogo',
                                    href: modelIndex(),
                                    icon: BookOpen,
                                    isActive: catalogActive,
                                },
                            ]}
                        />
                    )}
                </SidebarNavigation>
            </SidebarContent>

            <SidebarFooter>
                {/* Company settings exist only in the context of a selected company. */}
                {!inAdministration && settingsCompany ? (
                    <NavMain
                        items={[
                            {
                                title: 'Impostazioni',
                                href: companySettings(settingsCompany),
                                icon: Settings2,
                            },
                        ]}
                    />
                ) : null}
                {inAdministration || settingsCompany ? (
                    <SidebarSeparator />
                ) : null}
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
