import type { ReactNode } from 'react';
import { Zap } from 'lucide-react';
import { CopyValue } from '@/components/copy-value';
import TextLink from '@/components/text-link';
import { connectorTypes, type Port } from '@/lib/hardware-editor/document';
import { show as subnetShow } from '@/routes/companies/sites/subnets';
import type { DeviceInterface } from '@/types/device';

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
    return (
        <div className="flex flex-col gap-4">
            <dl className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-4 gap-y-2 text-sm">
                <dt className="text-muted-foreground">Tipo</dt>
                <dd>
                    {port
                        ? connectorTypes[port.type].name
                        : item?.kind === 'logical'
                          ? 'Logica'
                          : 'Fisica'}
                </dd>
                {port?.poe && (
                    <>
                        <dt className="text-muted-foreground">Capacità</dt>
                        <dd className="flex items-center gap-1">
                            <Zap className="size-4" />
                            PoE
                        </dd>
                    </>
                )}
                {item && (
                    <>
                        <dt className="text-muted-foreground">Nome / alias</dt>
                        <dd>
                            {item.name ? <CopyValue value={item.name} /> : '—'}
                        </dd>
                        <dt className="text-muted-foreground">MAC</dt>
                        <dd>
                            {item.mac_address ? (
                                <CopyValue
                                    value={item.mac_address}
                                    className="font-mono"
                                />
                            ) : (
                                '—'
                            )}
                        </dd>
                    </>
                )}
            </dl>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
            {item && (
                <div className="flex flex-col gap-2">
                    <h3 className="text-xs font-medium text-muted-foreground">
                        IPv4 collegati ({ips.length})
                    </h3>
                    {ips.map((ip) => (
                        <div
                            key={ip.id}
                            className="flex items-center justify-between gap-2 rounded-lg border p-3"
                        >
                            <div className="min-w-0">
                                <TextLink
                                    href={subnetShow(
                                        { company, site, subnet: ip.subnet.id },
                                        { query: { ip_address: ip.id } },
                                    )}
                                    className="font-mono"
                                >
                                    {ip.ip}
                                </TextLink>
                                <p className="truncate text-xs text-muted-foreground">
                                    {ip.subnet.cidr}
                                    {ip.hostname ? ` · ${ip.hostname}` : ''}
                                </p>
                            </div>
                            {ipActions?.(ip)}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
