import type { ReactNode } from 'react';
import { Zap } from 'lucide-react';
import { CopyValue } from '@/components/copy-value';
import TextLink from '@/components/text-link';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { connectorTypes, type Port } from '@/lib/hardware-editor/document';
import { show as subnetShow } from '@/routes/companies/sites/subnets';
import { interfaceMac, type DeviceInterface } from '@/types/device';
import { MikrotikInterfaceBadge } from '@/components/devices/mikrotik-interface-badge';

export type InterfaceIp = {
    id: number;
    ip: string;
    hostname: string | null;
    subnet: { id: number; cidr: string };
};

export function InterfaceDetails({
    company,
    site,
    item,
    port,
    ips,
    actions,
    ipActions,
}: {
    company: number;
    site: number;
    item?: DeviceInterface | null;
    port?: Port | null;
    ips: InterfaceIp[];
    actions?: ReactNode;
    ipActions?: (ip: InterfaceIp) => ReactNode;
}) {
    const name =
        item?.name || (!item?.mikrotik?.problem && item?.mikrotik?.name);
    const mac = item ? interfaceMac(item) : null;
    return (
        <div className="flex flex-col gap-4">
            {item?.mikrotik && (
                <div>
                    <MikrotikInterfaceBadge
                        source={item.mikrotik}
                        manualMac={item.mac_address}
                    />
                </div>
            )}
            <dl className="grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-6 gap-y-3 text-sm">
                <dt className="text-muted-foreground">Tipo</dt>
                <dd className="min-w-0 font-medium break-words">
                    {port
                        ? connectorTypes[port.type].name
                        : item?.kind === 'logical'
                          ? 'Logica'
                          : 'Fisica'}
                </dd>
                {port?.poe && (
                    <>
                        <dt className="text-muted-foreground">Capacità</dt>
                        <dd>
                            <Badge variant="secondary">
                                <Zap aria-hidden="true" />
                                PoE
                            </Badge>
                        </dd>
                    </>
                )}
                {item && (
                    <>
                        <dt className="text-muted-foreground">Nome / alias</dt>
                        <dd className="min-w-0">
                            {name ? (
                                <CopyValue
                                    value={name}
                                    className="-mx-2 -my-1.5"
                                />
                            ) : (
                                <span className="text-muted-foreground">—</span>
                            )}
                        </dd>
                        <dt className="text-muted-foreground">MAC</dt>
                        <dd className="min-w-0">
                            {mac ? (
                                <CopyValue
                                    value={mac}
                                    className="-mx-2 -my-1.5 tabular-nums"
                                />
                            ) : (
                                <span className="text-muted-foreground">—</span>
                            )}
                        </dd>
                    </>
                )}
            </dl>
            {item && ips.length > 0 && (
                <>
                    <Separator />
                    <section
                        aria-label="IPv4 collegati"
                        className="flex flex-col gap-3"
                    >
                        <h3 className="flex items-center justify-between gap-2 text-sm font-medium">
                            IPv4 collegati
                            <Badge variant="secondary" className="tabular-nums">
                                {ips.length}
                            </Badge>
                        </h3>
                        <ul className="divide-y rounded-lg border">
                            {ips.map((ip) => (
                                <li
                                    key={ip.id}
                                    className="flex items-center justify-between gap-3 p-3"
                                >
                                    <div className="flex min-w-0 flex-col gap-1">
                                        <TextLink
                                            href={subnetShow(
                                                {
                                                    company,
                                                    site,
                                                    subnet: ip.subnet.id,
                                                },
                                                {
                                                    query: {
                                                        ip_address: ip.id,
                                                    },
                                                },
                                            )}
                                            className="tabular-nums"
                                        >
                                            {ip.ip}
                                        </TextLink>
                                        <p className="text-xs text-muted-foreground tabular-nums">
                                            {ip.subnet.cidr}
                                        </p>
                                        {ip.hostname && (
                                            <p className="text-xs break-words text-muted-foreground">
                                                {ip.hostname}
                                            </p>
                                        )}
                                    </div>
                                    {ipActions && (
                                        <div className="shrink-0">
                                            {ipActions(ip)}
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                </>
            )}
            {actions && (
                <>
                    <Separator />
                    <div className="flex flex-wrap items-center gap-2">
                        {actions}
                    </div>
                </>
            )}
        </div>
    );
}
