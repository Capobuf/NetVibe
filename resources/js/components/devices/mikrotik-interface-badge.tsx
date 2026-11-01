import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { MikrotikMark } from '@/components/mikrotik-mark';
import type { MikrotikInterface } from '@/types/device';

export function MikrotikInterfaceBadge({
    source,
    manualMac,
}: {
    source: MikrotikInterface;
    manualMac?: string | null;
}) {
    const different =
        manualMac &&
        source.mac_address &&
        manualMac.replaceAll('-', ':').toUpperCase() !==
            source.mac_address.toUpperCase();
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    tabIndex={0}
                    className="inline-flex rounded-md focus-visible:outline-2 focus-visible:outline-ring"
                >
                    <Badge variant="outline">
                        <MikrotikMark />
                        {source.problem
                            ? 'Da verificare'
                            : source.stale
                              ? 'Non aggiornato'
                              : different
                                ? 'MAC diverso'
                                : 'MikroTik'}
                    </Badge>
                </span>
            </TooltipTrigger>
            <TooltipContent className="max-w-80">
                <div className="flex flex-col gap-1">
                    <p>
                        {source.name} · {source.default_name}
                    </p>
                    <p>MAC MikroTik: {source.mac_address ?? '—'}</p>
                    <p>
                        Ultima lettura:{' '}
                        {new Date(source.seen_at).toLocaleString('it-IT')}
                    </p>
                    {source.problem && <p>{source.problem}</p>}
                </div>
            </TooltipContent>
        </Tooltip>
    );
}
