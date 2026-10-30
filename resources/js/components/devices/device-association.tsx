import { Link } from '@inertiajs/react';
import { useEffect, useId, useState } from 'react';
import { ArrowUpRight } from 'lucide-react';
import {
    InterfaceDetails,
    type InterfaceIp,
} from '@/components/devices/interface-details';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Spinner } from '@/components/ui/spinner';
import {
    Popover,
    PopoverContent,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { show as deviceShow } from '@/routes/companies/sites/devices';
import { show as interfaceShow } from '@/routes/companies/sites/devices/interfaces';
import { interfaceName, type DeviceInterface } from '@/types/device';
import type { Port } from '@/lib/hardware-editor/document';

type Details = {
    interface: DeviceInterface;
    port: Port | null;
    ips: InterfaceIp[];
};
export function DeviceAssociation({
    company,
    site,
    device,
    item,
    label,
}: {
    company: number;
    site: number;
    device?: { id: number; name: string } | null;
    item?: DeviceInterface | null;
    label?: string | null;
}) {
    const [open, setOpen] = useState(false);
    const [details, setDetails] = useState<Details | null>(null);
    const [error, setError] = useState('');
    const title = useId();
    const deviceId = device?.id;
    const interfaceId = item?.id;
    useEffect(() => {
        if (!open || !deviceId || !interfaceId) return;
        const controller = new AbortController();
        setDetails(null);
        setError('');
        fetch(
            interfaceShow.url({
                company,
                site,
                device: deviceId,
                interface: interfaceId,
            }),
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        )
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        'Impossibile caricare la porta. Chiudi e riprova.',
                    );
                const data: Details = await response.json();
                if (!controller.signal.aborted) setDetails(data);
            })
            .catch((error) => {
                if (!controller.signal.aborted) setError(error.message);
            });
        return () => controller.abort();
    }, [open, company, site, deviceId, interfaceId]);
    if (!device) return null;
    const route = { company, site, device: device.id };
    const name =
        label?.slice(device.name.length + 1) ||
        (item ? interfaceName(item) : '');
    return (
        <span
            className="inline-flex flex-wrap items-center justify-center"
            onClick={(event) => event.stopPropagation()}
        >
            <Button variant="ghost" size="sm" asChild>
                <Link href={deviceShow(route)}>{device.name}</Link>
            </Button>
            {item && (
                <>
                    <span className="text-muted-foreground">@</span>
                    <Popover open={open} onOpenChange={setOpen}>
                        <PopoverTrigger asChild>
                            <Button variant="ghost" size="sm">
                                {name}
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent
                            className="max-h-[min(30rem,var(--radix-popover-content-available-height))] w-80 overflow-y-auto"
                            aria-labelledby={title}
                        >
                            <PopoverHeader className="mb-4">
                                <PopoverTitle id={title}>{name}</PopoverTitle>
                            </PopoverHeader>
                            {error ? (
                                <Alert variant="destructive">
                                    <AlertDescription>{error}</AlertDescription>
                                </Alert>
                            ) : details ? (
                                <InterfaceDetails
                                    company={company}
                                    site={site}
                                    item={details.interface}
                                    port={details.port}
                                    ips={details.ips}
                                    actions={
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={deviceShow(route, {
                                                    query: {
                                                        interface: item.id,
                                                    },
                                                })}
                                            >
                                                <ArrowUpRight data-icon="inline-start" />
                                                Apri nel dispositivo
                                            </Link>
                                        </Button>
                                    }
                                />
                            ) : (
                                <Spinner aria-label="Caricamento porta" />
                            )}
                        </PopoverContent>
                    </Popover>
                </>
            )}
        </span>
    );
}
