import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowRight } from 'lucide-react';
import {
    IntegrationHeader,
    IntegrationStatus,
    type IntegrationState,
} from '@/components/integration-header';
import { IntegrationTabs } from '@/components/integration-tabs';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Item,
    ItemActions,
    ItemContent,
    ItemDescription,
    ItemGroup,
    ItemTitle,
} from '@/components/ui/item';
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
    const state: IntegrationState = gateways.some((device) => device.error)
        ? 'error'
        : gateways.some((device) => device.activated_at)
          ? 'active'
          : gateways.length
            ? 'pending'
            : 'unconfigured';
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
                    <IntegrationHeader
                        mark={<MikrotikMark className="h-5" />}
                        title="MikroTik"
                        state={state}
                        description={[
                            gateways.length === 1
                                ? '1 apparato collegato'
                                : `${gateways.length} apparati collegati`,
                            candidates.length
                                ? `${candidates.length} senza connessione`
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    />
                    <Card>
                        <CardHeader>
                            <CardTitle>Apparati collegati</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {gateways.length ? (
                                <ItemGroup className="gap-2">
                                    {gateways.map((device) => (
                                        <Item
                                            key={device.id}
                                            variant="outline"
                                            size="sm"
                                            role="listitem"
                                        >
                                            <ItemContent className="min-w-0">
                                                <ItemTitle>
                                                    {device.name}
                                                    <IntegrationStatus
                                                        state={
                                                            device.error
                                                                ? 'error'
                                                                : device.activated_at
                                                                  ? 'active'
                                                                  : 'pending'
                                                        }
                                                    />
                                                </ItemTitle>
                                                <ItemDescription className="line-clamp-none">
                                                    {device.error ? (
                                                        <span className="text-destructive">
                                                            {device.error}
                                                        </span>
                                                    ) : (
                                                        [
                                                            context(device),
                                                            device.synced_at
                                                                ? `ultima lettura ${formatDateTime(device.synced_at)}`
                                                                : 'mai sincronizzata',
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')
                                                    )}
                                                </ItemDescription>
                                            </ItemContent>
                                            <ItemActions>
                                                {device.problems ? (
                                                    <Badge variant="outline">
                                                        <AlertTriangle className="text-amber-600 dark:text-amber-500" />
                                                        {device.problems} da
                                                        verificare
                                                    </Badge>
                                                ) : null}
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
                                            </ItemActions>
                                        </Item>
                                    ))}
                                </ItemGroup>
                            ) : (
                                <Empty>
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
                        </CardContent>
                    </Card>
                    {candidates.length ? (
                        <Card>
                            <CardHeader>
                                <CardTitle>Senza connessione</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ItemGroup className="gap-2">
                                    {candidates.map((device) => (
                                        <Item
                                            key={device.id}
                                            variant="outline"
                                            size="sm"
                                            role="listitem"
                                        >
                                            <ItemContent className="min-w-0">
                                                <ItemTitle>
                                                    {device.name}
                                                </ItemTitle>
                                                {context(device) ? (
                                                    <ItemDescription>
                                                        {context(device)}
                                                    </ItemDescription>
                                                ) : null}
                                            </ItemContent>
                                            <ItemActions>
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
                                            </ItemActions>
                                        </Item>
                                    ))}
                                </ItemGroup>
                            </CardContent>
                        </Card>
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
