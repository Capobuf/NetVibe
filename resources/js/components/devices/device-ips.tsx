import { CopyValue } from '@/components/copy-value';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/** First documented IP, with the others behind a "+N" popover. */
export function DeviceIps({
    ips,
    name,
    className,
}: {
    ips: string[];
    name: string;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex items-center gap-2 whitespace-nowrap tabular-nums',
                className,
            )}
        >
            <CopyValue
                value={ips[0]}
                className="-ml-2 whitespace-nowrap tabular-nums"
            />
            {ips.length > 1 ? (
                <Popover>
                    <PopoverTrigger asChild>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            aria-label={`Mostra tutti i ${ips.length} IP documentati di ${name}`}
                        >
                            +{ips.length - 1}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent
                        align="end"
                        className="flex flex-col gap-3"
                        aria-label={`IP documentati di ${name}`}
                    >
                        <PopoverHeader>
                            <PopoverTitle>
                                IP documentati ({ips.length})
                            </PopoverTitle>
                        </PopoverHeader>
                        <ul className="max-h-64 overflow-y-auto">
                            {ips.map((ip) => (
                                <li key={ip}>
                                    <CopyValue
                                        value={ip}
                                        className="w-full tabular-nums"
                                    />
                                </li>
                            ))}
                        </ul>
                    </PopoverContent>
                </Popover>
            ) : null}
        </div>
    );
}
