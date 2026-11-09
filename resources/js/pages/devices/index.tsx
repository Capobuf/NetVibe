import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRight, Filter } from 'lucide-react';
import { useSiteContext } from '@/hooks/use-site-context';
import { UnifiMark } from '@/components/unifi-mark';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { unifiState } from '@/components/unifi/types';
import { ListPageHeader } from '@/components/list-page-header';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { DeviceForm } from '@/components/devices/device-form';
import { DeviceImage } from '@/components/devices/device-image';
import { DataTable } from '@/components/data-table';
import { IconButton } from '@/components/icon-button';
import { DeviceIps } from '@/components/devices/device-ips';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import {
    Popover,
    PopoverTrigger,
    PopoverContent,
    PopoverHeader,
    PopoverTitle,
} from '@/components/ui/popover';
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
import { index as modelIndex } from '@/routes/device-models';
import type { Company, Site } from '@/types/company';
import {
    type Device,
    type DeviceView,
    type Manufacturer,
} from '@/types/device';

type Props = {
    company: Company;
    site: Site;
    devices: Device[];
    manufacturers: Manufacturer[];
    snipePending: number;
    view: DeviceView;
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
    view,
}: Props) {
    const [manufacturer, setManufacturer] = useState('all');
    const [type, setType] = useState('all');
    const activeFilters =
        Number(manufacturer !== 'all') + Number(type !== 'all');
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
    const context = useSiteContext(company, site);
    const viewDevices =
        view === 'all'
            ? devices
            : devices.filter((device) => device.is_infrastructure);
    const rows = viewDevices.filter(
        (d) =>
            (manufacturer === 'all' ||
                d.manufacturer_id === Number(manufacturer)) &&
            (type === 'all' || d.model?.model_type_id === Number(type)),
    );
    const value = (
        d: Device,
        c:
            | 'image'
            | 'name'
            | 'manufacturer'
            | 'model'
            | 'type'
            | 'ips'
            | 'source'
            | 'state',
    ) =>
        c === 'image'
            ? null
            : c === 'state'
              ? d.unifi
                  ? unifiState(d.unifi.state, d.unifi.stale)
                  : null
              : c === 'source'
                ? [
                      d.mikrotik_connection_exists ? 'MikroTik' : null,
                      d.unifi ? 'UniFi' : null,
                  ]
                      .filter(Boolean)
                      .join(', ') || null
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
                        <DeviceForm
                            {...route}
                            manufacturers={manufacturers}
                            view={view}
                        />
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
                <Tabs
                    value={view}
                    onValueChange={(value) =>
                        router.get(
                            index.url(route, { query: { view: value } }),
                            {},
                            { preserveState: true, preserveScroll: true },
                        )
                    }
                    className="gap-4"
                >
                    <TabsList aria-label="Vista dispositivi">
                        <TabsTrigger value="infrastructure">
                            Infrastruttura
                        </TabsTrigger>
                        <TabsTrigger value="all">Tutti</TabsTrigger>
                    </TabsList>
                    <TabsContent value={view} forceMount>
                        <DataTable
                            name="devices"
                            variant="list"
                            searchLabel="Cerca dispositivi"
                            total={viewDevices.length}
                            filterKey={`${view}:${manufacturer}:${type}`}
                            rows={rows}
                            columns={[
                                {
                                    id: 'image',
                                    label: 'Immagine',
                                    visible: true,
                                    sortable: false,
                                },
                                { id: 'name', label: 'Nome', visible: true },
                                {
                                    id: 'manufacturer',
                                    label: 'Produttore',
                                    visible: true,
                                },
                                {
                                    id: 'model',
                                    label: 'Modello',
                                    visible: true,
                                },
                                { id: 'type', label: 'Tipo', visible: true },
                                {
                                    id: 'ips',
                                    label: 'IP documentati',
                                    visible: true,
                                },
                                {
                                    id: 'source',
                                    label: 'Fonte',
                                    visible: false,
                                },
                                {
                                    id: 'state',
                                    label: 'Stato osservato',
                                    visible: true,
                                },
                            ]}
                            hiddenColumns={
                                devices.some((device) => device.unifi)
                                    ? []
                                    : devices.some(
                                            (device) =>
                                                device.mikrotik_connection_exists,
                                        )
                                      ? ['state']
                                      : ['source', 'state']
                            }
                            value={value}
                            render={(d, c) =>
                                c === 'image' ? (
                                    <DeviceImage
                                        src={d.image_url}
                                        name={d.name}
                                    />
                                ) : c === 'name' ? (
                                    <div className="flex items-center gap-2">
                                        {d.mikrotik_connection_exists ? (
                                            <span
                                                role="img"
                                                aria-label="Integrazione MikroTik"
                                            >
                                                <MikrotikMark className="size-4 shrink-0" />
                                            </span>
                                        ) : null}
                                        {d.unifi ? (
                                            <span
                                                role="img"
                                                aria-label="Fonte UniFi"
                                            >
                                                <UnifiMark className="size-4 shrink-0" />
                                            </span>
                                        ) : null}
                                        <TextLink
                                            href={show(
                                                { ...route, device: d.id },
                                                { query: { view } },
                                            )}
                                            className="whitespace-nowrap"
                                        >
                                            {d.name}
                                        </TextLink>
                                    </div>
                                ) : c === 'manufacturer' ? (
                                    <TextLink
                                        href={modelIndex({
                                            query: {
                                                manufacturer: d.manufacturer_id,
                                            },
                                        })}
                                        className="whitespace-nowrap"
                                    >
                                        {d.manufacturer.name}
                                    </TextLink>
                                ) : c === 'model' && d.model ? (
                                    <TextLink
                                        href={modelIndex({
                                            query: { model: d.model.id },
                                        })}
                                        className="whitespace-nowrap"
                                    >
                                        {d.model.name}
                                    </TextLink>
                                ) : c === 'ips' ? (
                                    d.ips?.length ? (
                                        <DeviceIps ips={d.ips} name={d.name} />
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )
                                ) : c === 'type' && d.model?.model_type ? (
                                    <Badge variant="secondary">
                                        {d.model.model_type.name}
                                    </Badge>
                                ) : (
                                    <span className="whitespace-nowrap">
                                        {value(d, c) || '—'}
                                    </span>
                                )
                            }
                            actions={(d) => (
                                <IconButton label={`Apri ${d.name}`} asChild>
                                    <Link
                                        href={show(
                                            { ...route, device: d.id },
                                            { query: { view } },
                                        )}
                                    >
                                        <ArrowRight />
                                    </Link>
                                </IconButton>
                            )}
                            empty={
                                devices.length
                                    ? 'Nessun dispositivo trovato'
                                    : view === 'infrastructure'
                                      ? 'Nessun dispositivo in Infrastruttura'
                                      : 'Nessun dispositivo'
                            }
                            headerActions={
                                manufacturerIds.size > 1 ||
                                typeIds.size > 1 ||
                                activeFilters > 0 ? (
                                    <Popover>
                                        <PopoverTrigger asChild>
                                            <IconButton
                                                label={
                                                    activeFilters
                                                        ? `Filtri (${activeFilters} attivi)`
                                                        : 'Filtri'
                                                }
                                                variant={
                                                    activeFilters
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                <Filter />
                                            </IconButton>
                                        </PopoverTrigger>
                                        <PopoverContent
                                            align="end"
                                            aria-labelledby="device-filters-title"
                                            className="flex flex-col gap-4"
                                        >
                                            <PopoverHeader>
                                                <PopoverTitle id="device-filters-title">
                                                    Filtri
                                                </PopoverTitle>
                                            </PopoverHeader>
                                            <FieldGroup>
                                                {manufacturerIds.size > 1 ||
                                                manufacturer !== 'all' ? (
                                                    <Field>
                                                        <FieldLabel htmlFor="device-manufacturer-filter">
                                                            Produttore
                                                        </FieldLabel>
                                                        <Select
                                                            value={manufacturer}
                                                            onValueChange={
                                                                setManufacturer
                                                            }
                                                        >
                                                            <SelectTrigger
                                                                id="device-manufacturer-filter"
                                                                className="w-full"
                                                            >
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectGroup>
                                                                    <SelectItem value="all">
                                                                        Tutti i
                                                                        produttori
                                                                    </SelectItem>
                                                                    {manufacturers
                                                                        .filter(
                                                                            (
                                                                                m,
                                                                            ) =>
                                                                                manufacturerIds.has(
                                                                                    m.id,
                                                                                ) ||
                                                                                String(
                                                                                    m.id,
                                                                                ) ===
                                                                                    manufacturer,
                                                                        )
                                                                        .map(
                                                                            (
                                                                                m,
                                                                            ) => (
                                                                                <SelectItem
                                                                                    key={
                                                                                        m.id
                                                                                    }
                                                                                    value={String(
                                                                                        m.id,
                                                                                    )}
                                                                                >
                                                                                    {
                                                                                        m.name
                                                                                    }
                                                                                </SelectItem>
                                                                            ),
                                                                        )}
                                                                </SelectGroup>
                                                            </SelectContent>
                                                        </Select>
                                                    </Field>
                                                ) : null}
                                                {typeIds.size > 1 ||
                                                type !== 'all' ? (
                                                    <Field>
                                                        <FieldLabel htmlFor="device-type-filter">
                                                            Tipo
                                                        </FieldLabel>
                                                        <Select
                                                            value={type}
                                                            onValueChange={
                                                                setType
                                                            }
                                                        >
                                                            <SelectTrigger
                                                                id="device-type-filter"
                                                                className="w-full"
                                                            >
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectGroup>
                                                                    <SelectItem value="all">
                                                                        Tutti i
                                                                        tipi
                                                                    </SelectItem>
                                                                    {types.map(
                                                                        (
                                                                            type,
                                                                        ) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    type.id
                                                                                }
                                                                                value={String(
                                                                                    type.id,
                                                                                )}
                                                                            >
                                                                                {
                                                                                    type.name
                                                                                }
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                                </SelectGroup>
                                                            </SelectContent>
                                                        </Select>
                                                    </Field>
                                                ) : null}
                                                {manufacturer !== 'all' ||
                                                type !== 'all' ? (
                                                    <Button
                                                        variant="ghost"
                                                        onClick={() => {
                                                            setManufacturer(
                                                                'all',
                                                            );
                                                            setType('all');
                                                        }}
                                                    >
                                                        Azzera filtri
                                                    </Button>
                                                ) : null}
                                            </FieldGroup>
                                        </PopoverContent>
                                    </Popover>
                                ) : null
                            }
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}
Devices.layout = ({ company, site, view }: Props) => ({
    breadcrumbs: [
        {
            title: 'Dispositivi',
            href: index(
                { company: company.id, site: site.id },
                { query: { view } },
            ),
        },
    ],
});
