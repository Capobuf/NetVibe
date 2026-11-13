import { Link } from '@inertiajs/react';
import { Palette, ShieldCheck, UserRound } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profilo',
        href: edit(),
        icon: UserRound,
    },
    {
        title: 'Sicurezza',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'Aspetto',
        href: editAppearance(),
        icon: Palette,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-6">
            <Heading title="Impostazioni" />

            <div className="flex min-w-0 flex-col gap-6 lg:flex-row lg:gap-12">
                <aside className="w-full shrink-0 lg:w-48">
                    <nav
                        className="flex gap-1 overflow-x-auto rounded-lg bg-muted/50 p-1 lg:flex-col"
                        aria-label="Impostazioni"
                    >
                        {sidebarNavItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn(
                                    'flex-1 justify-start lg:w-full lg:flex-none',
                                    {
                                        'bg-background shadow-xs':
                                            isCurrentOrParentUrl(item.href),
                                    },
                                )}
                            >
                                <Link
                                    href={item.href}
                                    aria-current={
                                        isCurrentOrParentUrl(item.href)
                                            ? 'page'
                                            : undefined
                                    }
                                >
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <div className="min-w-0 flex-1 md:max-w-2xl">
                    <section className="flex max-w-xl flex-col gap-10">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
