import {
    ChevronDown,
    Eye,
    EyeOff,
    Lock,
    Monitor,
    MoreHorizontal,
    Server,
    Unlock,
} from 'lucide-react';
import { orderActions } from '@/components/hardware-editor/editor-layers';
import { IconButton } from '@/components/icon-button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { LayerOrderAction } from '@/lib/hardware-editor/document';
import { cn } from '@/lib/utils';

export type FloorLayer = {
    key: string;
    kind: 'device' | 'rack';
    label: string;
    detail: string;
    /** Layer group: the model type, or Rack. */
    group: string;
    hidden: boolean;
    locked: boolean;
};

/** Placed elements grouped by type, topmost first, with visibility, lock and stacking order. */
export function FloorLayers({
    layers,
    selected,
    disabled,
    onLocate,
    onHidden,
    onLocked,
    onRestack,
    canRestack,
}: {
    /** In stacking order, bottom first. */
    layers: FloorLayer[];
    selected: string | null;
    disabled: boolean;
    onLocate: (key: string) => void;
    onHidden: (keys: string[], hidden: boolean) => void;
    onLocked: (keys: string[], locked: boolean) => void;
    onRestack: (key: string, action: LayerOrderAction) => void;
    canRestack: (key: string, action: LayerOrderAction) => boolean;
}) {
    const groups = new Map<string, FloorLayer[]>();
    for (const layer of [...layers].reverse())
        groups.set(layer.group, [...(groups.get(layer.group) ?? []), layer]);
    const names = [...groups.keys()].sort((a, b) =>
        a === 'Rack' ? -1 : b === 'Rack' ? 1 : a.localeCompare(b, 'it'),
    );
    return (
        <div className="flex flex-col gap-1">
            {names.map((name) => {
                const items = groups.get(name)!;
                const keys = items.map((item) => item.key);
                const allHidden = items.every((item) => item.hidden);
                const allLocked = items.every((item) => item.locked);
                return (
                    <Collapsible key={name} defaultOpen>
                        <div className="flex items-center gap-1 rounded-md pr-1 hover:bg-accent/60">
                            <CollapsibleTrigger className="flex min-w-0 flex-1 items-center gap-1.5 rounded-md px-2 py-1.5 text-left text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring [&[data-state=closed]>svg]:-rotate-90">
                                <ChevronDown className="size-4 shrink-0 text-muted-foreground transition-transform" />
                                <span className="truncate">{name}</span>
                                <span className="text-xs font-normal text-muted-foreground tabular-nums">
                                    {items.length}
                                </span>
                            </CollapsibleTrigger>
                            <IconButton
                                label={
                                    allHidden
                                        ? `Mostra ${name}`
                                        : `Nascondi ${name}`
                                }
                                variant="ghost"
                                className="size-7"
                                disabled={disabled}
                                onClick={() => onHidden(keys, !allHidden)}
                            >
                                {allHidden ? <EyeOff /> : <Eye />}
                            </IconButton>
                            <IconButton
                                label={
                                    allLocked
                                        ? `Sblocca ${name}`
                                        : `Blocca ${name}`
                                }
                                variant="ghost"
                                className="size-7"
                                disabled={disabled}
                                onClick={() => onLocked(keys, !allLocked)}
                            >
                                {allLocked ? <Lock /> : <Unlock />}
                            </IconButton>
                        </div>
                        <CollapsibleContent>
                            <ul className="flex flex-col gap-0.5 pb-1 pl-3">
                                {items.map((item) => {
                                    const Icon =
                                        item.kind === 'rack' ? Server : Monitor;
                                    return (
                                        <li
                                            key={item.key}
                                            className={cn(
                                                'flex items-center gap-0.5 rounded-md pr-1 transition-colors hover:bg-accent',
                                                selected === item.key &&
                                                    'bg-accent ring-1 ring-primary/40',
                                            )}
                                        >
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onLocate(item.key)
                                                }
                                                aria-current={
                                                    selected === item.key ||
                                                    undefined
                                                }
                                                className={cn(
                                                    'flex min-w-0 flex-1 items-center gap-2.5 rounded-md px-2 py-1.5 text-left focus-visible:outline-2 focus-visible:outline-ring',
                                                    item.hidden &&
                                                        'text-muted-foreground',
                                                )}
                                            >
                                                <Icon className="size-4 shrink-0 text-muted-foreground" />
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm">
                                                        {item.label}
                                                    </span>
                                                    <span className="block truncate text-xs text-muted-foreground">
                                                        {item.detail}
                                                    </span>
                                                </span>
                                            </button>
                                            <IconButton
                                                label={
                                                    item.hidden
                                                        ? `Mostra ${item.label}`
                                                        : `Nascondi ${item.label}`
                                                }
                                                variant="ghost"
                                                className="size-7"
                                                disabled={disabled}
                                                onClick={() =>
                                                    onHidden(
                                                        [item.key],
                                                        !item.hidden,
                                                    )
                                                }
                                            >
                                                {item.hidden ? (
                                                    <EyeOff />
                                                ) : (
                                                    <Eye />
                                                )}
                                            </IconButton>
                                            <IconButton
                                                label={
                                                    item.locked
                                                        ? `Sblocca ${item.label}`
                                                        : `Blocca ${item.label}`
                                                }
                                                variant="ghost"
                                                className="size-7"
                                                disabled={disabled}
                                                onClick={() =>
                                                    onLocked(
                                                        [item.key],
                                                        !item.locked,
                                                    )
                                                }
                                            >
                                                {item.locked ? (
                                                    <Lock />
                                                ) : (
                                                    <Unlock />
                                                )}
                                            </IconButton>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <IconButton
                                                        label={`Ordine di ${item.label}`}
                                                        variant="ghost"
                                                        className="size-7"
                                                        disabled={disabled}
                                                    >
                                                        <MoreHorizontal />
                                                    </IconButton>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuGroup>
                                                        {orderActions.map(
                                                            (action) => (
                                                                <DropdownMenuItem
                                                                    key={
                                                                        action.value
                                                                    }
                                                                    disabled={
                                                                        !canRestack(
                                                                            item.key,
                                                                            action.value,
                                                                        )
                                                                    }
                                                                    onSelect={() =>
                                                                        onRestack(
                                                                            item.key,
                                                                            action.value,
                                                                        )
                                                                    }
                                                                >
                                                                    <action.icon />
                                                                    {
                                                                        action.label
                                                                    }
                                                                </DropdownMenuItem>
                                                            ),
                                                        )}
                                                    </DropdownMenuGroup>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </li>
                                    );
                                })}
                            </ul>
                        </CollapsibleContent>
                    </Collapsible>
                );
            })}
        </div>
    );
}
