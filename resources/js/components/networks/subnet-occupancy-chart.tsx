import { useSyncExternalStore } from 'react';
import { Label, Pie, PieChart } from 'recharts';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';

const categories = {
    documented: { label: 'Documentati', color: 'var(--chart-2)' },
    excluded: { label: 'Riservati', color: 'var(--chart-5)' },
    external: { label: 'Fonti esterne', color: 'var(--chart-3)' },
    proposable: { label: 'Proponibili', color: 'var(--muted-foreground)' },
} satisfies ChartConfig;

function subscribeMotionPreference(onChange: () => void) {
    const media = window.matchMedia('(prefers-reduced-motion: reduce)');
    media.addEventListener('change', onChange);
    return () => media.removeEventListener('change', onChange);
}

export default function SubnetOccupancyChart({
    counts,
}: {
    counts: Record<keyof typeof categories, number>;
}) {
    const reducedMotion = useSyncExternalStore(
        subscribeMotionPreference,
        () => window.matchMedia('(prefers-reduced-motion: reduce)').matches,
        () => true,
    );
    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);
    const entries = (
        Object.keys(categories) as Array<keyof typeof categories>
    ).map((key) => ({
        key,
        ...categories[key],
        count: counts[key],
        fill: `var(--color-${key})`,
    }));

    return (
        <figure className="flex min-w-0 flex-col gap-2">
            <figcaption className="text-sm font-medium">Occupazione</figcaption>
            <div className="flex flex-wrap items-center gap-x-5 gap-y-3">
                <ChartContainer
                    config={categories}
                    className="aspect-square size-36 shrink-0"
                    aria-label={`${total.toLocaleString('it-IT')} IP utilizzabili`}
                >
                    <PieChart accessibilityLayer>
                        <ChartTooltip
                            cursor={false}
                            content={<ChartTooltipContent hideLabel />}
                        />
                        <Pie
                            data={entries.filter((entry) => entry.count > 0)}
                            dataKey="count"
                            nameKey="key"
                            innerRadius={48}
                            outerRadius={66}
                            strokeWidth={2}
                            startAngle={90}
                            endAngle={-270}
                            isAnimationActive={!reducedMotion}
                            animationDuration={600}
                            animationEasing="ease-out"
                        >
                            <Label
                                content={({ viewBox }) => {
                                    if (
                                        !viewBox ||
                                        !('cx' in viewBox) ||
                                        !('cy' in viewBox)
                                    )
                                        return null;
                                    return (
                                        <text
                                            x={viewBox.cx}
                                            y={viewBox.cy}
                                            textAnchor="middle"
                                            dominantBaseline="middle"
                                        >
                                            <tspan
                                                x={viewBox.cx}
                                                className="fill-foreground text-xl font-semibold tabular-nums"
                                            >
                                                {total.toLocaleString('it-IT')}
                                            </tspan>
                                            <tspan
                                                x={viewBox.cx}
                                                y={(viewBox.cy ?? 0) + 20}
                                                className="fill-muted-foreground text-[10px]"
                                            >
                                                IP utilizzabili
                                            </tspan>
                                        </text>
                                    );
                                }}
                            />
                        </Pie>
                    </PieChart>
                </ChartContainer>
                <dl className="flex min-w-40 flex-1 flex-col gap-2.5 text-sm">
                    {entries.map(({ key, label, color, count }) => (
                        <div
                            key={key}
                            className="flex items-center justify-between gap-4"
                        >
                            <dt className="flex items-center gap-2 text-muted-foreground">
                                <span
                                    className="size-2 shrink-0 rounded-full"
                                    style={{ backgroundColor: color }}
                                    aria-hidden="true"
                                />
                                {label}
                            </dt>
                            <dd className="font-medium tabular-nums">
                                {count.toLocaleString('it-IT')}
                            </dd>
                        </div>
                    ))}
                </dl>
            </div>
        </figure>
    );
}
