import { Head, Link, router, usePage } from '@inertiajs/react';
import { useId, useRef, useState } from 'react';
import {
    ArrowLeft,
    Trash2,
    Unlink,
    Network,
    Zap,
    Pencil,
    X,
} from 'lucide-react';
import { DeviceForm } from '@/components/devices/device-form';
import { DeviceTable } from '@/components/devices/device-table';
import {
    PanelViewer,
    type PortAnchor,
} from '@/components/devices/panel-viewer';
import { InterfaceForm } from '@/components/devices/interface-form';
import {
    LinkIpForm,
    type AvailableIp,
} from '@/components/devices/link-ip-form';
import IpAddressForm from '@/components/networks/ip-address-form';
import { Separator } from '@/components/ui/separator';
import { Empty, EmptyHeader, EmptyDescription } from '@/components/ui/empty';
import { GuidedDeletion } from '@/components/networks/guided-deletion';
import { Button } from '@/components/ui/button';
import { IconButton } from '@/components/icon-button';
import TextLink from '@/components/text-link';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Popover,
    PopoverContent,
    PopoverAnchor,
    PopoverArrow,
    PopoverHeader,
    PopoverTitle,
} from '@/components/ui/popover';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    index,
    show,
    destroy,
    deletionImpact,
} from '@/routes/companies/sites/devices';
import { destroy as unlink } from '@/routes/companies/sites/devices/ip-addresses';
import { show as subnetShow } from '@/routes/companies/sites/subnets';
import { connectorTypes, type Port } from '@/lib/hardware-editor/document';
import { interfaceName, type Device, type Manufacturer } from '@/types/device';
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
type Props = {
    company: Company;
    site: Site;
    device: Device;
    manufacturers: Manufacturer[];
    assetUrls: Record<string, string>;
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
    manufacturers,
    assetUrls,
    ips,
    availableIps,
    subnets,
    statuses,
    tags,
}: Props) {
    const { companyNavigation } = usePage().props;
    const [deleting, setDeleting] = useState(false);
    const [port, setPort] = useState<Port | null>(null);
    const [portOpen, setPortOpen] = useState(false);
    const portAnchor = useRef<PortAnchor | null>(null);
    const portFocus = useRef<HTMLElement | null>(null);
    const portTitleId = useId();
    const [interfaceId, setInterfaceId] = useState<number | null>(null);
    const [documentingSubnet, setDocumentingSubnet] = useState<
        Props['subnets'][number] | null
    >(null);
    const returnFocus = useRef<HTMLElement | null>(null);
    const documentIpTrigger = useRef<HTMLButtonElement>(null);
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
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-1">
                        <p className="text-sm text-muted-foreground">
                            {context}
                        </p>
                        <h1 className="text-3xl font-semibold tracking-tight break-words">
                            {device.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {[
                                device.manufacturer.name,
                                device.model?.name,
                                device.model?.model_type?.name,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                        {device.serial_number && (
                            <p className="text-sm text-muted-foreground">
                                Seriale: {device.serial_number}
                            </p>
                        )}
                    </div>
                    <div className="flex gap-2">
                        <DeviceForm
                            company={company.id}
                            site={site.id}
                            manufacturers={manufacturers}
                            device={device}
                        />
                        <Dialog open={deleting} onOpenChange={setDeleting}>
                            <DialogTrigger asChild>
                                <IconButton
                                    label="Elimina dispositivo"
                                    variant="outline"
                                >
                                    <Trash2 />
                                </IconButton>
                            </DialogTrigger>
                            <DialogContent
                                className="max-h-[90dvh] overflow-y-auto"
                                aria-describedby={undefined}
                            >
                                <DialogHeader>
                                    <DialogTitle>
                                        Elimina {device.name}
                                    </DialogTitle>
                                </DialogHeader>
                                <GuidedDeletion
                                    kind="device"
                                    previewUrl={deletionImpact.url(route)}
                                    action={destroy.form(route)}
                                    onCancel={() => setDeleting(false)}
                                    onSuccess={() => setDeleting(false)}
                                />
                            </DialogContent>
                        </Dialog>
                    </div>
                </header>
                {panel && (
                    <PanelViewer
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
                <Tabs defaultValue="interfaces" className="min-w-0">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <TabsList>
                            <TabsTrigger value="interfaces">
                                Interfacce ({device.interfaces.length})
                            </TabsTrigger>
                            <TabsTrigger value="ips">
                                IP ({ips.length})
                            </TabsTrigger>
                        </TabsList>
                        <div className="flex flex-wrap gap-2">
                            <InterfaceForm {...route} ports={ports} />
                            <LinkIpForm
                                {...route}
                                interfaces={device.interfaces}
                                ports={ports}
                                ips={availableIps}
                            />
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline" size="sm">
                                        <Network data-icon="inline-start" />
                                        Documenta nuovo IP
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent>
                                    <DropdownMenuGroup>
                                        {subnets.map((subnet) => (
                                            <DropdownMenuItem
                                                key={subnet.id}
                                                asChild
                                            >
                                                <Link
                                                    href={subnetShow({
                                                        company: company.id,
                                                        site: site.id,
                                                        subnet: subnet.id,
                                                    })}
                                                >
                                                    {subnet.cidr}
                                                    {subnet.name
                                                        ? ` · ${subnet.name}`
                                                        : ''}
                                                </Link>
                                            </DropdownMenuItem>
                                        ))}
                                        {!subnets.length && (
                                            <DropdownMenuItem disabled>
                                                Nessuna subnet nella sede
                                            </DropdownMenuItem>
                                        )}
                                    </DropdownMenuGroup>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                    <TabsContent value="interfaces" className="pt-4">
                        <DeviceTable
                            name="device-interfaces"
                            rows={device.interfaces}
                            columns={[
                                {
                                    id: 'name',
                                    label: 'Interfaccia',
                                    visible: true,
                                },
                                {
                                    id: 'connector',
                                    label: 'Porta modello',
                                    visible: true,
                                },
                                { id: 'kind', label: 'Tipo', visible: true },
                                {
                                    id: 'mac_address',
                                    label: 'MAC interfaccia',
                                    visible: true,
                                },
                                { id: 'ips', label: 'IPv4', visible: true },
                            ]}
                            value={(i, c) =>
                                c === 'name'
                                    ? interfaceName(i, ports)
                                    : c === 'connector'
                                      ? (ports.find(
                                            (p) => p.id === i.connector_id,
                                        )?.label ?? '—')
                                      : c === 'kind'
                                        ? i.kind === 'logical'
                                            ? 'Logica'
                                            : 'Fisica'
                                        : c === 'ips'
                                          ? ips
                                                .filter(
                                                    (ip) =>
                                                        ip.device_interface_id ===
                                                        i.id,
                                                )
                                                .map((ip) => ip.ip)
                                                .join(', ')
                                          : i.mac_address
                            }
                            onRowClick={(item) => editInterface(item.id)}
                            render={(item, column) =>
                                column === 'name' ? (
                                    <button
                                        type="button"
                                        id={`interface-${item.id}`}
                                        aria-haspopup="dialog"
                                        onClick={() => editInterface(item.id)}
                                        className="rounded-sm text-left font-medium transition-colors hover:text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        {interfaceName(item, ports)}
                                    </button>
                                ) : column === 'connector' ? (
                                    ports.find(
                                        (port) => port.id === item.connector_id,
                                    )?.label || '—'
                                ) : column === 'kind' ? (
                                    item.kind === 'logical' ? (
                                        'Logica'
                                    ) : (
                                        'Fisica'
                                    )
                                ) : column === 'ips' ? (
                                    ips
                                        .filter(
                                            (ip) =>
                                                ip.device_interface_id ===
                                                item.id,
                                        )
                                        .map((ip) => ip.ip)
                                        .join(', ') || '—'
                                ) : (
                                    item.mac_address || '—'
                                )
                            }
                            empty="Nessuna interfaccia. Aggiungi un’interfaccia fisica o logica."
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
                                        className="font-mono"
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
                                    label={`Scollega ${ip.ip}`}
                                    onClick={() =>
                                        router.delete(
                                            unlink.url({
                                                ...route,
                                                ipAddress: ip.id,
                                            }),
                                            { preserveScroll: true },
                                        )
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
                    ports={ports}
                    linkedIps={interfaceIps.map((ip) => ip.ip)}
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
                    <Separator />
                    <section
                        aria-label="IPv4 collegati"
                        className="flex min-w-0 flex-col gap-4"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h3 className="text-sm font-semibold">
                                IPv4 collegati ({interfaceIps.length})
                            </h3>
                            <div className="flex flex-wrap gap-2">
                                <LinkIpForm
                                    {...route}
                                    interfaces={device.interfaces}
                                    ports={ports}
                                    ips={availableIps}
                                    initialInterface={editingInterface.id}
                                />
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            ref={documentIpTrigger}
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Network data-icon="inline-start" />
                                            Documenta nuovo IP
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        onCloseAutoFocus={(event) => {
                                            if (documentingSubnet)
                                                event.preventDefault();
                                        }}
                                    >
                                        <DropdownMenuGroup>
                                            {subnets.map((subnet) => (
                                                <DropdownMenuItem
                                                    key={subnet.id}
                                                    onSelect={() =>
                                                        setDocumentingSubnet(
                                                            subnet,
                                                        )
                                                    }
                                                >
                                                    {subnet.cidr}
                                                    {subnet.name
                                                        ? ` · ${subnet.name}`
                                                        : ''}
                                                </DropdownMenuItem>
                                            ))}
                                            {!subnets.length && (
                                                <DropdownMenuItem disabled>
                                                    Nessuna subnet nella sede
                                                </DropdownMenuItem>
                                            )}
                                        </DropdownMenuGroup>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                        {interfaceIps.length ? (
                            <ul className="divide-y rounded-lg border">
                                {interfaceIps.map((ip) => (
                                    <li
                                        key={ip.id}
                                        className="flex items-center justify-between gap-3 px-3 py-2"
                                    >
                                        <div className="min-w-0">
                                            <TextLink
                                                href={ipUrl(ip)}
                                                className="font-mono"
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
                                            label={`Scollega ${ip.ip}`}
                                            variant="ghost"
                                            onClick={() =>
                                                router.delete(
                                                    unlink.url({
                                                        ...route,
                                                        ipAddress: ip.id,
                                                    }),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Unlink />
                                        </IconButton>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <Empty className="rounded-lg border py-5">
                                <EmptyHeader>
                                    <EmptyDescription>
                                        Nessun IP collegato
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        )}
                    </section>
                </InterfaceForm>
            )}
            <Popover open={portOpen && !!port} onOpenChange={setPortOpen}>
                <PopoverAnchor virtualRef={portAnchor} />
                <PopoverContent
                    side="top"
                    sideOffset={10}
                    collisionPadding={12}
                    className="w-80 max-w-[calc(100vw-2rem)] p-0"
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
                        if (!interfaceId)
                            portFocus.current?.focus({ preventScroll: true });
                    }}
                >
                    <div className="max-h-[min(24rem,var(--radix-popover-content-available-height))] overflow-y-auto p-4">
                        <PopoverHeader className="mb-3 flex-row items-start justify-between gap-3">
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
                                aria-label="Chiudi porta"
                                variant="ghost"
                                onClick={() => setPortOpen(false)}
                            >
                                <X />
                            </Button>
                        </PopoverHeader>
                        {port && (
                            <div className="flex flex-col gap-4">
                                <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
                                    <dt className="text-muted-foreground">
                                        Connettore
                                    </dt>
                                    <dd>{connectorTypes[port.type].name}</dd>
                                    {port.poe && (
                                        <>
                                            <dt className="text-muted-foreground">
                                                Capacità
                                            </dt>
                                            <dd className="flex items-center gap-1">
                                                <Zap className="size-4" />
                                                PoE
                                            </dd>
                                        </>
                                    )}
                                    {selected && (
                                        <>
                                            <dt className="text-muted-foreground">
                                                Alias locale
                                            </dt>
                                            <dd className="break-words">
                                                {selected.name || '—'}
                                            </dd>
                                            <dt className="text-muted-foreground">
                                                MAC interfaccia
                                            </dt>
                                            <dd className="font-mono break-all">
                                                {selected.mac_address || '—'}
                                            </dd>
                                        </>
                                    )}
                                </dl>
                                {selected && (
                                    <>
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => {
                                                    setPortOpen(false);
                                                    editInterface(
                                                        selected.id,
                                                        portFocus.current,
                                                    );
                                                }}
                                            >
                                                <Pencil data-icon="inline-start" />
                                                Modifica interfaccia
                                            </Button>
                                        </div>
                                        <div className="flex flex-col gap-3">
                                            {ips
                                                .filter(
                                                    (ip) =>
                                                        ip.device_interface_id ===
                                                        selected.id,
                                                )
                                                .map((ip) => (
                                                    <div
                                                        key={ip.id}
                                                        className="flex items-center justify-between gap-2 rounded-lg border p-3"
                                                    >
                                                        <div>
                                                            <TextLink
                                                                href={ipUrl(ip)}
                                                                className="font-mono"
                                                            >
                                                                {ip.ip}
                                                            </TextLink>
                                                            <p className="text-xs text-muted-foreground">
                                                                {ip.subnet.cidr}
                                                                {ip.hostname
                                                                    ? ` · ${ip.hostname}`
                                                                    : ''}
                                                            </p>
                                                        </div>
                                                        <IconButton
                                                            label={`Scollega ${ip.ip}`}
                                                            onClick={() =>
                                                                router.delete(
                                                                    unlink.url({
                                                                        ...route,
                                                                        ipAddress:
                                                                            ip.id,
                                                                    }),
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <Unlink />
                                                        </IconButton>
                                                    </div>
                                                ))}
                                        </div>
                                    </>
                                )}
                            </div>
                        )}
                    </div>
                    <PopoverArrow />
                </PopoverContent>
            </Popover>
            {documentingSubnet && editingInterface && (
                <IpAddressForm
                    key={documentingSubnet.id}
                    companyId={company.id}
                    siteId={site.id}
                    subnetId={documentingSubnet.id}
                    excludedRanges={documentingSubnet.excludedRanges}
                    statuses={statuses}
                    tags={tags}
                    assignment={{
                        deviceId: device.id,
                        interfaceId: editingInterface.id,
                        label: `${device.name} · ${interfaceName(editingInterface, ports)} · ${documentingSubnet.cidr}`,
                    }}
                    open
                    onOpenChange={(open) => {
                        if (!open) setDocumentingSubnet(null);
                    }}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        documentIpTrigger.current?.focus();
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
