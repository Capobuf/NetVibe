import type { Auth } from '@/types/auth';
import type { Company, Site } from '@/types/company';

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
            companyNavigation: {
                companies: Company[];
                currentCompanyId: number | null;
                sites: Site[];
                currentSiteId: number | null;
            } | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
