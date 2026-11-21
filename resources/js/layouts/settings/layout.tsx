import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';

// Section navigation (Profilo, Sicurezza, Aspetto) lives in the app sidebar.
export default function SettingsLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-6">
            <Heading title="Impostazioni" />

            <section className="flex max-w-2xl min-w-0 flex-col gap-10">
                {children}
            </section>
        </div>
    );
}
