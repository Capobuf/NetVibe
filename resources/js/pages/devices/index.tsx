import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRight, Server } from 'lucide-react';
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
export default function Devices({
    company,
    site,
    devices,
    manufacturers,
    snipePending,
}: Props) {
    const { companyNavigation } = usePage().props;
    const [manufacturer, setManufacturer] = useState('all');
    const [type, setType] = useState('all');
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
        c: 'name' | 'manufacturer' | 'model' | 'type' | 'ips',
    ) =>
        c === 'manufacturer'
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
            <div className="flex min-w-0 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Server className="size-6 text-muted-foreground" />
                        <div>
                            <p className="text-sm text-muted-foreground">
                                {context}
                            </p>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Dispositivi
                            </h1>
                        </div>
                    </div>
                    <DeviceForm {...route} manufacturers={manufacturers} />
                </header>
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
                    ]}
                    value={value}
                    render={(d, c) =>
                        c === 'name' ? (
                            <TextLink href={show({ ...route, device: d.id })}>
                                {d.name}
                            </TextLink>
                        ) : (
                            <span
                                className={
                                    c === 'ips'
                                        ? 'font-mono text-xs'
                                        : undefined
                                }
                            >
                                {value(d, c) || '—'}
                            </span>
                        )
                    }
                    actions={(d) => (
                        <IconButton label={`Apri ${d.name}`} asChild>
                            <Link href={show({ ...route, device: d.id })}>
                                <ArrowRight />
                            </Link>
                        </IconButton>
                    )}
                    empty="Nessun dispositivo. Aggiungi il primo in questa sede."
                    filters={
                        <>
                            <Select
                                value={manufacturer}
                                onValueChange={setManufacturer}
                            >
                                <SelectTrigger aria-label="Filtra produttore">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectItem value="all">
                                            Tutti i produttori
                                        </SelectItem>
                                        {manufacturers.map((m) => (
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
                            <Select value={type} onValueChange={setType}>
                                <SelectTrigger aria-label="Filtra tipo">
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
