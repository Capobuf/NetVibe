import { formatDateTime } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { MikrotikMark } from '@/components/mikrotik-mark';
import { mikrotikInterfaceName, type MikrotikInterface } from '@/types/device';

export function MikrotikInterfaceBadge({
    source,
    manualMac,
    iconOnly = false,
}: {
    source: MikrotikInterface;
    manualMac?: string | null;
    iconOnly?: boolean;
}) {
    const different =
        manualMac &&
        source.mac_address &&
        manualMac.replaceAll('-', ':').toUpperCase() !==
            source.mac_address.toUpperCase();
    const label = source.problem
        ? 'Da verificare'
        : source.stale
          ? 'Non aggiornato'
          : different
            ? 'MAC diverso'
            : 'MikroTik';
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    tabIndex={0}
                    aria-label={
                        label === 'MikroTik' ? label : `MikroTik: ${label}`
                    }
                    className="inline-flex rounded-md focus-visible:outline-2 focus-visible:outline-ring"
                >
                    {iconOnly ? (
                        <MikrotikMark />
                    ) : (
                        <Badge variant="outline">
                            <MikrotikMark />
                            {label}
                        </Badge>
                    )}
                </span>
            </TooltipTrigger>
            <TooltipContent className="max-w-80">
                <div className="flex flex-col gap-1">
                    <p>{label === 'MikroTik' ? label : `MikroTik: ${label}`}</p>
                    <p>{mikrotikInterfaceName(source)}</p>
                    <p>MAC MikroTik: {source.mac_address ?? '—'}</p>
                    <p>Ultima lettura: {formatDateTime(source.seen_at)}</p>
                    {source.problem && <p>{source.problem}</p>}
                </div>
            </TooltipContent>
        </Tooltip>
    );
}
