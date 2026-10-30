import { ChevronDown, RefreshCw } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { Company, Site } from '@/types/company';
import type {
    IpStatus,
    Subnet,
    SubnetSummaryEntry,
    Tag,
    Vlan,
} from '@/types/network';
import type { RouteFormDefinition } from '@/wayfinder';

type ImpactIp = {
    id: number;
    subnet_id: number;
    ip: string;
    hostname: string | null;
    description: string | null;
    context?: {
        company_id: number;
        company_name: string;
        site_name: string | null;
        cidr: string;
    };
};
type Impact = {
    impact_token: string;
    ips: ImpactIp[];
    tag_links: { ip_address_id: number; tag_id: number }[];
} & (
    | {
          status: IpStatus;
          statuses: IpStatus[];
      }
    | {
          device: { id: number; name: string };
          interfaces: { id: number; name: string | null }[];
      }
    | {
          devices: { id: number; name: string; site_id: number }[];
          interfaces: { id: number; device_id: number; name: string | null }[];
          device_links: { id: number; device_id: number }[];
          scope: {
              company: number;
              site: number | null;
              subnet: number | null;
              vlan: number | null;
          };
          company: Company;
          sites: Site[];
          vlans: (Vlan & { site_id: number })[];
          subnets: (Subnet & { site_id: number })[];
          ranges: {
              id: number;
              subnet_id: number;
              start_ip: string;
              end_ip: string;
          }[];
          summaries: (SubnetSummaryEntry & { subnet_id: number })[];
          tags: Tag[];
      }
);

export function GuidedDeletion({
    previewUrl,
    action,
    kind,
    onCancel,
    onSuccess,
}: {
    previewUrl: string;
    action: RouteFormDefinition<'post'>;
    kind: 'company' | 'site' | 'subnet' | 'vlan' | 'status' | 'device';
    onCancel: () => void;
    onSuccess: () => void;
}) {
    const id = useId();
    const [impact, setImpact] = useState<Impact | null>(null);
    const [error, setError] = useState('');
    const [revision, setRevision] = useState(0);
    const [stale, setStale] = useState(false);
    const [mode, setMode] = useState(
        kind === 'vlan' ? 'keep_subnets' : 'clear',
    );
    const [replacement, setReplacement] = useState('');

    useEffect(() => {
        const controller = new AbortController();
        fetch(previewUrl, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        'Impossibile caricare l’anteprima. Riprova o riapri la pagina.',
                    );
                const data: Impact = await response.json();
                if (!controller.signal.aborted) setImpact(data);
            })
            .catch((failure: unknown) => {
                if (!controller.signal.aborted)
                    setError(
                        failure instanceof Error
                            ? failure.message
                            : 'Anteprima non disponibile.',
                    );
            });
        return () => controller.abort();
    }, [previewUrl, revision]);

    useEffect(() => {
        if (impact) document.getElementById(`${id}-impact`)?.focus();
    }, [impact, id]);

    function refresh() {
        setImpact(null);
        setError('');
        setStale(false);
        setReplacement('');
        setRevision((value) => value + 1);
    }

    if (!impact)
        return (
            <div className="flex flex-col gap-4" aria-live="polite">
                {error ? (
                    <InputError message={error} />
                ) : (
                    <p className="flex items-center gap-2 text-sm">
                        <Spinner /> Caricamento dell’impatto…
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <Button variant="outline" onClick={onCancel}>
                        Torna alla modifica
                    </Button>
                    {error ? <Button onClick={refresh}>Riprova</Button> : null}
                </div>
            </div>
        );

    if ('device' in impact)
        return (
            <DeletionConfirmation
                key={revision}
                action={action}
                onCancel={onCancel}
                onSuccess={onSuccess}
                onError={() => setStale(true)}
                disabled={stale}
                submitLabel="Elimina dispositivo e libera IP"
                fields={(processing) => (
                    <>
                        <input
                            type="hidden"
                            name="impact_token"
                            value={impact.impact_token}
                        />
                        {stale && (
                            <InputError message="I dati sono cambiati. Aggiorna l’anteprima." />
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={refresh}
                            disabled={processing}
                        >
                            <RefreshCw />
                            Aggiorna impatto
                        </Button>
                    </>
                )}
                details={
                    <div className="flex flex-col gap-2 text-sm">
                        <p>
                            {impact.interfaces.length} interfacce da eliminare ·{' '}
                            {impact.ips.length} record IP da eliminare
                        </p>
                        <ul className="max-h-52 overflow-y-auto">
                            {impact.ips.map((ip) => (
                                <li key={ip.id}>
                                    {ip.ip}
                                    {ip.hostname ? ` · ${ip.hostname}` : ''}
                                </li>
                            ))}
                        </ul>
                    </div>
                }
            >
                <p>
                    «{impact.device.name}» e le sue interfacce saranno eliminati
                    insieme ai record IP elencati, con hostname, descrizione,
                    MAC, stato e associazioni ai tag. Gli indirizzi torneranno
                    liberi; quelli esclusi resteranno esclusi dai suggerimenti.
                </p>
            </DeletionConfirmation>
        );

    const statuses = 'status' in impact ? impact.statuses : [];
    const lastSite =
        'scope' in impact && kind === 'site' && impact.sites.length === 1;
    const preserveNetworks = kind === 'vlan' && mode === 'keep_subnets';
    const label =
        kind === 'vlan'
            ? preserveNetworks
                ? 'Elimina solo VLAN'
                : 'Elimina VLAN e subnet'
            : kind === 'status'
              ? mode === 'delete_ips'
                  ? 'Elimina stato e IP'
                  : 'Elimina stato'
              : kind === 'company'
                ? 'Elimina azienda'
                : kind === 'site'
                  ? 'Elimina sede'
                  : 'Elimina subnet';
    const selectedStatus = statuses.find(
        (status) => String(status.id) === replacement,
    );

    return (
        <DeletionConfirmation
            key={revision}
            confirmationKey={`${mode}-${replacement}`}
            action={action}
            onCancel={onCancel}
            onSuccess={onSuccess}
            onError={() => setStale(true)}
            submitLabel={label}
            disabled={
                stale ||
                lastSite ||
                (kind === 'status' && mode === 'reassign' && !selectedStatus)
            }
            fields={(processing) => (
                <>
                    <input
                        type="hidden"
                        name="impact_token"
                        value={impact.impact_token}
                    />
                    <input type="hidden" name="mode" value={mode} />
                    <input
                        type="hidden"
                        name="replacement_status_id"
                        value={replacement}
                    />
                    {stale ? (
                        <InputError message="Aggiorna l’anteprima prima di riprovare." />
                    ) : null}
                    <FieldGroup>
                        {kind === 'vlan' || kind === 'status' ? (
                            <Field>
                                <FieldLabel htmlFor={`${id}-mode`}>
                                    Operazione
                                </FieldLabel>
                                <Select
                                    value={mode}
                                    onValueChange={setMode}
                                    disabled={processing}
                                >
                                    <SelectTrigger
                                        id={`${id}-mode`}
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {kind === 'vlan' ? (
                                                <>
                                                    <SelectItem value="keep_subnets">
                                                        Elimina solo VLAN
                                                    </SelectItem>
                                                    <SelectItem value="delete_subnets">
                                                        Elimina VLAN e subnet
                                                    </SelectItem>
                                                </>
                                            ) : (
                                                <>
                                                    <SelectItem value="clear">
                                                        Conserva gli IP senza
                                                        stato
                                                    </SelectItem>
                                                    <SelectItem
                                                        value="reassign"
                                                        disabled={
                                                            statuses.length ===
                                                            0
                                                        }
                                                    >
                                                        Riassegna gli IP a un
                                                        altro stato
                                                    </SelectItem>
                                                    <SelectItem value="delete_ips">
                                                        Elimina anche i record
                                                        IP
                                                    </SelectItem>
                                                </>
                                            )}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            </Field>
                        ) : null}
                        {kind === 'status' && mode === 'reassign' ? (
                            <Field>
                                <FieldLabel htmlFor={`${id}-replacement`}>
                                    Nuovo stato
                                </FieldLabel>
                                <Select
                                    value={replacement}
                                    onValueChange={setReplacement}
                                    disabled={processing}
                                >
                                    <SelectTrigger
                                        id={`${id}-replacement`}
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Scegli uno stato" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {statuses.map((status) => (
                                                <SelectItem
                                                    key={status.id}
                                                    value={String(status.id)}
                                                >
                                                    {status.name}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            </Field>
                        ) : null}
                    </FieldGroup>
                    <div className="flex items-center justify-between gap-2">
                        <h3
                            id={`${id}-impact`}
                            tabIndex={-1}
                            className="text-sm font-semibold"
                        >
                            Impatto dell’operazione
                        </h3>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={refresh}
                            disabled={processing}
                        >
                            <RefreshCw data-icon="inline-start" /> Aggiorna
                        </Button>
                    </div>
                </>
            )}
            details={<ImpactDetails impact={impact} kind={kind} />}
        >
            {'status' in impact ? (
                <p>
                    Lo stato <strong>{impact.status.name}</strong> verrà
                    eliminato.{' '}
                    {mode === 'delete_ips'
                        ? `Saranno eliminati definitivamente anche ${impact.ips.length} record IP e ${impact.tag_links.length} associazioni ai tag in tutte le aziende elencate.`
                        : mode === 'reassign'
                          ? `${impact.ips.length} IP saranno conservati e riassegnati a ${selectedStatus?.name ?? 'uno stato da scegliere'}, mantenendo gli altri dati e i tag.`
                          : `${impact.ips.length} IP saranno conservati senza stato, mantenendo gli altri dati e i tag.`}
                </p>
            ) : lastSite ? (
                <p>
                    Questa è l’unica sede dell’azienda. Per rimuovere tutto,
                    elimina l’azienda dal suo modale di modifica.
                </p>
            ) : (
                <>
                    <p>
                        {preserveNetworks
                            ? `Verrà eliminata solo la VLAN. Le ${impact.subnets.length} subnet diventeranno «Senza VLAN»; tutti gli IP, le esclusioni, i riepiloghi e i tag saranno conservati.`
                            : 'Saranno eliminati definitivamente il contenitore selezionato e tutti i dati elencati, comprese le associazioni ai tag.'}
                    </p>
                    {kind === 'site' && impact.sites.length === 2 ? (
                        <p>
                            L’altra sede sarà conservata e lavorerai
                            direttamente nel contesto azienda.
                        </p>
                    ) : null}
                    {!preserveNetworks && kind !== 'company' ? (
                        <p>Il catalogo aziendale dei tag sarà conservato.</p>
                    ) : null}
                </>
            )}
        </DeletionConfirmation>
    );
}

function ImpactDetails({ impact, kind }: { impact: Impact; kind: string }) {
    if ('device' in impact) return null;
    if ('status' in impact) {
        const companies = new Map<number, { name: string; ips: ImpactIp[] }>();
        for (const ip of impact.ips) {
            if (!ip.context) continue;
            const company = companies.get(ip.context.company_id) ?? {
                name: ip.context.company_name,
                ips: [],
            };
            company.ips.push(ip);
            companies.set(ip.context.company_id, company);
        }
        return (
            <div className="flex flex-col gap-3 text-sm">
                <div className="flex flex-wrap gap-2">
                    <Badge variant="secondary">{impact.ips.length} IP</Badge>
                    <Badge variant="secondary">{companies.size} aziende</Badge>
                </div>
                {[...companies].map(([companyId, company]) => (
                    <div key={companyId} className="rounded-lg border p-3">
                        <h4 className="mb-2 font-medium">
                            {company.name} · {company.ips.length} IP
                        </h4>
                        <ul className="flex max-h-52 flex-col gap-2 overflow-y-auto">
                            {company.ips.map((ip) => (
                                <li key={ip.id} className="break-words">
                                    <span className="font-mono">{ip.ip}</span>
                                    {ip.hostname ? ` · ${ip.hostname}` : ''}
                                    <span className="block text-xs text-muted-foreground">
                                        {[
                                            ip.context?.site_name,
                                            ip.context?.cidr,
                                            ip.description,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        );
    }
    const siteName = (siteId: number) =>
        impact.sites.length > 1
            ? impact.sites.find((site) => site.id === siteId)?.name
            : null;
    const selectedSite = impact.sites.find(
        (site) => site.id === impact.scope.site,
    );
    return (
        <div className="flex flex-col gap-3 text-sm">
            <p className="font-medium">
                {impact.company.name}
                {selectedSite && impact.sites.length > 1
                    ? ` · ${selectedSite.name}`
                    : ''}
            </p>
            {kind === 'company' && impact.sites.length > 1 ? (
                <p>Sedi: {impact.sites.map((site) => site.name).join(', ')}</p>
            ) : null}
            <div className="flex flex-wrap gap-2">
                {kind !== 'subnet' ? (
                    <Badge variant="secondary">
                        {impact.vlans.length} VLAN
                    </Badge>
                ) : null}
                <Badge variant="secondary">
                    {impact.subnets.length} subnet
                </Badge>
                <Badge variant="secondary">
                    {impact.ips.length} IP documentati
                </Badge>
                <Badge variant="secondary">
                    {impact.ranges.length} intervalli esclusi
                </Badge>
                <Badge variant="secondary">
                    {impact.summaries.length} voci di riepilogo
                </Badge>
                <Badge variant="secondary">
                    {impact.tag_links.length} associazioni ai tag
                </Badge>
                {kind === 'company' ? (
                    <Badge variant="secondary">
                        {impact.tags.length} tag aziendali
                    </Badge>
                ) : null}
            </div>
            {(kind === 'company' || kind === 'site') && (
                <div className="flex flex-col gap-2">
                    <p>
                        {impact.devices.length} dispositivi ·{' '}
                        {impact.interfaces.length} interfacce ·{' '}
                        {impact.device_links.length} collegamenti IP
                    </p>
                    <ul className="max-h-40 overflow-y-auto">
                        {impact.devices.map((device) => (
                            <li key={device.id}>
                                {device.name} ·{' '}
                                {
                                    impact.interfaces.filter(
                                        (i) => i.device_id === device.id,
                                    ).length
                                }{' '}
                                interfacce
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {impact.vlans.length ? (
                <ul className="flex flex-wrap gap-2">
                    {impact.vlans.map((vlan) => (
                        <li key={vlan.id}>
                            <Badge variant="outline">
                                {siteName(vlan.site_id)
                                    ? `${siteName(vlan.site_id)} · `
                                    : ''}
                                VLAN {vlan.tag}
                                {vlan.name ? ` · ${vlan.name}` : ''}
                            </Badge>
                        </li>
                    ))}
                </ul>
            ) : null}
            {impact.subnets.map((subnet) => {
                const ips = impact.ips.filter(
                    (ip) => ip.subnet_id === subnet.id,
                );
                const ranges = impact.ranges.filter(
                    (range) => range.subnet_id === subnet.id,
                );
                const summaries = impact.summaries.filter(
                    (entry) => entry.subnet_id === subnet.id,
                );
                return (
                    <Collapsible
                        key={subnet.id}
                        className="rounded-lg border p-3"
                    >
                        <CollapsibleTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                className="h-auto w-full justify-between text-left whitespace-normal"
                            >
                                <span>
                                    <span className="font-mono">
                                        {subnet.cidr}
                                    </span>
                                    {subnet.name ? ` · ${subnet.name}` : ''}
                                    <span className="block text-xs text-muted-foreground">
                                        {siteName(subnet.site_id)
                                            ? `${siteName(subnet.site_id)} · `
                                            : ''}
                                        {ips.length} IP · {ranges.length}{' '}
                                        esclusioni · {summaries.length}{' '}
                                        riepiloghi
                                    </span>
                                </span>
                                <ChevronDown data-icon="inline-end" />
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="pt-3">
                            <ul className="flex max-h-64 flex-col gap-2 overflow-y-auto break-words">
                                {ips.map((ip) => (
                                    <li key={`ip-${ip.id}`}>
                                        <span className="font-mono">
                                            {ip.ip}
                                        </span>
                                        {[ip.hostname, ip.description]
                                            .filter(Boolean)
                                            .map((value, index) => (
                                                <span key={index}>
                                                    {' '}
                                                    · {value}
                                                </span>
                                            ))}
                                    </li>
                                ))}
                                {ranges.map((range) => (
                                    <li key={`range-${range.id}`}>
                                        Esclusione:{' '}
                                        <span className="font-mono">
                                            {range.start_ip} – {range.end_ip}
                                        </span>
                                    </li>
                                ))}
                                {summaries.map((entry) => (
                                    <li key={`summary-${entry.id}`}>
                                        {entry.name}: {entry.values.join(', ')}
                                    </li>
                                ))}
                                {!ips.length &&
                                !ranges.length &&
                                !summaries.length ? (
                                    <li className="text-muted-foreground">
                                        Nessun IP, intervallo escluso o
                                        riepilogo.
                                    </li>
                                ) : null}
                            </ul>
                        </CollapsibleContent>
                    </Collapsible>
                );
            })}
            {kind === 'company' && impact.tags.length ? (
                <p>Tag: {impact.tags.map((tag) => tag.name).join(', ')}</p>
            ) : null}
        </div>
    );
}
