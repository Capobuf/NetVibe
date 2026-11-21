import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
// Registers the unsaved-changes history guard before Inertia's own popstate listener.
import '@/hooks/use-leave-guard';
import AppLayout from '@/layouts/app-layout';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { pageTransitionOptions } from '@/lib/page-transition';

const appName = import.meta.env.VITE_APP_NAME || 'NetVibe';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'administration/hardware-editor':
                return AppSidebarLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    defaults: {
        visitOptions: pageTransitionOptions,
    },
    withApp(app) {
        return (
            <TooltipProvider>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
