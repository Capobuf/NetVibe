import { UnifiMark } from '@/components/unifi-mark';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { SnipeItMark } from '@/components/snipe-it-mark';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle, CardFooter } from '@/components/ui/card';
import { index as administration } from '@/routes/administration';
import { index } from '@/routes/administration/integrations';
import { edit as snipeIt } from '@/routes/administration/integrations/snipe-it';
import { index as unifi } from '@/routes/administration/integrations/unifi';

export default function Integrations() {
    return (
        <>
            <Head title="Integrazioni" />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-medium tracking-tight">
                    Integrazioni
                </h1>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-3">
                                <SnipeItMark className="size-6" />
                                Snipe-IT
                            </CardTitle>
                        </CardHeader>
                        <CardFooter>
                            <Button
                                asChild
                                variant="outline"
                                className="w-full justify-between"
                            >
                                <Link href={snipeIt()}>
                                    Gestisci Snipe-IT{' '}
                                    <ArrowRight data-icon="inline-end" />
                                </Link>
                            </Button>
                        </CardFooter>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-3">
                                <UnifiMark className="size-6" />
                                UniFi
                            </CardTitle>
                        </CardHeader>
                        <CardFooter>
                            <Button
                                asChild
                                variant="outline"
                                className="w-full justify-between"
                            >
                                <Link href={unifi()}>
                                    Gestisci UniFi{' '}
                                    <ArrowRight data-icon="inline-end" />
                                </Link>
                            </Button>
                        </CardFooter>
                    </Card>
                </div>
            </div>
        </>
    );
}

Integrations.layout = {
    breadcrumbs: [
        { title: 'Amministrazione', href: administration() },
        { title: 'Integrazioni', href: index() },
    ],
};
