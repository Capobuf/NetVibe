import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ArrowRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDateTime } from '@/lib/utils';

export type Status = { label: string; tone: 'ok' | 'idle' | 'error' };

export function syncStatus(activated: string | null, error: string | null): Status {
    if (error) return { label: 'Errore', tone: 'error' };
    return activated
        ? { label: 'Attiva · ogni ora', tone: 'ok' }
        : { label: 'Da avviare', tone: 'idle' };
}

export function StatusBadge({ status }: { status: Status }) {
    return (
        <Badge
            variant={
                status.tone === 'error'
                    ? 'destructive'
                    : status.tone === 'ok'
                      ? 'secondary'
                      : 'outline'
            }
        >
            {status.tone === 'ok' ? (
                <span
                    aria-hidden="true"
                    className="size-1.5 rounded-full bg-emerald-500"
                />
            ) : null}
            {status.label}
        </Badge>
    );
}

export function LastRead({ value }: { value: string | null }) {
    return (
        <span className="text-xs text-muted-foreground tabular-nums">
            {value
                ? `Ultima lettura ${formatDateTime(value)}`
                : 'Mai sincronizzata'}
        </span>
    );
}

/** Integration summary card shared by the administration and company overviews. */
export function IntegrationCard({
    mark,
    title,
    status,
    actions,
    footer,
    children,
}: {
    mark: ReactNode;
    title: string;
    status: Status;
    actions?: ReactNode;
    footer?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card className="h-full">
            <CardHeader className="flex-row items-start justify-between gap-3">
                <div className="flex flex-col gap-2">
                    <CardTitle className="flex items-center gap-3">
                        {mark}
                        {title}
                    </CardTitle>
                    <div>
                        <StatusBadge status={status} />
                    </div>
                </div>
                {actions ? (
                    <div className="-mt-1.5 -mr-2 flex items-center">
                        {actions}
                    </div>
                ) : null}
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-3">
                {children}
            </CardContent>
            {footer ? (
                <CardFooter className="gap-2">{footer}</CardFooter>
            ) : null}
        </Card>
    );
}

export function ManageButton({
    href,
    children,
    primary,
}: {
    href: Parameters<typeof Link>[0]['href'];
    children: ReactNode;
    primary?: boolean;
}) {
    return (
        <Button
            asChild
            variant={primary ? 'default' : 'outline'}
            className="w-full justify-between"
        >
            <Link href={href}>
                {children}
                <ArrowRight data-icon="inline-end" />
            </Link>
        </Button>
    );
}
