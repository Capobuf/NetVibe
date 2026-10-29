import { useSyncExternalStore } from 'react';
import { PolarAngleAxis, PolarGrid, RadialBar, RadialBarChart } from 'recharts';
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
        <figure className="mx-auto flex w-60 max-w-full flex-col items-center gap-2">
            <ChartContainer
                config={subnetChartConfig}
                initialDimension={{ width: 240, height: 240 }}
                className="aspect-square w-full rounded-full focus-within:ring-2 focus-within:ring-ring [&_.recharts-sector]:transition-opacity [&_.recharts-sector:hover]:opacity-75"
            >
                <RadialBarChart
                    data={data}
                    innerRadius={30}
                    outerRadius={100}
                    startAngle={90}
                    endAngle={-270}
                    accessibilityLayer
                    aria-label={`Occupazione subnet: ${counts.documented.toLocaleString('it-IT')} documentati, ${counts.excluded.toLocaleString('it-IT')} riservati, ${counts.proposable.toLocaleString('it-IT')} liberi su ${total.toLocaleString('it-IT')} IP utilizzabili`}
                >
                    <ChartTooltip
                        cursor={false}
                        content={
                            <ChartTooltipContent hideLabel nameKey="category" />
                        }
                    />
                    <PolarAngleAxis
                        type="number"
                        domain={[0, total]}
                        tick={false}
                        axisLine={false}
                    />
                    <PolarGrid gridType="circle" />
                    <RadialBar
                        dataKey="count"
                        isAnimationActive={!prefersReducedMotion}
                        animationDuration={700}
                        animationEasing="ease-out"
                    />
                </RadialBarChart>
            </ChartContainer>
            <figcaption className="text-center text-xs text-muted-foreground">
                <span className="font-medium text-foreground tabular-nums">
                    {total.toLocaleString('it-IT')}
                </span>{' '}
                IP utilizzabili
            </figcaption>
        </figure>
    );
}
