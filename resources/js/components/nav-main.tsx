import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

function NavBranch({ item }: { item: NavItem }) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { state, isMobile, setOpenMobile } = useSidebar();
    const collapsed = state === 'collapsed' && !isMobile;
    const childActive =
        item.items?.some(
            (child) => child.isActive ?? isCurrentOrParentUrl(child.href),
        ) ?? false;
    const active =
        !childActive && (item.isActive ?? isCurrentOrParentUrl(item.href));
    const [open, setOpen] = useState(active || childActive);
    const hasChildren = !!item.items?.length;

    return (
        <Collapsible asChild open={collapsed || open} onOpenChange={setOpen}>
            <SidebarMenuItem>
                <div
                    className="sidebar-nav-row flex items-center gap-1 rounded-md"
                    data-active={active}
                >
                    <SidebarMenuButton
                        asChild
                        isActive={active}
                        data-child-active={childActive}
                        tooltip={item.title}
                        className="sidebar-nav-link min-w-0 flex-1"
                    >
                        <Link
                            href={item.href}
                            aria-current={active ? 'page' : undefined}
                            onSuccess={() => setOpenMobile(false)}
                        >
                            {item.icon && <item.icon aria-hidden="true" />}
                            <span>{item.title}</span>
                        </Link>
                    </SidebarMenuButton>
                    {hasChildren && !collapsed && (
                        <CollapsibleTrigger
                            className="sidebar-nav-toggle flex size-10 shrink-0 items-center justify-center rounded-md text-muted-foreground outline-hidden hover:text-sidebar-accent-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring max-md:size-11"
                            aria-label={`${open ? 'Chiudi' : 'Apri'} il sottomenu ${item.title}`}
                        >
                            <ChevronRight
                                aria-hidden="true"
                                className="size-4 transition-transform duration-190 ease-out motion-reduce:transition-none"
                                style={{
                                    transform: open
                                        ? 'rotate(90deg)'
                                        : undefined,
                                }}
                            />
                        </CollapsibleTrigger>
                    )}
                </div>
                {hasChildren && (
                    <CollapsibleContent className="sidebar-submenu">
                        <SidebarMenuSub className="sidebar-nav-sub">
                            {item.items?.map((child) => {
                                const selected =
                                    child.isActive ??
                                    isCurrentOrParentUrl(child.href);

                                return (
                                    <SidebarMenuSubItem key={child.title}>
                                        <SidebarMenuButton
                                            asChild
                                            isActive={selected}
                                            tooltip={child.title}
                                            className="sidebar-nav-link"
                                        >
                                            <Link
                                                href={child.href}
                                                aria-current={
                                                    selected
                                                        ? 'page'
                                                        : undefined
                                                }
                                                onSuccess={() =>
                                                    setOpenMobile(false)
                                                }
                                            >
                                                {child.icon && (
                                                    <child.icon aria-hidden="true" />
                                                )}
                                                <span>{child.title}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuSubItem>
                                );
                            })}
                        </SidebarMenuSub>
                    </CollapsibleContent>
                )}
            </SidebarMenuItem>
        </Collapsible>
    );
}

export function NavMain({
    items,
    label,
}: {
    items: NavItem[];
    label?: string;
}) {
    const { currentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-0">
            {label ? <SidebarGroupLabel>{label}</SidebarGroupLabel> : null}
            <SidebarMenu>
                {items.map((item) => (
                    <NavBranch
                        key={`${item.title}:${currentUrl}`}
                        item={item}
                    />
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
