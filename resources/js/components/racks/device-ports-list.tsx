import { ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    cableCode,
    devicePorts,
    endKey,
    endLabel,
    type Cable,
    type CableEnd,
} from '@/lib/rack-cabling';
import type { RackDevice } from '@/types/rack';

export function DevicePortsList({
    device,
    devices,
    cables,
    disabled,
    onSelect,
}: {
    device: RackDevice;
    devices: RackDevice[];
    cables: Cable[];
    disabled: boolean;
    onSelect: (end: CableEnd) => void;
}) {
    const ports = devicePorts(device);
    return (
        <Collapsible className="group/ports overflow-hidden rounded-lg border">
            <CollapsibleTrigger asChild>
                <Button
                    variant="ghost"
                    className="h-auto min-h-10 w-full justify-start rounded-none py-2 text-left"
                    disabled={disabled}
                >
                    <ChevronRight className="size-4 transition-transform duration-150 group-data-[state=open]/ports:rotate-90 motion-reduce:transition-none" />
                    <span className="min-w-0 flex-1 truncate">
                        {device.name}
                    </span>
                    <Badge variant="secondary">{ports.length}</Badge>
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <ul className="divide-y border-t">
                    {ports.map((port) => {
                        const end = {
                            device_id: device.id,
                            connector_id: port.id,
                        };
                        const cable = cables.find((c) =>
                            [c.a, c.b].some((e) => endKey(e) === endKey(end)),
                        );
                        return (
                            <li key={port.id}>
                                <Button
                                    variant="ghost"
                                    className="h-auto min-h-10 w-full justify-start rounded-none px-3 py-2.5 text-left whitespace-normal"
                                    disabled={disabled}
                                    onClick={() => onSelect(end)}
                                >
                                    <span className="min-w-0 break-words">
                                        {port.label || port.id.slice(0, 8)}
                                        <span className="block text-xs text-muted-foreground">
                                            {port.face === 'front'
                                                ? 'Fronte'
                                                : 'Retro'}{' '}
                                            ·{' '}
                                            {cable
                                                ? `${cableCode(cable)} → ${endLabel(endKey(cable.a) === endKey(end) ? cable.b : cable.a, devices, '@')}`
                                                : 'Non collegata'}
                                        </span>
                                    </span>
                                </Button>
                            </li>
                        );
                    })}
                </ul>
                {!ports.length && (
                    <p className="py-4 text-center text-sm text-muted-foreground">
                        Nessuna porta
                    </p>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}
