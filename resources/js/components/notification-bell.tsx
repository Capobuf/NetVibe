import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    CircleAlert,
    CircleCheck,
    Mail,
    MailOpen,
    MoreHorizontal,
    Settings,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn, formatDateTime } from '@/lib/utils';
import {
    destroy,
    destroyRead,
    edit,
    mark,
    read,
    readAll,
} from '@/routes/notifications';
import type { AppNotification } from '@/types/global';

const sourceNames = {
    snipe_it: 'Snipe-IT',
    mikrotik: 'MikroTik',
    unifi: 'UniFi',
    google_drive: 'Google Drive',
};

const relative = new Intl.RelativeTimeFormat('it-IT', { numeric: 'auto' });

function timeAgo(value: string): string {
    const minutes = Math.round(
        (new Date(value).getTime() - Date.now()) / 60000,
    );
    if (minutes === 0) return 'adesso';
    if (Math.abs(minutes) < 60) return relative.format(minutes, 'minute');
    const hours = Math.round(minutes / 60);
    if (Math.abs(hours) < 24) return relative.format(hours, 'hour');
    return relative.format(Math.round(hours / 24), 'day');
}

const visit = { preserveScroll: true, preserveState: true };

function NotificationRow({
    notification,
    onOpen,
}: {
    notification: AppNotification;
    onOpen: () => void;
}) {
    const { data, read_at: readAt } = notification;
    const Icon = data.failed ? CircleAlert : CircleCheck;

    return (
        <li className="group relative">
            <Link
                href={read(notification.id)}
                as="button"
                onClick={onOpen}
                className={cn(
                    'flex w-full gap-3 py-3 pr-10 pl-4 text-left outline-hidden transition-colors hover:bg-accent/50 focus-visible:bg-accent/50',
                    !readAt && 'bg-muted/40',
                )}
            >
                <span
                    className={cn(
                        'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full',
                        data.failed
                            ? 'bg-destructive/10 text-destructive'
                            : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                    )}
                >
                    <Icon aria-hidden="true" className="size-4" />
                </span>
                <span className="flex min-w-0 flex-1 flex-col gap-1 text-sm">
                    <span className="text-muted-foreground">
                        <strong className="font-medium text-foreground">
                            {data.subject}
                        </strong>{' '}
                        sincronizzazione{' '}
                        <strong className="font-medium text-foreground">
                            {sourceNames[data.source]}
                        </strong>{' '}
                        {data.failed ? 'non riuscita' : 'ripristinata'}
                    </span>
                    {data.message && (
                        <span className="line-clamp-2 text-xs text-muted-foreground">
                            {data.message}
                        </span>
                    )}
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span className="w-fit text-xs text-muted-foreground">
                                {timeAgo(notification.created_at)}
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>
                            {formatDateTime(notification.created_at)}
                        </TooltipContent>
                    </Tooltip>
                </span>
                {!readAt && <span className="sr-only">Non letta</span>}
            </Link>
            {!readAt && (
                <span
                    aria-hidden="true"
                    className="absolute top-4.5 right-4 size-2 rounded-full bg-foreground max-md:hidden md:group-focus-within:hidden md:group-hover:hidden"
                />
            )}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Azioni notifica"
                        className="absolute top-2 right-1.5 size-8 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 data-[state=open]:opacity-100 md:opacity-0"
                    >
                        <MoreHorizontal />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        onSelect={() =>
                            router.patch(
                                mark(notification.id),
                                { read: !readAt },
                                visit,
                            )
                        }
                    >
                        {readAt ? <Mail /> : <MailOpen />}
                        {readAt ? 'Segna come non letta' : 'Segna come letta'}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() =>
                            router.delete(destroy(notification.id), visit)
                        }
                    >
                        <Trash2 />
                        Elimina
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </li>
    );
}

export function NotificationBell() {
    const { notifications } = usePage().props;
    const [open, setOpen] = useState(false);
    const [filter, setFilter] = useState('all');
    if (!notifications) return null;
    const { unread, latest } = notifications;
    const shown =
        filter === 'unread'
            ? latest.filter((notification) => !notification.read_at)
            : latest;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative size-9 shrink-0 data-[state=open]:bg-accent"
                    aria-label={
                        unread ? `Notifiche, ${unread} non lette` : 'Notifiche'
                    }
                >
                    <Bell />
                    {unread > 0 && (
                        <span className="absolute top-2 right-2 size-2 rounded-full bg-foreground ring-2 ring-background" />
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="w-[min(24rem,calc(100vw-2rem))] gap-0 overflow-hidden rounded-xl p-0"
                // Keep focus on the panel itself, so no control looks pre-selected on open.
                onOpenAutoFocus={(event) => event.preventDefault()}
            >
                <div className="flex items-center justify-between gap-2 px-4 pt-4">
                    <h2 className="text-base font-semibold">Notifiche</h2>
                    {unread > 0 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="-mr-2 h-7"
                            onClick={() => router.post(readAll(), {}, visit)}
                        >
                            Segna tutte come lette
                        </Button>
                    )}
                </div>
                <Tabs value={filter} onValueChange={setFilter}>
                    <TabsList
                        variant="line"
                        className="h-auto w-full justify-start gap-4 border-b px-4 pt-2"
                    >
                        <TabsTrigger
                            value="all"
                            className="flex-none px-0 pb-2"
                        >
                            Tutte
                        </TabsTrigger>
                        <TabsTrigger
                            value="unread"
                            className="flex-none px-0 pb-2"
                        >
                            Non lette
                            {unread > 0 && (
                                <span className="rounded-full bg-muted px-1.5 text-xs tabular-nums">
                                    {unread}
                                </span>
                            )}
                        </TabsTrigger>
                    </TabsList>
                </Tabs>
                {shown.length ? (
                    <ul className="max-h-[28rem] divide-y overflow-y-auto">
                        {shown.map((notification) => (
                            <NotificationRow
                                key={notification.id}
                                notification={notification}
                                onOpen={() => setOpen(false)}
                            />
                        ))}
                    </ul>
                ) : (
                    <div className="flex flex-col items-center gap-3 px-4 py-10 text-center">
                        <span className="flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground">
                            <Bell className="size-5" />
                        </span>
                        <p className="text-sm text-muted-foreground">
                            {filter === 'unread'
                                ? 'Nessuna notifica da leggere'
                                : 'Nessuna notifica'}
                        </p>
                    </div>
                )}
                <div className="flex items-center justify-between gap-2 border-t bg-muted/30 px-2 py-1.5">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 text-muted-foreground"
                        asChild
                    >
                        <Link href={edit()} onClick={() => setOpen(false)}>
                            <Settings />
                            Preferenze
                        </Link>
                    </Button>
                    {latest.some((notification) => notification.read_at) && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 text-muted-foreground"
                            onClick={() => router.delete(destroyRead(), visit)}
                        >
                            <Trash2 />
                            Elimina le lette
                        </Button>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
