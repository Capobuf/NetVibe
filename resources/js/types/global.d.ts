import type { Auth } from '@/types/auth';
import type { Company, Site } from '@/types/company';

export type AppNotification = {
    id: string;
    data: {
        source: 'snipe_it' | 'mikrotik' | 'unifi' | 'google_drive';
        failed: boolean;
        title: string;
        subject: string;
        message: string | null;
        url: string;
    };
    read_at: string | null;
    created_at: string;
};

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            navigationArea: 'tenant' | 'administration';
            companyNavigation: {
                companies: Company[];
                currentCompanyId: number | null;
                sites: Site[];
                currentSiteId: number | null;
            } | null;
            notifications: {
                unread: number;
                latest: AppNotification[];
            } | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
