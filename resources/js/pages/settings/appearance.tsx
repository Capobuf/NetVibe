import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Impostazioni dell’aspetto" />

            <h1 className="sr-only">Impostazioni dell’aspetto</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Impostazioni dell’aspetto"
                    description="Scegli l’aspetto dell’interfaccia"
                />
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Impostazioni dell’aspetto',
            href: editAppearance(),
        },
    ],
};
