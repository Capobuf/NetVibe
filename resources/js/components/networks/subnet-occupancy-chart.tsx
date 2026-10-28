import { useSyncExternalStore } from 'react';
import { Pie, PieChart } from 'recharts';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';

const subnetChartConfig = {
    documented: { label: 'Documentati', color: 'var(--chart-2)' },
    excluded: { label: 'Riservati', color: 'var(--chart-5)' },
    proposable: { label: 'Liberi', color: 'var(--muted-foreground)' },
} satisfies ChartConfig;

const reducedMotion =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia('(prefers-reduced-motion: reduce)');

function subscribeToMotionPreference(callback: () => void) {
    reducedMotion?.addEventListener('change', callback);
    return () => reducedMotion?.removeEventListener('change', callback);
}

export default function SubnetOccupancyChart({
    counts,
}: {
    counts: Record<keyof typeof subnetChartConfig, number>;
}) {
    const prefersReducedMotion = useSyncExternalStore(
        subscribeToMotionPreference,
        () => reducedMotion?.matches ?? true,
        () => true,
    );
    const total = counts.documented + counts.excluded + counts.proposable;
    const data = (
        Object.keys(subnetChartConfig) as Array<keyof typeof subnetChartConfig>
    ).map((key) => ({
        category: key,
        count: counts[key],
        fill: `var(--color-${key})`,
    }));

    return (
        <figure className="mx-auto flex max-w-xl flex-col items-center gap-6 lg:flex-row lg:gap-10">
            <div className="relative w-56 shrink-0">
                <ChartContainer
                    config={subnetChartConfig}
                    initialDimension={{ width: 224, height: 224 }}
                    className="aspect-square w-full rounded-full focus-within:ring-2 focus-within:ring-ring [&_.recharts-sector]:transition-opacity [&_.recharts-sector:hover]:opacity-75"
                >
                    <PieChart
                        accessibilityLayer
                        aria-label={`Occupazione subnet: ${counts.documented.toLocaleString('it-IT')} IP documentati su ${total.toLocaleString('it-IT')} utilizzabili`}
                    >
                        <ChartTooltip
                            cursor={false}
                            content={
                                <ChartTooltipContent
                                    hideLabel
                                    nameKey="category"
                                />
                            }
                        />
                        <Pie
                            data={data}
                            dataKey="count"
                            nameKey="category"
                            innerRadius={78}
                            outerRadius={100}
                            stroke="var(--card)"
                            strokeWidth={3}
                            startAngle={90}
                            endAngle={-270}
                            isAnimationActive={!prefersReducedMotion}
                            animationDuration={700}
                            animationEasing="ease-out"
                        />
                    </PieChart>
                </ChartContainer>
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-1"
                >
                    <span className="text-xl font-semibold tracking-tight tabular-nums">
                        {total.toLocaleString('it-IT')}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        IP utilizzabili
                    </span>
                </div>
            </div>
            <figcaption className="flex w-full min-w-0 flex-1 flex-col gap-4">
                <h2 className="text-sm font-medium">Occupazione subnet</h2>
                <ul className="flex flex-col gap-3">
                    {data.map(({ category, count }) => (
                        <li
                            key={category}
                            className="flex items-center justify-between gap-4 text-sm"
                        >
                            <span className="flex min-w-0 items-center gap-2 text-muted-foreground">
                                <span
                                    aria-hidden="true"
                                    className="size-2.5 shrink-0 rounded-full"
                                    style={{
                                        backgroundColor:
                                            subnetChartConfig[category].color,
                                    }}
                                />
                                {subnetChartConfig[category].label}
                            </span>
                            <span className="shrink-0 font-medium tabular-nums">
                                {count.toLocaleString('it-IT')}
                            </span>
                        </li>
                    ))}
                </ul>
            </figcaption>
        </figure>
    );
}
