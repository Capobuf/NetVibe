import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <AppLogoIcon className="size-16 group-data-[collapsible=icon]:size-8 motion-safe:transition-[width,height] motion-safe:duration-220 motion-safe:ease-out" />
            <span className="sr-only">{name}</span>
        </>
    );
}
