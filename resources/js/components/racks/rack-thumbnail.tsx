import {
    RACK_UNIT_HEIGHT as U,
    RACK_WIDTH as WIDTH,
} from '@/lib/hardware-editor/document';
import { itemBounds } from '@/lib/rack-layout';
import type { Rack } from '@/types/rack';

export function RackThumbnail({ rack }: { rack: Rack }) {
    const height = rack.height_u * U;
    return (
        <svg
            viewBox={`-24 -16 ${WIDTH + 48} ${height + 32}`}
            className="h-20 w-14 shrink-0"
            role="img"
            aria-label={`Disposizione di ${rack.name}`}
        >
            <rect
                x={-24}
                y={-16}
                width={WIDTH + 48}
                height={height + 32}
                rx={12}
                className="fill-muted stroke-muted-foreground"
                strokeWidth={4}
            />
            <rect width={WIDTH} height={height} className="fill-background" />
            {Array.from({ length: rack.height_u }, (_, row) => (
                <line
                    key={row}
                    x1={0}
                    x2={WIDTH}
                    y1={row * U}
                    y2={row * U}
                    className="stroke-border"
                    strokeWidth={3}
                />
            ))}
            {rack.items.map((item) => {
                const bounds = itemBounds(item, rack.items);
                const x = (item.x / 100) * WIDTH;
                const width = (item.width / 100) * WIDTH;
                const bottom = (rack.height_u - bounds.bottom) * U;
                return item.kind === 'shelf' ? (
                    <path
                        key={item.key}
                        d={`M ${x} ${bottom - 12} V ${bottom} H ${x + width} V ${bottom - 12}`}
                        fill="none"
                        className="stroke-foreground"
                        strokeWidth={6}
                    />
                ) : (
                    <rect
                        key={item.key}
                        x={x}
                        y={(rack.height_u - bounds.top) * U}
                        width={width}
                        height={item.height * U}
                        rx={4}
                        className="fill-muted-foreground stroke-background"
                        strokeWidth={4}
                    />
                );
            })}
        </svg>
    );
}
