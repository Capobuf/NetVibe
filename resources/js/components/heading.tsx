export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    return (
        <header
            className={
                variant === 'small'
                    ? 'flex flex-col gap-1'
                    : 'flex flex-col gap-2'
            }
        >
            <h2
                className={
                    variant === 'small'
                        ? 'text-base font-medium text-balance'
                        : 'text-2xl font-medium tracking-tight text-balance'
                }
            >
                {title}
            </h2>
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
        </header>
    );
}
