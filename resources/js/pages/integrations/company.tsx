import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    AlertTriangle,
    ArrowRight,
    RefreshCw,
    Settings2,
} from 'lucide-react';
import { IconButton } from '@/components/icon-button';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { SnipeItMark } from '@/components/snipe-it-mark';
import { UnifiMark } from '@/components/unifi-mark';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/utils';
import { index as adminUnifi } from '@/routes/administration/integrations/unifi';
import {
    edit as snipeItEdit,
    records,
    sync as snipeItSync,
} from '@/routes/companies/settings/integrations/snipe-it';
import { index as companyUnifi } from '@/routes/companies/settings/integrations/unifi';
import {
    index as deviceIndex,
    integrations as deviceIntegrations,
    show as deviceShow,
} from '@/routes/companies/sites/devices';
import type { Company } from '@/types/company';

type Counts = {
    pending: number;
    linked: number;
    ipam: number;
    excluded: number;
    problems: number;
};
type Props = {
    company: Company;
    snipeIt: {
        connected: boolean;
        external_company: string | null;
        confirmed: boolean;
        active: boolean;
        synced_at: string | null;
        error: string | null;
        counts: Counts | null;
    };
    mikrotik: {
        id: number;
        name: string;
        site_id: number;
        site: string | null;
        activated_at: string | null;
        synced_at: string | null;
        error: string | null;
        problems: number;
    }[];
    mikrotikCandidates: {
        id: number;
        name: string;
        site_id: number;
        site: string | null;
    }[];
    unifi: {
        id: number;
        name: string;
        console_name: string;
        connection: string;
        site_id: number;
        site: string | null;
        inventory_activated_at: string | null;
        last_synced_at: string | null;
        devices_count: number;
        linked_count: number;
        error: string | null;
    }[];
};

type Status = { label: string; tone: 'ok' | 'idle' | 'error' };

function StatusBadge({ status }: { status: Status }) {
    return (
        <Badge
            variant={
                status.tone === 'error'
                    ? 'destructive'
                    : status.tone === 'ok'
                      ? 'secondary'
                      : 'outline'
            }
        >
            {status.tone === 'ok' ? (
                <span
                    aria-hidden="true"
                    className="size-1.5 rounded-full bg-emerald-500"
                />
            ) : null}
            {status.label}
        </Badge>
    );
}

function syncStatus(
    activated: string | null,
    error: string | null,
): Status {
    if (error) return { label: 'Errore', tone: 'error' };
    return activated
        ? { label: 'Attiva · ogni ora', tone: 'ok' }
        : { label: 'Da avviare', tone: 'idle' };
}

function LastRead({ value }: { value: string | null }) {
    return (
        <span className="text-sm text-muted-foreground tabular-nums">
            {value
                ? `Ultima lettura ${formatDateTime(value)}`
                : 'Mai sincronizzata'}
        </span>
    );
}

function IntegrationCard({
    mark,
    title,
    status,
    actions,
    children,
}: {
    mark: ReactNode;
    title: string;
    status: Status;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card className="gap-4">
            <CardHeader className="flex-row flex-wrap items-center justify-between gap-3">
                <CardTitle className="flex flex-wrap items-center gap-3 text-base">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-background">
                        {mark}
                    </span>
                    {title}
                    <StatusBadge status={status} />
                </CardTitle>
                {actions ? (
                    <div className="flex flex-wrap items-center gap-1">
                        {actions}
                    </div>
                ) : null}
            </CardHeader>
            <CardContent className="flex flex-col gap-4">{children}</CardContent>
        </Card>
    );
}

function SnipeIt({
    company,
    data,
    admin,
}: {
    company: Company;
    data: Props['snipeIt'];
    admin: boolean;
}) {
    const form = useForm({});
    const counts = data.counts;
    const status: Status = !data.connected
        ? { label: 'Da configurare', tone: 'idle' }
        : !data.confirmed
          ? data.external_company
              ? { label: 'Sospesa', tone: 'error' }
              : { label: 'Da configurare', tone: 'idle' }
          : syncStatus(data.synced_at, data.error);
    const ready = data.confirmed;
    const groups: { view: string; label: string; count: number }[] = counts
        ? [
              { view: 'pending', label: 'Da decidere', count: counts.pending },
              { view: 'linked', label: 'Apparati', count: counts.linked },
              { view: 'ipam', label: 'Client IPAM', count: counts.ipam },
              { view: 'excluded', label: 'Esclusi', count: counts.excluded },
          ]
        : [];

    return (
        <IntegrationCard
            mark={<SnipeItMark className="size-5" />}
            title="Snipe-IT"
            status={status}
            actions={
                admin ? (
                    <>
                        {ready ? (
                            <IconButton
                                label="Sincronizza ora"
                                variant="ghost"
                                disabled={form.processing}
                                onClick={() =>
                                    form.submit(snipeItSync(company.id), {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                {form.processing ? <Spinner /> : <RefreshCw />}
                            </IconButton>
                        ) : null}
                        <IconButton label="Impostazioni Snipe-IT" variant="ghost" asChild>
                            <Link href={snipeItEdit(company.id)}>
                                <Settings2 />
                            </Link>
                        </IconButton>
                    </>
                ) : null
            }
        >
            {!ready ? (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-muted-foreground">
                        {data.external_company
                            ? `L’associazione con ${data.external_company} va riconfermata.`
                            : 'Nessuna azienda Snipe-IT associata.'}
                    </p>
                    {admin ? (
                        <Button asChild>
                            <Link href={snipeItEdit(company.id)}>
                                {data.external_company
                                    ? 'Riconferma'
                                    : 'Configura'}
                                <ArrowRight data-icon="inline-end" />
                            </Link>
                        </Button>
                    ) : null}
                </div>
            ) : (
                <>
                    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                        <span className="text-sm">
                            {data.external_company}
                        </span>
                        <LastRead value={data.synced_at} />
                    </div>
                    {data.error ? (
                        <p className="text-sm text-destructive">{data.error}</p>
                    ) : null}
                    {counts ? (
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {groups.map((group) => {
                                const content = (
                                    <>
                                        <span className="text-xs text-muted-foreground">
                                            {group.label}
                                        </span>
                                        <span className="text-xl font-medium tabular-nums">
                                            {group.count}
                                        </span>
                                    </>
                                );
                                const className =
                                    'flex flex-col items-start gap-1 rounded-lg border px-3 py-2.5 text-left';
                                return admin ? (
                                    <Link
                                        key={group.view}
                                        href={records(company.id, {
                                            query: { view: group.view },
                                        })}
                                        className={`${className} transition-colors outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50`}
                                    >
                                        {content}
                                    </Link>
                                ) : (
                                    <div key={group.view} className={className}>
                                        {content}
                                    </div>
                                );
                            })}
                        </div>
                    ) : null}
                    {admin && counts ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                asChild
                                variant={counts.pending ? 'default' : 'outline'}
                            >
                                <Link
                                    href={records(company.id, {
                                        query: { view: 'pending' },
                                    })}
                                >
                                    Gestisci asset
                                    <ArrowRight data-icon="inline-end" />
                                </Link>
                            </Button>
                            {counts.problems ? (
                                <Button asChild variant="ghost">
                                    <Link
                                        href={records(company.id, {
                                            query: {
                                                view: 'pending',
                                                problem: 1,
                                            },
                                        })}
                                    >
                                        <AlertTriangle data-icon="inline-start" />
                                        {counts.problems} da verificare
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    ) : null}
                </>
            )}
        </IntegrationCard>
    );
}

export default function CompanyIntegrations({
    company,
    snipeIt,
    mikrotik,
    mikrotikCandidates,
    unifi,
}: Props) {
    const { auth, companyNavigation } = usePage().props;
    const admin = auth.user.is_superadmin;
    const multipleSites = (companyNavigation?.sites.length ?? 0) > 1;
    const mikrotikStatus: Status = mikrotik.some((item) => item.error)
        ? { label: 'Errore', tone: 'error' }
        : mikrotik.length
          ? syncStatus(
                mikrotik.find((item) => item.activated_at)?.activated_at ??
                    null,
                null,
            )
          : { label: 'Da configurare', tone: 'idle' };
    const unifiStatus: Status = unifi.some((item) => item.error)
        ? { label: 'Errore', tone: 'error' }
        : unifi.length
          ? syncStatus(
                unifi.find((item) => item.inventory_activated_at)
                    ?.inventory_activated_at ?? null,
                null,
            )
          : { label: 'Da configurare', tone: 'idle' };
    const unifiHref = admin ? adminUnifi() : companyUnifi(company.id);
    const deviceLink = (device: { id: number; site_id: number }) =>
        admin
            ? deviceIntegrations(
                  { company: company.id, site: device.site_id, device: device.id },
                  { query: { tab: 'mikrotik' } },
              )
            : deviceShow({
                  company: company.id,
                  site: device.site_id,
                  device: device.id,
              });

    return (
        <>
            <Head title={`Integrazioni · ${company.name}`} />
            <div className="flex max-w-5xl flex-col gap-4 p-4 md:p-6">
                <SnipeIt company={company} data={snipeIt} admin={admin} />

                <IntegrationCard
                    mark={<MikrotikMark className="h-5" />}
                    title="MikroTik"
                    status={mikrotikStatus}
                >
                    {mikrotik.length ? (
                        <ul className="flex flex-col divide-y rounded-lg border">
                            {mikrotik.map((device) => (
                                <li
                                    key={device.id}
                                    className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-3 py-2.5"
                                >
                                    <div className="flex min-w-0 flex-col">
                                        <Link
                                            href={deviceLink(device)}
                                            className="truncate font-medium outline-none hover:text-primary focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        >
                                            {device.name}
                                        </Link>
                                        {multipleSites && device.site ? (
                                            <span className="text-xs text-muted-foreground">
                                                {device.site}
                                            </span>
                                        ) : null}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-3">
                                        {device.problems ? (
                                            <Badge variant="outline">
                                                <AlertTriangle />
                                                {device.problems} da verificare
                                            </Badge>
                                        ) : null}
                                        {device.error ? (
                                            <span className="text-sm text-destructive">
                                                {device.error}
                                            </span>
                                        ) : (
                                            <LastRead value={device.synced_at} />
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {mikrotikCandidates.length ? (
                        <div className="flex flex-col gap-2">
                            <span className="text-sm text-muted-foreground">
                                Apparati MikroTik senza connessione
                            </span>
                            <div className="flex flex-wrap gap-2">
                                {mikrotikCandidates.map((device) => (
                                    <Button
                                        key={device.id}
                                        asChild
                                        variant="outline"
                                        size="sm"
                                    >
                                        <Link href={deviceLink(device)}>
                                            {device.name}
                                            <ArrowRight data-icon="inline-end" />
                                        </Link>
                                    </Button>
                                ))}
                            </div>
                        </div>
                    ) : null}
                    {!mikrotik.length && !mikrotikCandidates.length ? (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm text-muted-foreground">
                                Nessun apparato MikroTik in inventario.
                            </p>
                            {companyNavigation?.currentSiteId ? (
                                <Button asChild variant="outline">
                                    <Link
                                        href={deviceIndex({
                                            company: company.id,
                                            site: companyNavigation.currentSiteId,
                                        })}
                                    >
                                        Dispositivi
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    ) : null}
                </IntegrationCard>

                <IntegrationCard
                    mark={<UnifiMark className="size-5" />}
                    title="UniFi"
                    status={unifiStatus}
                    actions={
                        unifi.length ? (
                            <IconButton
                                label="Impostazioni UniFi"
                                variant="ghost"
                                asChild
                            >
                                <Link href={unifiHref}>
                                    <Settings2 />
                                </Link>
                            </IconButton>
                        ) : null
                    }
                >
                    {unifi.length ? (
                        <ul className="flex flex-col divide-y rounded-lg border">
                            {unifi.map((site) => (
                                <li
                                    key={site.id}
                                    className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-3 py-2.5"
                                >
                                    <div className="flex min-w-0 flex-col">
                                        <span className="truncate font-medium">
                                            {site.console_name} · {site.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {[
                                                multipleSites ? site.site : null,
                                                `${site.linked_count} di ${site.devices_count} apparati collegati`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </div>
                                    {site.error ? (
                                        <span className="text-sm text-destructive">
                                            {site.error}
                                        </span>
                                    ) : (
                                        <LastRead value={site.last_synced_at} />
                                    )}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm text-muted-foreground">
                                Nessun sito UniFi collegato.
                            </p>
                            {admin ? (
                                <Button asChild variant="outline">
                                    <Link href={unifiHref}>
                                        Collega sito
                                        <ArrowRight data-icon="inline-end" />
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    )}
                </IntegrationCard>
            </div>
        </>
    );
}

CompanyIntegrations.layout = () => ({ breadcrumbs: [] });
