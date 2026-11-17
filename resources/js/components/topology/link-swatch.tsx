import { cn } from '@/lib/utils';
import type { TopologyEdge } from '@/types/topology';

/** Line sample of a link kind: solid when documented, dashed patterns for what integrations report. */
export function LinkSwatch({
    kind,
    className,
}: {
    kind: TopologyEdge['kind'];
    className?: string;
}) {
    return (
        <svg
            aria-hidden
            className={cn(
                'h-2 w-6',
                kind === 'documented' && 'text-primary',
                className,
            )}
            viewBox="0 0 24 8"
        >
            <line
                x1="0"
                y1="4"
                x2="24"
                y2="4"
                stroke="currentColor"
                strokeWidth="2"
                strokeDasharray={
                    kind === 'discovered'
                        ? '10 6'
                        : kind === 'partial'
                          ? '2 6'
                          : kind === 'logical'
                            ? '12 4 2 4'
                            : undefined
                }
            />
        </svg>
    );
}
