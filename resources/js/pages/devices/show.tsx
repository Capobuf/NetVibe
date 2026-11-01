import { Head, Link, usePage } from '@inertiajs/react';
import { useId, useRef, useState } from 'react';
import { ArrowLeft, Unlink, Pencil, X } from 'lucide-react';
import { DeviceForm } from '@/components/devices/device-form';
import { SnipeItMark } from '@/components/snipe-it-mark';
import type { MikrotikConnection } from '@/components/devices/mikrotik-device';
import { type SnipeDevice } from '@/components/devices/snipe-it-device';
import { DeviceTable } from '@/components/devices/device-table';
import {
    PanelViewer,
    type PortAnchor,
} from '@/components/devices/panel-viewer';
import { NewIpMenu } from '@/components/devices/new-ip-menu';
import { InterfaceDetails } from '@/components/devices/interface-details';
import { InterfaceForm } from '@/components/devices/interface-form';
import { MikrotikInterfaceBadge } from '@/components/devices/mikrotik-interface-badge';
import { AddIpForm, type AvailableIp } from '@/components/devices/add-ip-form';
import IpAddressForm from '@/components/networks/ip-address-form';
import { Separator } from '@/components/ui/separator';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { IconButton } from '@/components/icon-button';
import TextLink from '@/components/text-link';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import {
    Popover,
    PopoverContent,
    PopoverAnchor,
    PopoverArrow,
    PopoverHeader,
    PopoverTitle,
} from '@/components/ui/popover';
import { index, show } from '@/routes/companies/sites/devices';
import { ReleaseIp } from '@/components/devices/release-ip';
import { show as subnetShow } from '@/routes/companies/sites/subnets';
import { connectorTypes, type Port } from '@/lib/hardware-editor/document';
import {
    interfaceName,
    interfaceMac,
    type Device,
    type DeviceInterface,
    type Manufacturer,
    type MikrotikInterface,
} from '@/types/device';
import type { Company, Site } from '@/types/company';
import type {
    Subnet,
    Vlan,
    ExcludedRange,
    IpStatus,
    Tag,
} from '@/types/network';

type LinkedIp = AvailableIp & {
    device_interface_id: number | null;
    mac_address: string | null;
    subnet: Subnet & { vlan: Vlan | null };
};
type InterfaceRow = DeviceInterface | Port;
type Props = {
    company: Company;
    site: Site;
    device: Device;
    snipeIt: SnipeDevice | null;
    isMikrotik: boolean;
    mikrotik: MikrotikConnection | null;
    mikrotikInterfaces: MikrotikInterface[];
    initialTab: string;
    initialManagementTab: string | null;
    manufacturers: Manufacturer[];
    assetUrls: Record<string, string>;
    initialInterfaceId: number | null;
    ips: LinkedIp[];
    availableIps: AvailableIp[];
    subnets: (Subnet & { excludedRanges: ExcludedRange[] })[];
    statuses: IpStatus[];
    tags: Tag[];
};
export default function DeviceShow({
    company,
    site,
    device,
    snipeIt,
    isMikrotik,
    mikrotik,
    mikrotikInterfaces,
    initialTab,
    initialManagementTab,
    manufacturers,
    assetUrls,
    ips,
    availableIps,
    subnets,
    statuses,
    tags,
    initialInterfaceId,
}: Props) {
    const { companyNavigation } = usePage().props;
    const [tab, setTab] = useState(initialTab);
    const [releasingIp, setReleasingIp] = useState<{
        id: number;
        ip: string;
    } | null>(null);
    const releaseFocus = useRef<HTMLElement | null>(null);
    function releaseIp(ip: { id: number; ip: string }, trigger: HTMLElement) {
        releaseFocus.current = trigger;
        setReleasingIp(ip);
        setPortOpen(false);
    }
    const [port, setPort] = useState<Port | null>(null);
    const [portOpen, setPortOpen] = useState(false);
    const portAnchor = useRef<PortAnchor | null>(null);
    const portFocus = useRef<HTMLElement | null>(null);
    const portTitleId = useId();
    const initialInterface = device.interfaces.find(
        (item) => item.id === initialInterfaceId,
    );
    const initialPort = device.model?.panel
        ? [
              ...device.model.panel.faces.front.ports,
              ...device.model.panel.faces.rear.ports,
          ].find(
              (port) =>
                  port.id === initialInterface?.connector_id && !port.hidden,
          )
        : undefined;
    const [interfaceId, setInterfaceId] = useState<number | null>(
        initialPort ? null : initialInterfaceId,
    );
    const [documentingInterfaceId, setDocumentingInterfaceId] = useState<
        number | undefined
    >();
    const [documentingSubnet, setDocumentingSubnet] = useState<
        Props['subnets'][number] | null
    >(null);
    const returnFocus = useRef<HTMLElement | null>(null);
    const editingInterface = device.interfaces.find(
        (item) => item.id === interfaceId,
    );
    const interfaceIps = ips.filter(
        (ip) => ip.device_interface_id === interfaceId,
    );
    function editInterface(
        id: number,
        trigger = document.getElementById(`interface-${id}`) ??
            document.getElementById(`row-device-interfaces-${id}`),
    ) {
        returnFocus.current = trigger;
        setInterfaceId(id);
    }
    const route = { company: company.id, site: site.id, device: device.id };
    const panel = device.model?.panel;
    const ports = panel
        ? [...panel.faces.front.ports, ...panel.faces.rear.ports]
        : [];
    const interfaceRows: InterfaceRow[] = [
        ...device.interfaces,
        ...ports.filter(
            (port) =>
                !device.interfaces.some(
                    (item) => item.connector_id === port.id,
                ),
        ),
    ];
    function interfaceRowValue(row: InterfaceRow, column: string) {
        const item = 'connector_id' in row ? row : null;
        const connector =
            'connector_id' in row
                ? ports.find((port) => port.id === row.connector_id)
                : row;
        switch (column) {
            case 'name':
                return 'connector_id' in row
                    ? interfaceName(row, ports)
                    : row.label || connectorTypes[row.type].name;
            case 'connector':
                return connector?.label || '—';
            case 'port_type':
                return connector?.type === 'rj45'
                    ? 'Ethernet (RJ45)'
                    : connector
                      ? connectorTypes[connector.type].name
                      : '—';
            case 'kind':
                return item?.kind === 'logical' ? 'Logica' : 'Fisica';
            case 'ips':
                return item
                    ? ips
                          .filter((ip) => ip.device_interface_id === item.id)
                          .map((ip) => ip.ip)
                          .join(', ')
                    : null;
            default:
                return item ? interfaceMac(item) : null;
        }
    }
    function openInterfaceRow(row: InterfaceRow) {
        if ('connector_id' in row) {
            editInterface(row.id);
        }
    }
    const selected = port
        ? device.interfaces.find((i) => i.connector_id === port.id)
        : null;
    const context =
        (companyNavigation?.sites.length ?? 0) > 1
            ? `${company.name} · ${site.name}`
            : company.name;
    const interfaceValue = (id: number | null) =>
        id
            ? interfaceName(
                  device.interfaces.find((i) => i.id === id)!,
                  ports,
              )
            : 'Solo dispositivo';
    const ipUrl = (ip: LinkedIp) =>
        subnetShow(
            { company: company.id, site: site.id, subnet: ip.subnet.id },
            { query: { ip_address: ip.id } },
        );
    return (
        <>
            <Head title={`${device.name} · ${context}`} />
            <div className="flex min-w-0 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={index(route)}>
                            <ArrowLeft data-icon="inline-start" />
                            Dispositivi
                        </Link>
                    </Button>
                </div>
                <header className="flex min-w-0 flex-col gap-5">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex min-w-0 flex-1 flex-col gap-2">
                            <p className="text-sm text-muted-foreground">
                                {context}
                            </p>
                            <div className="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-2">
                                <div className="flex min-w-0 items-center gap-3">
                                    {snipeIt ? (
                                        <span
                                            className="shrink-0"
                                            role="img"
                                            aria-label="Collegato a Snipe-IT"
                                        >
                                            <SnipeItMark className="size-8" />
                                        </span>
                                    ) : null}
                                    <h1 className="min-w-0 text-3xl font-semibold tracking-tight break-words">
                                        {device.name}
                                    </h1>
                                </div>
                                {device.model?.model_type?.name ? (
                                    <Badge variant="secondary">
                                        {device.model.model_type.name}
                                    </Badge>
                                ) : null}
                            </div>
                            <p className="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                                <span className="text-muted-foreground">
                                    {device.manufacturer.name}
                                </span>
                                {device.model?.name ? (
                                    <span className="min-w-0 font-medium break-words">
                                        {device.model.name}
                                    </span>
                                ) : null}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <DeviceForm
                                company={company.id}
                                site={site.id}
                                manufacturers={manufacturers}
                                device={device}
                                snipeIt={snipeIt}
                                isMikrotik={isMikrotik}
                                mikrotik={mikrotik}
                                initialManagementTab={initialManagementTab}
                            />
                        </div>
                    </div>
                    {device.serial_number || device.asset_tag ? (
                        <dl className="flex min-w-0 flex-wrap gap-x-10 gap-y-3 border-t pt-4">
                            {device.serial_number ? (
                                <div className="flex min-w-0 flex-col gap-1">
                                    <dt className="text-xs text-muted-foreground">
                                        Seriale
                                    </dt>
                                    <dd className="text-sm font-medium break-all">
                                        {device.serial_number}
                                    </dd>
                                </div>
                            ) : null}
                            {device.asset_tag ? (
                                <div className="flex min-w-0 flex-col gap-1">
                                    <dt className="text-xs text-muted-foreground">
                                        Asset tag
                                    </dt>
                                    <dd className="text-sm font-medium break-all">
                                        {device.asset_tag}
                                    </dd>
                                </div>
                            ) : null}
                        </dl>
                    ) : null}
                </header>
                {device.notes ? (
                    <p className="text-sm whitespace-pre-wrap">
                        {device.notes}
                    </p>
                ) : null}
                {panel && (
                    <PanelViewer
                        initialPortId={initialPort?.id}
                        panel={panel}
                        assetUrls={assetUrls}
                        onPort={(port, anchor) => {
                            if (!port || !anchor) {
                                setPortOpen(false);
                                return;
                            }
                            portAnchor.current = anchor;
                            portFocus.current = document.getElementById(
                                `port-${port.id}`,
                            );
                            setPort(port);
                            setPortOpen(true);
                        }}
                    />
                )}
                <Tabs
                    value={
                        tab === 'ips' && ips.length === 0 ? 'interfaces' : tab
                    }
                    onValueChange={setTab}
                    className="min-w-0"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <TabsList>
                            <TabsTrigger
                                value="interfaces"
                                className="flex-none px-3"
                            >
                                Porte e interfacce ({interfaceRows.length})
                            </TabsTrigger>
                            {ips.length > 0 && (
                                <TabsTrigger
                                    value="ips"
                                    className="flex-none px-3"
                                >
                                    IP ({ips.length})
                                </TabsTrigger>
                            )}
                        </TabsList>
                        <div className="flex flex-wrap gap-2">
                            <InterfaceForm {...route} ports={ports} />
                            <AddIpForm
                                {...route}
                                device={device}
                                ports={ports}
                                ips={availableIps}
                                subnets={subnets}
                                statuses={statuses}
                                tags={tags}
                            />
                        </div>
                    </div>
                    <TabsContent value="interfaces" className="pt-4">
                        <DeviceTable
                            name="device-interfaces"
                            defaultSort={{ column: 'name', direction: 'asc' }}
                            rows={interfaceRows}
                            columns={[
                                {
                                    id: 'name',
                                    label: 'Porta / interfaccia',
                                    visible: true,
                                },
                                {
                                    id: 'connector',
                                    label: 'Porta fisica',
                                    visible: true,
                                },
                                {
                                    id: 'port_type',
                                    label: 'Tipo porta',
                                    visible: true,
                                },
                                {
                                    id: 'kind',
                                    label: 'Tipo interfaccia',
                                    visible: true,
                                },
                                {
                                    id: 'mac_address',
                                    label: 'MAC interfaccia',
                                    visible: true,
                                },
                                { id: 'ips', label: 'IPv4', visible: true },
                            ]}
                            value={interfaceRowValue}
                            hiddenColumns={
                                new Set(
                                    interfaceRows.map((row) =>
                                        interfaceRowValue(row, 'kind'),
                                    ),
                                ).size > 1
                                    ? []
                                    : ['kind']
                            }
                            onRowClick={openInterfaceRow}
                            isRowClickable={(row) => 'connector_id' in row}
                            render={(row, column) => {
                                if (column !== 'name')
                                    return (
                                        interfaceRowValue(row, column) || '—'
                                    );
                                const item = 'connector_id' in row ? row : null;
                                if (!item)
                                    return (
                                        <span className="font-medium">
                                            {interfaceRowValue(row, 'name')}
                                        </span>
                                    );
                                return (
                                    <div className="flex items-center gap-2">
                                        {item?.mikrotik && (
                                            <MikrotikInterfaceBadge
                                                iconOnly
                                                source={item.mikrotik}
                                                manualMac={item.mac_address}
                                            />
                                        )}
                                        <button
                                            type="button"
                                            id={`interface-${row.id}`}
                                            aria-haspopup="dialog"
                                            onClick={() =>
                                                openInterfaceRow(row)
                                            }
                                            className="rounded-sm text-left font-medium transition-colors hover:text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        >
                                            {interfaceRowValue(row, 'name')}
                                        </button>
                                    </div>
                                );
                            }}
                            empty="Nessuna porta o interfaccia."
                        />
                    </TabsContent>
                    <TabsContent value="ips" className="pt-4">
                        <DeviceTable
                            name="device-ips"
                            rows={ips}
                            columns={[
                                { id: 'ip', label: 'IPv4', visible: true },
                                {
                                    id: 'interface',
                                    label: 'Interfaccia',
                                    visible: true,
                                },
                                {
                                    id: 'hostname',
                                    label: 'Hostname',
                                    visible: true,
                                },
                                {
                                    id: 'mac_address',
                                    label: 'MAC IP',
                                    visible: true,
                                },
                                {
                                    id: 'subnet',
                                    label: 'Subnet / VLAN',
                                    visible: true,
                                },
                            ]}
                            value={(ip, c) =>
                                c === 'interface'
                                    ? interfaceValue(ip.device_interface_id)
                                    : c === 'subnet'
                                      ? `${ip.subnet.cidr} · ${ip.subnet.vlan ? `VLAN ${ip.subnet.vlan.tag}` : 'Senza VLAN'}`
                                      : ip[c]
                            }
                            render={(ip, c) =>
                                c === 'ip' ? (
                                    <TextLink
                                        href={ipUrl(ip)}
                                        className="tabular-nums"
                                    >
                                        {ip.ip}
                                    </TextLink>
                                ) : c === 'interface' ? (
                                    interfaceValue(ip.device_interface_id)
                                ) : c === 'subnet' ? (
                                    `${ip.subnet.cidr} · ${ip.subnet.vlan ? `VLAN ${ip.subnet.vlan.tag}` : 'Senza VLAN'}`
                                ) : (
                                    ip[c] || '—'
                                )
                            }
                            actions={(ip) => (
                                <IconButton
                                    label={`Scollega e libera ${ip.ip}`}
                                    onClick={(event) =>
                                        releaseIp(ip, event.currentTarget)
                                    }
                                >
                                    <Unlink />
                                </IconButton>
                            )}
                            empty="Nessun IP collegato."
                        />
                    </TabsContent>
                </Tabs>
            </div>
            {editingInterface && (
                <InterfaceForm
                    {...route}
                    key={editingInterface.id}
                    item={editingInterface}
                    mikrotikInterfaces={mikrotikInterfaces}
                    ports={ports}
                    linkedIps={interfaceIps}
                    open
                    description={`${device.name} · ${editingInterface.kind === 'logical' ? 'Interfaccia logica' : 'Interfaccia fisica'}`}
                    onOpenChange={(open) => {
                        if (!open) setInterfaceId(null);
                    }}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        returnFocus.current?.focus();
                    }}
                >
                    {interfaceIps.length > 0 && (
                        <>
                            <Separator />
                            <section
                                aria-label="IPv4 collegati"
                                className="flex min-w-0 flex-col gap-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <h3 className="text-sm font-medium">
                                        IPv4 collegati ({interfaceIps.length})
                                    </h3>
                                    <div className="flex flex-wrap gap-2">
                                        <AddIpForm
                                            {...route}
                                            device={device}
                                            ports={ports}
                                            ips={availableIps}
                                            subnets={subnets}
                                            statuses={statuses}
                                            tags={tags}
                                            initialInterface={
                                                editingInterface.id
                                            }
                                        />
                                    </div>
                                </div>
                                <ul className="divide-y rounded-lg border">
                                    {interfaceIps.map((ip) => (
                                        <li
                                            key={ip.id}
                                            className="flex items-center justify-between gap-3 px-3 py-2"
                                        >
                                            <div className="min-w-0">
                                                <TextLink
                                                    href={ipUrl(ip)}
                                                    className="tabular-nums"
                                                >
                                                    {ip.ip}
                                                </TextLink>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {ip.subnet.cidr}
                                                    {ip.hostname
                                                        ? ` · ${ip.hostname}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <IconButton
                                                label={`Scollega e libera ${ip.ip}`}
                                                onClick={(event) =>
                                                    releaseIp(
                                                        ip,
                                                        event.currentTarget,
                                                    )
                                                }
                                            >
                                                <Unlink />
                                            </IconButton>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        </>
                    )}
                </InterfaceForm>
            )}
            <Popover open={portOpen && !!port} onOpenChange={setPortOpen}>
                <PopoverAnchor virtualRef={portAnchor} />
                <PopoverContent
                    side="top"
                    sideOffset={10}
                    collisionPadding={12}
                    className="flex max-h-[min(32rem,var(--radix-popover-content-available-height))] w-96 max-w-[calc(100vw-2rem)] flex-col rounded-xl p-0"
                    aria-labelledby={portTitleId}
                    aria-describedby={undefined}
                    onInteractOutside={(event) => {
                        const target = event.target;
                        // Canvas ports share a DOM target; their click handler updates or closes the popup.
                        if (
                            (target instanceof Node &&
                                portAnchor.current?.contextElement.contains(
                                    target,
                                )) ||
                            (target instanceof Element &&
                                target.closest('[data-device-port]'))
                        )
                            event.preventDefault();
                    }}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        if (!interfaceId && !documentingSubnet && !releasingIp)
                            portFocus.current?.focus({ preventScroll: true });
                    }}
                >
                    <PopoverHeader className="shrink-0 flex-row items-center justify-between gap-3 rounded-t-xl bg-muted/40 px-4 py-3">
                        <PopoverTitle
                            id={portTitleId}
                            className="min-w-0 flex-1 break-words"
                        >
                            {port?.label ||
                                (port
                                    ? connectorTypes[port.type].name
                                    : 'Porta')}
                        </PopoverTitle>
                        <Button
                            type="button"
                            size="icon"
                            className="shrink-0"
                            aria-label="Chiudi porta"
                            variant="ghost"
                            onClick={() => setPortOpen(false)}
                        >
                            <X />
                        </Button>
                    </PopoverHeader>
                    <Separator />
                    <div className="min-h-0 overflow-y-auto overscroll-contain p-4">
                        {port && (
                            <InterfaceDetails
                                company={company.id}
                                site={site.id}
                                item={selected}
                                port={port}
                                ips={ips.filter(
                                    (ip) =>
                                        ip.device_interface_id === selected?.id,
                                )}
                                actions={
                                    selected && (
                                        <>
                                            <IconButton
                                                label="Modifica interfaccia"
                                                className="size-8"
                                                onClick={() => {
                                                    setPortOpen(false);
                                                    editInterface(
                                                        selected.id,
                                                        portFocus.current,
                                                    );
                                                }}
                                            >
                                                <Pencil />
                                            </IconButton>
                                            <NewIpMenu
                                                subnets={subnets}
                                                onSelect={(subnet) => {
                                                    setDocumentingInterfaceId(
                                                        selected.id,
                                                    );
                                                    setDocumentingSubnet(
                                                        subnet,
                                                    );
                                                    setPortOpen(false);
                                                }}
                                            />
                                        </>
                                    )
                                }
                                ipActions={(ip) => (
                                    <IconButton
                                        label={`Scollega e libera ${ip.ip}`}
                                        onClick={(event) =>
                                            releaseIp(ip, event.currentTarget)
                                        }
                                    >
                                        <Unlink />
                                    </IconButton>
                                )}
                            />
                        )}
                    </div>
                    <PopoverArrow />
                </PopoverContent>
            </Popover>
            {releasingIp && (
                <ReleaseIp
                    {...route}
                    ip={releasingIp}
                    onClose={() => setReleasingIp(null)}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        (releaseFocus.current?.isConnected
                            ? releaseFocus.current
                            : portFocus.current
                        )?.focus();
                    }}
                />
            )}
            {documentingSubnet && (
                <IpAddressForm
                    key={documentingSubnet.id}
                    companyId={company.id}
                    siteId={site.id}
                    subnetId={documentingSubnet.id}
                    devices={[device]}
                    excludedRanges={documentingSubnet.excludedRanges}
                    statuses={statuses}
                    tags={tags}
                    assignment={{
                        deviceId: device.id,
                        interfaceId: documentingInterfaceId,
                        label: `${device.name} · ${documentingSubnet.cidr}`,
                    }}
                    open
                    onOpenChange={(open) => {
                        if (!open) setDocumentingSubnet(null);
                    }}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        portFocus.current?.focus();
                    }}
                />
            )}
        </>
    );
}
DeviceShow.layout = ({ company, site, device }: Props) => ({
    breadcrumbs: [
        {
            title: 'Dispositivi',
            href: index({ company: company.id, site: site.id }),
        },
        {
            title: device.name,
            href: show({
                company: company.id,
                site: site.id,
                device: device.id,
            }),
        },
    ],
});
