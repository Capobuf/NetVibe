import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRight } from 'lucide-react';
import { UnifiMark } from '@/components/unifi-mark';
import { unifiState } from '@/components/unifi/types';
import { ListPageHeader } from '@/components/list-page-header';
import { Badge } from '@/components/ui/badge';
import { DeviceForm } from '@/components/devices/device-form';
import { DeviceTable } from '@/components/devices/device-table';
import { IconButton } from '@/components/icon-button';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { records as snipeRecords } from '@/routes/companies/settings/integrations/snipe-it';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index, show } from '@/routes/companies/sites/devices';
import type { Company, Site } from '@/types/company';
import { type Device, type Manufacturer } from '@/types/device';

type Props = {
    company: Company;
    site: Site;
    devices: Device[];
    manufacturers: Manufacturer[];
    snipePending: number;
};
export default function Devices(props: Props) {
    return <DeviceList key={props.site.id} {...props} />;
}

function DeviceList({
    company,
    site,
    devices,
    manufacturers,
    snipePending,
}: Props) {
    const { companyNavigation } = usePage().props;
    const [manufacturer, setManufacturer] = useState('all');
    const [type, setType] = useState('all');
    const manufacturerIds = new Set(
        devices.map((device) => device.manufacturer_id),
    );
    const typeIds = new Set(
        devices.map((device) => device.model?.model_type_id ?? null),
    );
    const types = Array.from(
        new Map(
            devices.flatMap((device) =>
                device.model?.model_type
                    ? [
                          [
                              device.model.model_type.id,
                              device.model.model_type,
                          ] as const,
                      ]
                    : [],
            ),
        ).values(),
    ).sort((a, b) => a.name.localeCompare(b.name));
    const route = { company: company.id, site: site.id };
    const context =
        (companyNavigation?.sites.length ?? 0) > 1
            ? `${company.name} · ${site.name}`
            : company.name;
    const rows = devices.filter(
        (d) =>
            (manufacturer === 'all' ||
                d.manufacturer_id === Number(manufacturer)) &&
            (type === 'all' || d.model?.model_type_id === Number(type)),
    );
    const value = (
        d: Device,
        c:
            | 'name'
            | 'manufacturer'
            | 'model'
            | 'type'
            | 'ips'
            | 'source'
            | 'state',
    ) =>
        c === 'state'
            ? d.unifi
                ? unifiState(d.unifi.state, d.unifi.stale)
                : null
            : c === 'source'
              ? d.unifi
                  ? 'UniFi'
                  : null
              : c === 'manufacturer'
                ? d.manufacturer.name
                : c === 'model'
                  ? (d.model?.name ?? '—')
                  : c === 'type'
                    ? (d.model?.model_type?.name ?? '—')
                    : c === 'ips'
                      ? (d.ips?.join(', ') ?? '')
                      : d.name;
    return (
        <>
            <Head title={`Dispositivi · ${context}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <ListPageHeader
                    title="Dispositivi"
                    actions={
                        <DeviceForm {...route} manufacturers={manufacturers} />
                    }
                />
                {snipePending > 0 ? (
                    <Alert>
                        <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
                            <span>
                                Snipe-IT · {snipePending} dispositivi da
                                assegnare
                            </span>
                            <Button asChild variant="outline" size="sm">
                                <Link
                                    href={snipeRecords(company.id, {
                                        query: { view: 'sites' },
                                    })}
                                >
                                    Assegna sedi
                                </Link>
                            </Button>
                        </AlertDescription>
                    </Alert>
                ) : null}
                <DeviceTable
                    name="devices"
                    variant="list"
                    searchLabel="Cerca dispositivi"
                    total={devices.length}
                    filterKey={`${manufacturer}:${type}`}
                    rows={rows}
                    columns={[
                        { id: 'name', label: 'Nome', visible: true },
                        {
                            id: 'manufacturer',
                            label: 'Produttore',
                            visible: true,
                        },
                        { id: 'model', label: 'Modello', visible: true },
                        { id: 'type', label: 'Tipo', visible: true },
                        { id: 'ips', label: 'IP documentati', visible: true },
                        { id: 'source', label: 'Fonte', visible: false },
                        {
                            id: 'state',
                            label: 'Stato osservato',
                            visible: true,
                        },
                    ]}
                    hiddenColumns={
                        devices.some((device) => device.unifi)
                            ? []
                            : ['source', 'state']
                    }
                    value={value}
                    render={(d, c) =>
                        c === 'name' ? (
                            <div className="flex items-center gap-2">
                                {d.unifi ? (
                                    <span role="img" aria-label="Fonte UniFi">
                                        <UnifiMark className="size-4 shrink-0" />
                                    </span>
                                ) : null}
                                <TextLink
                                    href={show({ ...route, device: d.id })}
                                >
                                    {d.name}
                                </TextLink>
                            </div>
                        ) : c === 'ips' ? (
                            d.ips?.length ? (
                                <div className="flex flex-col gap-1 tabular-nums">
                                    {d.ips.map((ip) => (
                                        <span key={ip}>{ip}</span>
                                    ))}
                                </div>
                            ) : (
                                <span className="text-muted-foreground">—</span>
                            )
                        ) : c === 'type' && d.model?.model_type ? (
                            <Badge variant="secondary">
                                {d.model.model_type.name}
                            </Badge>
                        ) : (
                            value(d, c) || '—'
                        )
                    }
                    actions={(d) => (
                        <IconButton label={`Apri ${d.name}`} asChild>
                            <Link href={show({ ...route, device: d.id })}>
                                <ArrowRight />
                            </Link>
                        </IconButton>
                    )}
                    empty={
                        devices.length
                            ? 'Nessun dispositivo trovato'
                            : 'Nessun dispositivo'
                    }
                    filters={
                        <>
                            {manufacturerIds.size > 1 ||
                            manufacturer !== 'all' ? (
                                <Select
                                    value={manufacturer}
                                    onValueChange={setManufacturer}
                                >
                                    <SelectTrigger
                                        className="w-48 max-w-full"
                                        aria-label="Filtra produttore"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            <SelectItem value="all">
                                                Tutti i produttori
                                            </SelectItem>
                                            {manufacturers
                                                .filter(
                                                    (m) =>
                                                        manufacturerIds.has(
                                                            m.id,
                                                        ) ||
                                                        String(m.id) ===
                                                            manufacturer,
                                                )
                                                .map((m) => (
                                                    <SelectItem
                                                        key={m.id}
                                                        value={String(m.id)}
                                                    >
                                                        {m.name}
                                                    </SelectItem>
                                                ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            ) : null}
                            {typeIds.size > 1 || type !== 'all' ? (
                                <Select value={type} onValueChange={setType}>
                                    <SelectTrigger
                                        className="w-44 max-w-full"
                                        aria-label="Filtra tipo"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            <SelectItem value="all">
                                                Tutti i tipi
                                            </SelectItem>
                                            {types.map((type) => (
                                                <SelectItem
                                                    key={type.id}
                                                    value={String(type.id)}
                                                >
                                                    {type.name}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                            ) : null}
                            {manufacturer !== 'all' || type !== 'all' ? (
                                <Button
                                    variant="ghost"
                                    onClick={() => {
                                        setManufacturer('all');
                                        setType('all');
                                    }}
                                >
                                    Azzera filtri
                                </Button>
                            ) : null}
                        </>
                    }
                />
            </div>
        </>
    );
}
Devices.layout = ({ company, site }: Props) => ({
    breadcrumbs: [
        {
            title: 'Dispositivi',
            href: index({ company: company.id, site: site.id }),
        },
    ],
});
