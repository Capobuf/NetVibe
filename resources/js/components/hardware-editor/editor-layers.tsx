import {
    ArrowDown,
    ArrowDownToLine,
    ArrowUp,
    ArrowUpToLine,
    ChevronDown,
    Eye,
    EyeOff,
    Group,
    Image,
    Layers,
    Lock,
    MoreHorizontal,
    PanelsTopLeft,
    Plug,
    Unlock,
    X,
} from 'lucide-react';
import { IconButton } from '@/components/icon-button';
import { Button } from '@/components/ui/button';
import { TooltipProvider } from '@/components/ui/tooltip';
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
import {
    connectorTypes,
    type LayerOrderAction,
    type LayerState,
} from '@/lib/hardware-editor/document';
import { cn } from '@/lib/utils';
import type { Editor, Selection } from './use-editor';

export const orderActions: {
    value: LayerOrderAction;
    label: string;
    icon: typeof ArrowUp;
}[] = [
    { value: 'forward', label: 'Porta avanti', icon: ArrowUp },
    { value: 'backward', label: 'Porta indietro', icon: ArrowDown },
    { value: 'front', label: 'Porta in primo piano', icon: ArrowUpToLine },
    { value: 'back', label: 'Porta sullo sfondo', icon: ArrowDownToLine },
];

export function LayerOrderMenu({
    editor: e,
    selection = e.selection,
}: {
    editor: Editor;
    selection?: Selection;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <IconButton label="Ordine di sovrapposizione" variant="ghost">
                    <MoreHorizontal />
                </IconButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuGroup>
                    {orderActions.map((action) => (
                        <DropdownMenuItem
                            key={action.value}
                            disabled={!e.canReorder(action.value, selection)}
                            onSelect={() => e.reorder(action.value, selection)}
                        >
                            <action.icon />
                            {action.label}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function LayerRow({
    editor: e,
    name,
    icon: Icon,
    selection,
    state,
    child = false,
}: {
    editor: Editor;
    name: string;
    icon: typeof Layers;
    selection: Selection;
    state: LayerState;
    child?: boolean;
}) {
    const selected =
        selection.kind === 'ports'
            ? selection.ids.every((id) => e.selection.ids.includes(id))
            : selection.kind === e.selection.kind;
    return (
        <div
            className={cn(
                'flex min-w-0 items-center rounded-md',
                selected && 'bg-accent',
                child && 'ml-5',
            )}
        >
            <Button
                variant="ghost"
                className="min-w-0 flex-1 justify-start px-2"
                aria-pressed={selected}
                onClick={(event) => {
                    if (selection.kind === 'ports') {
                        const port = e.ports.find(
                            (p) => p.id === selection.ids[0],
                        );
                        e.setEnteredGroup(child ? (port?.group ?? null) : null);
                        const additive =
                            event.ctrlKey || event.metaKey || event.shiftKey;
                        e.setSelection({
                            kind: 'ports',
                            ids: additive
                                ? selected
                                    ? e.selection.ids.filter(
                                          (id) => !selection.ids.includes(id),
                                      )
                                    : [
                                          ...new Set([
                                              ...e.selection.ids,
                                              ...selection.ids,
                                          ]),
                                      ]
                                : selection.ids,
                        });
                    } else {
                        e.setSelection(selection);
                        e.setEnteredGroup(null);
                    }
                }}
            >
                <Icon />
                <span
                    className={cn(
                        'truncate',
                        state.hidden && 'text-muted-foreground',
                    )}
                >
                    {name}
                </span>
            </Button>
            <IconButton
                variant="ghost"
                label={`${state.hidden ? 'Mostra' : 'Nascondi'} ${name}`}
                aria-pressed={!!state.hidden}
                onClick={() =>
                    e.patchLayer(selection, { hidden: !state.hidden })
                }
            >
                {state.hidden ? <EyeOff /> : <Eye />}
            </IconButton>
            <IconButton
                variant="ghost"
                label={`${state.locked ? 'Sblocca' : 'Blocca'} ${name}`}
                aria-pressed={!!state.locked}
                onClick={() =>
                    e.patchLayer(selection, { locked: !state.locked })
                }
            >
                {state.locked ? <Lock /> : <Unlock />}
            </IconButton>
            {selection.kind !== 'shell' && (
                <LayerOrderMenu editor={e} selection={selection} />
            )}
        </div>
    );
}

export function EditorLayers({
    editor: e,
    onClose,
    disabled,
}: {
    editor: Editor;
    onClose: () => void;
    disabled: boolean;
}) {
    const picture = e.doc.faces[e.face].image;
    const portName = (id: string) => {
        const p = e.ports.find((p) => p.id === id)!;
        return p.label
            ? `${p.label} · ${connectorTypes[p.type].name}`
            : `${connectorTypes[p.type].name} · ${e.ports.indexOf(p) + 1}`;
    };
    return (
        <TooltipProvider delayDuration={700} skipDelayDuration={0}>
            <div className="flex h-full flex-col">
                <div className="flex items-center justify-between gap-2 border-b px-3 py-3">
                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                        <Layers className="size-4" />
                        Livelli
                    </h2>
                    <IconButton
                        label="Chiudi livelli"
                        variant="ghost"
                        onClick={onClose}
                    >
                        <X />
                    </IconButton>
                </div>
                <p className="px-3 pt-3 text-xs text-muted-foreground">
                    {e.face === 'front' ? 'Frontale' : 'Retro'}
                </p>
                <fieldset
                    disabled={disabled}
                    className="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto p-2 disabled:opacity-50"
                >
                    {[...e.layers].reverse().map((layer) => {
                        if (layer.id === 'image')
                            return (
                                picture && (
                                    <LayerRow
                                        key="image"
                                        editor={e}
                                        name="Immagine"
                                        icon={Image}
                                        selection={{ kind: 'image', ids: [] }}
                                        state={picture}
                                    />
                                )
                            );
                        const ports = layer.ids.map((id) =>
                            e.ports.find((p) => p.id === id)!,
                        );
                        const selection: Selection = {
                            kind: 'ports',
                            ids: layer.ids,
                        };
                        if (!ports[0].group)
                            return (
                                <LayerRow
                                    key={layer.id}
                                    editor={e}
                                    name={portName(ports[0].id)}
                                    icon={Plug}
                                    selection={selection}
                                    state={ports[0]}
                                />
                            );
                        return (
                            <Collapsible key={layer.id}>
                                <LayerRow
                                    editor={e}
                                    name={`Gruppo · ${ports.length} porte`}
                                    icon={Group}
                                    selection={selection}
                                    state={{
                                        hidden: ports.every((p) => p.hidden),
                                        locked: ports.some((p) => p.locked),
                                    }}
                                />
                                <CollapsibleTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="ml-2 gap-1"
                                    >
                                        <ChevronDown />
                                        Porte del gruppo
                                    </Button>
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    {[...ports].reverse().map((p) => (
                                        <LayerRow
                                            key={p.id}
                                            editor={e}
                                            name={portName(p.id)}
                                            icon={Plug}
                                            child
                                            selection={{
                                                kind: 'ports',
                                                ids: [p.id],
                                            }}
                                            state={p}
                                        />
                                    ))}
                                </CollapsibleContent>
                            </Collapsible>
                        );
                    })}
                    <LayerRow
                        editor={e}
                        name="Scocca"
                        icon={PanelsTopLeft}
                        selection={{ kind: 'shell', ids: [] }}
                        state={e.shellState}
                    />
                </fieldset>
            </div>
        </TooltipProvider>
    );
}
