import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';

export default function AppLogo({ className }: { className?: string }) {
    const { name } = usePage().props;

    return (
        <>
            <AppLogoIcon
                className={cn(
                    'size-16 group-data-[collapsible=icon]:size-8 motion-safe:transition-[width,height] motion-safe:duration-220 motion-safe:ease-out',
                    className,
                )}
            />
            <span className="sr-only">{name}</span>
        </>
    );
}
