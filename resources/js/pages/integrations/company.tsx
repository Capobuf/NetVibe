import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AlertTriangle, ArrowRight, RefreshCw, Settings2 } from 'lucide-react';
import { IconButton } from '@/components/icon-button';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { SnipeItMark } from '@/components/snipe-it-mark';
import { UnifiMark } from '@/components/unifi-mark';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
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

type View = 'pending' | 'linked' | 'ipam' | 'excluded';
type Counts = Record<View | 'problems', number> & {
    problems_by_view: Partial<Record<View, number>>;
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

function syncStatus(activated: string | null, error: string | null): Status {
    if (error) return { label: 'Errore', tone: 'error' };
    return activated
        ? { label: 'Attiva · ogni ora', tone: 'ok' }
        : { label: 'Da avviare', tone: 'idle' };
}

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

function LastRead({ value }: { value: string | null }) {
    return (
        <span className="text-xs text-muted-foreground tabular-nums">
            {value
                ? `Ultima lettura ${formatDateTime(value)}`
                : 'Mai sincronizzata'}
        </span>
    );
}

/** Same card layout as the administration integrations page, with status and a summary. */
function IntegrationCard({
    mark,
    title,
    status,
    actions,
    footer,
    children,
}: {
    mark: ReactNode;
    title: string;
    status: Status;
    actions?: ReactNode;
    footer?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card className="h-full">
            <CardHeader className="flex-row items-start justify-between gap-3">
                <div className="flex flex-col gap-2">
                    <CardTitle className="flex items-center gap-3">
                        {mark}
                        {title}
                    </CardTitle>
                    <div>
                        <StatusBadge status={status} />
                    </div>
                </div>
                {actions ? (
                    <div className="-mt-1.5 -mr-2 flex items-center">
                        {actions}
                    </div>
                ) : null}
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-3">
                {children}
            </CardContent>
            {footer ? <CardFooter className="gap-2">{footer}</CardFooter> : null}
        </Card>
    );
}

function ManageButton({
    href,
    children,
    primary,
}: {
    href: Parameters<typeof Link>[0]['href'];
    children: ReactNode;
    primary?: boolean;
}) {
    return (
        <Button
            asChild
            variant={primary ? 'default' : 'outline'}
            className="w-full justify-between"
        >
            <Link href={href}>
                {children}
                <ArrowRight data-icon="inline-end" />
            </Link>
        </Button>
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
    const groups: { view: View; label: string }[] = [
        { view: 'pending', label: 'Da decidere' },
        { view: 'linked', label: 'Apparati' },
        { view: 'ipam', label: 'Client IPAM' },
        { view: 'excluded', label: 'Esclusi' },
    ];
    const problemView = counts
        ? ((['pending', 'ipam', 'linked'] as const).find(
              (view) => counts.problems_by_view[view],
          ) ?? 'pending')
        : 'pending';

    return (
        <IntegrationCard
            mark={<SnipeItMark className="size-6" />}
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
                        {ready ? (
                            <IconButton
                                label="Impostazioni Snipe-IT"
                                variant="ghost"
                                asChild
                            >
                                <Link href={snipeItEdit(company.id)}>
                                    <Settings2 />
                                </Link>
                            </IconButton>
                        ) : null}
                    </>
                ) : null
            }
            footer={
                admin ? (
                    ready ? (
                        <ManageButton
                            href={records(company.id, {
                                query: { view: 'pending' },
                            })}
                            primary={!!counts?.pending}
                        >
                            Gestisci asset
                        </ManageButton>
                    ) : (
                        <ManageButton href={snipeItEdit(company.id)} primary>
                            {data.external_company
                                ? 'Riconferma azienda'
                                : 'Configura Snipe-IT'}
                        </ManageButton>
                    )
                ) : null
            }
        >
            {!ready ? (
                <p className="text-sm text-muted-foreground">
                    {data.external_company
                        ? `L’associazione con ${data.external_company} va riconfermata.`
                        : 'Nessuna azienda Snipe-IT associata.'}
                </p>
            ) : (
                <>
                    <div className="flex flex-col">
                        <span className="text-sm">{data.external_company}</span>
                        <LastRead value={data.synced_at} />
                    </div>
                    {data.error ? (
                        <p className="text-sm text-destructive">{data.error}</p>
                    ) : null}
                    {counts ? (
                        <div className="grid grid-cols-2 gap-2">
                            {groups.map((group) => {
                                const content = (
                                    <>
                                        <span className="text-xs text-muted-foreground">
                                            {group.label}
                                        </span>
                                        <span className="text-lg font-medium tabular-nums">
                                            {counts[group.view]}
                                        </span>
                                    </>
                                );
                                const className =
                                    'flex flex-col items-start rounded-lg border px-3 py-2 text-left';
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
                    {admin && counts?.problems ? (
                        <Link
                            href={records(company.id, {
                                query: { view: problemView, problem: 1 },
                            })}
                            className="flex items-center gap-2 self-start rounded-md text-sm text-amber-700 outline-none hover:text-amber-600 focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:text-amber-500 dark:hover:text-amber-400"
                        >
                            <AlertTriangle className="size-4" />
                            {counts.problems} da verificare
                        </Link>
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
                  {
                      company: company.id,
                      site: device.site_id,
                      device: device.id,
                  },
                  { query: { tab: 'mikrotik' } },
              )
            : deviceShow({
                  company: company.id,
                  site: device.site_id,
                  device: device.id,
              });
    const devicesHref = companyNavigation?.currentSiteId
        ? deviceIndex({
              company: company.id,
              site: companyNavigation.currentSiteId,
          })
        : null;

    return (
        <>
            <Head title={`Integrazioni · ${company.name}`} />
            <div className="grid max-w-6xl auto-rows-fr gap-4 p-4 md:grid-cols-2 md:p-6 xl:grid-cols-3">
                <SnipeIt company={company} data={snipeIt} admin={admin} />

                <IntegrationCard
                    mark={<MikrotikMark className="h-6" />}
                    title="MikroTik"
                    status={mikrotikStatus}
                    footer={
                        mikrotik.length === 1 ? (
                            <ManageButton href={deviceLink(mikrotik[0])}>
                                Gestisci {mikrotik[0].name}
                            </ManageButton>
                        ) : devicesHref ? (
                            <ManageButton href={devicesHref}>
                                Dispositivi
                            </ManageButton>
                        ) : null
                    }
                >
                    {mikrotik.length ? (
                        <ul className="flex flex-col gap-2">
                            {mikrotik.map((device) => (
                                <li
                                    key={device.id}
                                    className="flex flex-col rounded-lg border px-3 py-2"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <Link
                                            href={deviceLink(device)}
                                            className="truncate rounded-sm text-sm font-medium outline-none hover:text-primary focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        >
                                            {device.name}
                                        </Link>
                                        {device.problems ? (
                                            <span className="flex shrink-0 items-center gap-1 text-xs text-amber-700 dark:text-amber-500">
                                                <AlertTriangle className="size-3.5" />
                                                {device.problems} da verificare
                                            </span>
                                        ) : null}
                                    </div>
                                    {multipleSites && device.site ? (
                                        <span className="text-xs text-muted-foreground">
                                            {device.site}
                                        </span>
                                    ) : null}
                                    {device.error ? (
                                        <span className="text-xs text-destructive">
                                            {device.error}
                                        </span>
                                    ) : (
                                        <LastRead value={device.synced_at} />
                                    )}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Nessun apparato MikroTik collegato.
                        </p>
                    )}
                    {mikrotikCandidates.length ? (
                        <div className="flex flex-col gap-1.5">
                            <span className="text-xs text-muted-foreground">
                                {mikrotikCandidates.length === 1
                                    ? '1 apparato MikroTik senza connessione'
                                    : `${mikrotikCandidates.length} apparati MikroTik senza connessione`}
                            </span>
                            <div className="flex flex-wrap gap-1.5">
                                {mikrotikCandidates.map((device) => (
                                    <Badge
                                        key={device.id}
                                        variant="outline"
                                        asChild
                                    >
                                        <Link href={deviceLink(device)}>
                                            {device.name}
                                        </Link>
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    ) : null}
                </IntegrationCard>

                <IntegrationCard
                    mark={<UnifiMark className="size-6" />}
                    title="UniFi"
                    status={unifiStatus}
                    footer={
                        admin || unifi.length ? (
                            <ManageButton href={unifiHref}>
                                {unifi.length ? 'Gestisci UniFi' : 'Collega sito UniFi'}
                            </ManageButton>
                        ) : null
                    }
                >
                    {unifi.length ? (
                        <ul className="flex flex-col gap-2">
                            {unifi.map((site) => (
                                <li
                                    key={site.id}
                                    className="flex flex-col rounded-lg border px-3 py-2"
                                >
                                    <span className="truncate text-sm font-medium">
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
                                    {site.error ? (
                                        <span className="text-xs text-destructive">
                                            {site.error}
                                        </span>
                                    ) : (
                                        <LastRead value={site.last_synced_at} />
                                    )}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Nessun sito UniFi collegato.
                        </p>
                    )}
                </IntegrationCard>
            </div>
        </>
    );
}

CompanyIntegrations.layout = () => ({ breadcrumbs: [] });
