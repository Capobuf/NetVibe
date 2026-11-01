import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';

export function ListPageHeader({
    title,
    icon: Icon,
    actions,
    id,
}: {
    title: string;
    icon?: LucideIcon;
    actions?: ReactNode;
    id?: string;
}) {
    return (
        <header className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex min-w-0 items-center gap-3">
                {Icon ? (
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-secondary text-secondary-foreground">
                        <Icon className="size-5" aria-hidden="true" />
                    </span>
                ) : null}
                <h1 id={id} className="text-2xl font-medium tracking-tight">
                    {title}
                </h1>
            </div>
            {actions}
        </header>
    );
}
