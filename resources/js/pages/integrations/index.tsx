import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AlertTriangle } from 'lucide-react';
import {
    IntegrationCard,
    LastRead,
    ManageButton,
    type Status,
} from '@/components/integration-card';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { SnipeItMark } from '@/components/snipe-it-mark';
import { UnifiMark } from '@/components/unifi-mark';
import { formatDateTime } from '@/lib/utils';
import { index as administration } from '@/routes/administration';
import { index } from '@/routes/administration/integrations';
import { edit as snipeIt } from '@/routes/administration/integrations/snipe-it';
import { index as unifi } from '@/routes/administration/integrations/unifi';
import { show as companyIntegrations } from '@/routes/companies/settings/integrations';
import { integrations as deviceIntegrations } from '@/routes/companies/sites/devices';
import type { Company } from '@/types/company';

type Props = {
    snipeIt: {
        server_url: string | null;
        check_status: string | null;
        checked_at: string | null;
        companies: {
            id: number;
            name: string;
            synced_at: string | null;
            error: string | null;
            pending: number;
        }[];
    };
    mikrotik: {
        id: number;
        name: string;
        site_id: number;
        company: Company;
        synced_at: string | null;
        error: string | null;
    }[];
    unifi: {
        connections: number;
        errors: number;
        sites: {
            id: number;
            name: string;
            console_name: string;
            last_synced_at: string | null;
            company: Company;
            error: string | null;
        }[];
        unassigned: number;
    };
};

const VISIBLE = 5;

/** Compact list of per-company rows; long lists are truncated with a count. */
function Rows<T>({
    items,
    empty,
    render,
}: {
    items: T[];
    empty: string;
    render: (item: T) => ReactNode;
}) {
    if (!items.length)
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    return (
        <ul className="flex flex-col gap-2">
            {items.slice(0, VISIBLE).map(render)}
            {items.length > VISIBLE ? (
                <li className="text-xs text-muted-foreground">
                    e altri {items.length - VISIBLE}
                </li>
            ) : null}
        </ul>
    );
}

function Row({
    href,
    title,
    detail,
    error,
    lastRead,
}: {
    href: Parameters<typeof Link>[0]['href'];
    title: string;
    detail?: ReactNode;
    error: string | null;
    lastRead: string | null;
}) {
    return (
        <li className="flex flex-col rounded-lg border px-3 py-2">
            <div className="flex items-center justify-between gap-2">
                <Link
                    href={href}
                    className="truncate rounded-sm text-sm font-medium outline-none hover:text-primary focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    {title}
                </Link>
                {detail}
            </div>
            {error ? (
                <span className="text-xs text-destructive">{error}</span>
            ) : (
                <LastRead value={lastRead} />
            )}
        </li>
    );
}

function status(
    configured: boolean,
    failed: boolean,
    activeLabel = 'Attiva · ogni ora',
): Status {
    if (!configured) return { label: 'Da configurare', tone: 'idle' };
    if (failed) return { label: 'Errore', tone: 'error' };
    return { label: activeLabel, tone: 'ok' };
}

export default function Integrations({
    snipeIt: snipe,
    mikrotik,
    unifi: unifiData,
}: Props) {
    return (
        <>
            <Head title="Integrazioni" />
            <div className="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-medium tracking-tight">
                    Integrazioni
                </h1>
                <div className="grid auto-rows-fr gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <IntegrationCard
                        mark={<SnipeItMark className="size-6" />}
                        title="Snipe-IT"
                        status={status(
                            !!snipe.server_url,
                            (!!snipe.check_status &&
                                snipe.check_status !== 'ok') ||
                                snipe.companies.some((item) => item.error),
                            snipe.companies.length
                                ? 'Attiva · ogni ora'
                                : 'Connessa',
                        )}
                        footer={
                            <ManageButton href={snipeIt()}>
                                Gestisci Snipe-IT
                            </ManageButton>
                        }
                    >
                        {snipe.server_url ? (
                            <div className="flex flex-col">
                                <span className="truncate text-sm">
                                    {snipe.server_url}
                                </span>
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {snipe.checked_at
                                        ? `Verificata il ${formatDateTime(snipe.checked_at)}`
                                        : 'Mai verificata'}
                                </span>
                            </div>
                        ) : null}
                        <Rows
                            items={snipe.companies}
                            empty="Nessuna azienda collegata."
                            render={(item) => (
                                <Row
                                    key={item.id}
                                    href={companyIntegrations(item.id)}
                                    title={item.name}
                                    error={item.error}
                                    lastRead={item.synced_at}
                                    detail={
                                        item.pending ? (
                                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                {item.pending} da decidere
                                            </span>
                                        ) : null
                                    }
                                />
                            )}
                        />
                    </IntegrationCard>

                    <IntegrationCard
                        mark={<UnifiMark className="size-6" />}
                        title="UniFi"
                        status={status(
                            unifiData.connections > 0,
                            unifiData.errors > 0 ||
                                unifiData.sites.some((item) => item.error),
                            unifiData.sites.length
                                ? 'Attiva · ogni ora'
                                : 'Connessa',
                        )}
                        footer={
                            <ManageButton href={unifi()}>
                                Gestisci UniFi
                            </ManageButton>
                        }
                    >
                        <Rows
                            items={unifiData.sites}
                            empty="Nessun sito collegato a un’azienda."
                            render={(item) => (
                                <Row
                                    key={item.id}
                                    href={companyIntegrations(item.company.id)}
                                    title={item.company.name}
                                    error={item.error}
                                    lastRead={item.last_synced_at}
                                    detail={
                                        <span className="truncate text-xs text-muted-foreground">
                                            {item.console_name} · {item.name}
                                        </span>
                                    }
                                />
                            )}
                        />
                        {unifiData.unassigned ? (
                            <Link
                                href={unifi()}
                                className="flex items-center gap-2 self-start rounded-md text-sm text-amber-700 outline-none hover:text-amber-600 focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:text-amber-500 dark:hover:text-amber-400"
                            >
                                <AlertTriangle className="size-4" />
                                {unifiData.unassigned === 1
                                    ? '1 sito da collegare'
                                    : `${unifiData.unassigned} siti da collegare`}
                            </Link>
                        ) : null}
                    </IntegrationCard>

                    <IntegrationCard
                        mark={<MikrotikMark className="h-6" />}
                        title="MikroTik"
                        status={status(
                            mikrotik.length > 0,
                            mikrotik.some((item) => item.error),
                        )}
                    >
                        <Rows
                            items={mikrotik}
                            empty="Nessun gateway MikroTik configurato."
                            render={(item) => (
                                <Row
                                    key={item.id}
                                    href={deviceIntegrations(
                                        {
                                            company: item.company.id,
                                            site: item.site_id,
                                            device: item.id,
                                        },
                                        { query: { tab: 'mikrotik' } },
                                    )}
                                    title={item.name}
                                    error={item.error}
                                    lastRead={item.synced_at}
                                    detail={
                                        <span className="truncate text-xs text-muted-foreground">
                                            {item.company.name}
                                        </span>
                                    }
                                />
                            )}
                        />
                    </IntegrationCard>
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
