const categories = {
    documented: { label: 'Documentati', color: 'var(--chart-2)' },
    excluded: { label: 'Riservati', color: 'var(--chart-5)' },
    external: { label: 'Fonti esterne', color: 'var(--chart-3)' },
    proposable: { label: 'Proponibili', color: 'var(--muted-foreground)' },
};

export default function SubnetOccupancyChart({
    counts,
}: {
    counts: Record<keyof typeof categories, number>;
}) {
    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);
    const entries = (
        Object.keys(categories) as Array<keyof typeof categories>
    ).map((key) => ({
        key,
        ...categories[key],
        count: counts[key],
    }));

    return (
        <figure className="flex w-full min-w-0 flex-col gap-4">
            <figcaption className="flex items-baseline justify-between gap-3">
                <span className="text-sm font-medium">Occupazione</span>
                <span className="text-xs text-muted-foreground">
                    <span className="tabular-nums">
                        {total.toLocaleString('it-IT')}
                    </span>{' '}
                    IP utilizzabili
                </span>
            </figcaption>
            <div
                className="flex h-3 overflow-hidden rounded-full bg-muted"
                aria-hidden="true"
            >
                {entries.map(({ key, count, color }) => (
                    <span
                        key={key}
                        style={{
                            width: `${total ? (count / total) * 100 : 0}%`,
                            backgroundColor: color,
                        }}
                    />
                ))}
            </div>
            <dl className="flex flex-col gap-2.5 text-sm">
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
        </figure>
    );
}
