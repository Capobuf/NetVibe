import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowRight } from 'lucide-react';
import { IntegrationTabs } from '@/components/integration-tabs';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { formatDateTime } from '@/lib/utils';
import { index as administration } from '@/routes/administration';
import {
    index as integrations,
    mikrotik as mikrotikIndex,
} from '@/routes/administration/integrations';
import {
    integrations as deviceIntegrations,
    show as deviceShow,
} from '@/routes/companies/sites/devices';
import type { Company } from '@/types/company';

type Device = {
    id: number;
    name: string;
    site_id: number;
    site: string | null;
    company: Company;
};
type Props = {
    company: Company | null;
    gateways: (Device & {
        activated_at: string | null;
        synced_at: string | null;
        error: string | null;
        problems: number;
    })[];
    candidates: Device[];
};

export default function Mikrotik({ company, gateways, candidates }: Props) {
    const { auth } = usePage().props;
    const admin = auth.user.is_superadmin;
    const href = (device: Device) => {
        const route = {
            company: device.company.id,
            site: device.site_id,
            device: device.id,
        };
        return admin
            ? deviceIntegrations(route, { query: { tab: 'mikrotik' } })
            : deviceShow(route);
    };
    const context = (device: Device) =>
        [company ? null : device.company.name, device.site]
            .filter(Boolean)
            .join(' · ');

    return (
        <>
            <Head title={`MikroTik · ${company?.name ?? 'Integrazioni'}`} />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                {!company ? (
                    <h1 className="text-2xl font-medium tracking-tight">
                        Integrazioni
                    </h1>
                ) : null}
                <IntegrationTabs value="mikrotik" company={company}>
                    <section
                        aria-labelledby="mikrotik-gateways"
                        className="flex flex-col gap-3"
                    >
                        <h2
                            id="mikrotik-gateways"
                            className="text-base font-medium"
                        >
                            Apparati collegati
                        </h2>
                        {gateways.length ? (
                            <ul className="flex flex-col divide-y rounded-xl border">
                                {gateways.map((device) => (
                                    <li
                                        key={device.id}
                                        className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-3"
                                    >
                                        <div className="flex min-w-0 flex-col">
                                            <span className="truncate font-medium">
                                                {device.name}
                                            </span>
                                            {context(device) ? (
                                                <span className="text-sm text-muted-foreground">
                                                    {context(device)}
                                                </span>
                                            ) : null}
                                        </div>
                                        <div className="flex flex-wrap items-center gap-3">
                                            {device.problems ? (
                                                <Badge variant="outline">
                                                    <AlertTriangle className="text-amber-600 dark:text-amber-500" />
                                                    {device.problems} da
                                                    verificare
                                                </Badge>
                                            ) : null}
                                            {device.error ? (
                                                <Badge variant="destructive">
                                                    {device.error}
                                                </Badge>
                                            ) : (
                                                <Badge
                                                    variant={
                                                        device.activated_at
                                                            ? 'secondary'
                                                            : 'outline'
                                                    }
                                                >
                                                    {device.activated_at
                                                        ? 'Attiva · ogni ora'
                                                        : 'Da avviare'}
                                                </Badge>
                                            )}
                                            <span className="text-sm text-muted-foreground tabular-nums">
                                                {device.synced_at
                                                    ? `Ultima lettura ${formatDateTime(device.synced_at)}`
                                                    : 'Mai sincronizzata'}
                                            </span>
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="sm"
                                            >
                                                <Link href={href(device)}>
                                                    Gestisci
                                                    <ArrowRight data-icon="inline-end" />
                                                </Link>
                                            </Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty className="border">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <MikrotikMark />
                                    </EmptyMedia>
                                    <EmptyTitle>
                                        Nessun apparato MikroTik collegato
                                    </EmptyTitle>
                                </EmptyHeader>
                            </Empty>
                        )}
                    </section>
                    {candidates.length ? (
                        <section
                            aria-labelledby="mikrotik-candidates"
                            className="flex flex-col gap-3"
                        >
                            <h2
                                id="mikrotik-candidates"
                                className="text-base font-medium"
                            >
                                Senza connessione
                            </h2>
                            <ul className="flex flex-col divide-y rounded-xl border">
                                {candidates.map((device) => (
                                    <li
                                        key={device.id}
                                        className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-2.5"
                                    >
                                        <div className="flex min-w-0 flex-col">
                                            <span className="truncate">
                                                {device.name}
                                            </span>
                                            {context(device) ? (
                                                <span className="text-sm text-muted-foreground">
                                                    {context(device)}
                                                </span>
                                            ) : null}
                                        </div>
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="sm"
                                        >
                                            <Link href={href(device)}>
                                                {admin
                                                    ? 'Configura connessione'
                                                    : 'Apri dispositivo'}
                                                <ArrowRight data-icon="inline-end" />
                                            </Link>
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : null}
                </IntegrationTabs>
            </div>
        </>
    );
}

Mikrotik.layout = ({ company }: Props) => ({
    breadcrumbs: company
        ? []
        : [
              { title: 'Amministrazione', href: administration() },
              { title: 'Integrazioni', href: integrations() },
              { title: 'MikroTik', href: mikrotikIndex() },
          ],
});
