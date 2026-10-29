import { usePage } from '@inertiajs/react';
import { NotebookPen, Pencil, Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import { CopyValue } from '@/components/copy-value';
import { IconButton } from '@/components/icon-button';
import SubnetSummaryEntryForm, {
    type SummaryRoute,
} from '@/components/networks/subnet-summary-entry-form';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Separator } from '@/components/ui/separator';
import type { SubnetSummaryEntry } from '@/types/network';

export default function SubnetSummary({
    route,
    entries,
}: {
    route: SummaryRoute;
    entries: SubnetSummaryEntry[];
}) {
    const { auth } = usePage().props;
    const [editing, setEditing] = useState<SubnetSummaryEntry | 'new' | null>(
        null,
    );
    const menuTrigger = useRef<HTMLButtonElement>(null);
    const dialogOpener = useRef<HTMLElement | null>(null);

    return (
        <section
            className="flex min-w-0 flex-col gap-5"
            aria-labelledby="subnet-summary-title"
        >
            <header className="flex items-center gap-1">
                <h2 id="subnet-summary-title" className="font-semibold">
                    Riepilogo
                </h2>
                {auth.user.is_superadmin ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <IconButton
                                ref={menuTrigger}
                                label="Modifica riepilogo"
                                variant="ghost"
                                className="size-8"
                            >
                                <Pencil />
                            </IconButton>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            className="max-h-80 max-w-xs overflow-y-auto"
                            onCloseAutoFocus={(event) => {
                                if (editing) event.preventDefault();
                            }}
                        >
                            <DropdownMenuGroup>
                                <DropdownMenuItem
                                    onSelect={() => {
                                        dialogOpener.current =
                                            menuTrigger.current;
                                        setEditing('new');
                                    }}
                                >
                                    <Plus />
                                    Nuova voce
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                            {entries.length ? (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuGroup>
                                        {entries.map((entry) => (
                                            <DropdownMenuItem
                                                key={entry.id}
                                                onSelect={() => {
                                                    dialogOpener.current =
                                                        menuTrigger.current;
                                                    setEditing(entry);
                                                }}
                                            >
                                                <span className="break-words">
                                                    {entry.name}
                                                </span>
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuGroup>
                                </>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </header>
            {entries.length ? (
                <dl className="flex flex-col gap-3">
                    {entries.map((entry) => (
                        <div
                            key={entry.id}
                            className="grid min-w-0 grid-cols-[minmax(0,1fr)_minmax(0,3fr)] items-start gap-4"
                        >
                            <dt className="min-w-0 text-sm font-medium">
                                {auth.user.is_superadmin ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-auto max-w-full justify-start px-2 py-1.5 text-left break-words whitespace-normal"
                                        aria-label={`Modifica ${entry.name}`}
                                        aria-haspopup="dialog"
                                        onClick={(event) => {
                                            dialogOpener.current =
                                                event.currentTarget;
                                            setEditing(entry);
                                        }}
                                    >
                                        <span className="min-w-0 break-words">
                                            {entry.name}
                                        </span>
                                    </Button>
                                ) : (
                                    <span className="block px-2 py-1.5 break-words">
                                        {entry.name}
                                    </span>
                                )}
                            </dt>
                            <dd className="min-w-0">
                                <ul className="flex flex-wrap items-center gap-x-1 gap-y-1">
                                    {entry.values.map((value, index) => (
                                        <li
                                            key={index}
                                            className="flex max-w-full min-w-0 items-center gap-1"
                                        >
                                            {index > 0 ? (
                                                <Separator
                                                    orientation="vertical"
                                                    className="data-[orientation=vertical]:h-4"
                                                />
                                            ) : null}
                                            <CopyValue value={value} />
                                        </li>
                                    ))}
                                </ul>
                            </dd>
                        </div>
                    ))}
                </dl>
            ) : (
                <Empty className="py-4 md:py-4">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <NotebookPen />
                        </EmptyMedia>
                        <EmptyTitle>Nessuna voce nel riepilogo</EmptyTitle>
                    </EmptyHeader>
                </Empty>
            )}
            {editing !== null ? (
                <SubnetSummaryEntryForm
                    key={editing === 'new' ? 'new' : editing.id}
                    route={route}
                    entry={editing === 'new' ? undefined : editing}
                    onOpenChange={(open) => {
                        if (!open) setEditing(null);
                    }}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        const opener = dialogOpener.current;
                        (opener?.isConnected
                            ? opener
                            : menuTrigger.current
                        )?.focus();
                    }}
                />
            ) : null}
        </section>
    );
}
