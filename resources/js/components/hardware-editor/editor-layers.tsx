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
import { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import { Choice } from './editor-fields';
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
    connectorCategories,
    categorizedLayers,
    type ConnectorCategory,
    type FaceLayer,
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
    const [grouping, setGrouping] = useState(false);
    const [category, setCategory] = useState<ConnectorCategory>('rj45');
    const categoryPorts = e.ports.filter(
        (p) => connectorTypes[p.type].category === category,
    );
    const categoryChoices = Object.fromEntries(
        Object.entries(connectorCategories).filter(([key]) =>
            e.ports.some((p) => connectorTypes[p.type].category === key),
        ),
    );
    function renderLayer(layer: FaceLayer) {
        const ports = layer.ids.map((id) => e.ports.find((p) => p.id === id)!);
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
                    <Button variant="ghost" size="sm" className="ml-2 gap-1">
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
    }
    return (
        <TooltipProvider delayDuration={700} skipDelayDuration={0}>
            <div className="flex h-full flex-col">
                <div className="flex items-center justify-between gap-2 border-b px-3 py-3">
                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                        <Layers className="size-4" />
                        Livelli
                    </h2>
                    <IconButton
                        label="Raggruppa per categoria"
                        variant="ghost"
                        disabled={disabled || e.ports.length < 2}
                        onClick={() => {
                            setCategory(
                                (Object.keys(categoryChoices).find(
                                    (key) =>
                                        e.ports.filter(
                                            (p) =>
                                                connectorTypes[p.type]
                                                    .category === key,
                                        ).length > 1,
                                ) ??
                                    Object.keys(
                                        categoryChoices,
                                    )[0]) as ConnectorCategory,
                            );
                            setGrouping(true);
                        }}
                    >
                        <Group />
                    </IconButton>
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
                    {picture && (
                        <LayerRow
                            editor={e}
                            name="Immagine"
                            icon={Image}
                            selection={{ kind: 'image', ids: [] }}
                            state={picture}
                        />
                    )}
                    {categorizedLayers(e.doc.faces[e.face]).map((section) => (
                        <Collapsible
                            key={section.id}
                            defaultOpen
                            className="rounded-md border"
                        >
                            <CollapsibleTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="w-full justify-start"
                                >
                                    <ChevronDown />
                                    <span className="min-w-0 flex-1 truncate text-left">
                                        {section.name}
                                    </span>
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {section.layers.reduce(
                                            (count, layer) =>
                                                count + layer.ids.length,
                                            0,
                                        )}
                                    </span>
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                {section.layers.map(renderLayer)}
                            </CollapsibleContent>
                        </Collapsible>
                    ))}
                    <LayerRow
                        editor={e}
                        name="Scocca"
                        icon={PanelsTopLeft}
                        selection={{ kind: 'shell', ids: [] }}
                        state={e.shellState}
                    />
                </fieldset>
            </div>
            <Dialog open={grouping} onOpenChange={setGrouping}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Raggruppa per categoria</DialogTitle>
                        <DialogDescription>
                            {categoryPorts.length} porte sul{' '}
                            {e.face === 'front' ? 'frontale' : 'retro'}.
                            {categoryPorts.some((p) => p.group) &&
                                ' Le porte saranno spostate dai gruppi attuali nel nuovo gruppo.'}
                        </DialogDescription>
                    </DialogHeader>
                    <Choice
                        label="Categoria"
                        value={category}
                        choices={categoryChoices}
                        onChange={(value) =>
                            setCategory(value as ConnectorCategory)
                        }
                    />
                    <ul className="flex max-h-56 flex-col gap-2 overflow-y-auto rounded-md border p-3">
                        {categoryPorts.map((p) => (
                            <li
                                key={p.id}
                                className="flex items-center gap-2 text-sm"
                            >
                                <span
                                    className="size-3 shrink-0 rounded-full border"
                                    style={{ backgroundColor: p.borderColor }}
                                />
                                <span className="truncate">
                                    {portName(p.id)}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setGrouping(false)}
                        >
                            Annulla
                        </Button>
                        <Button
                            disabled={disabled || categoryPorts.length < 2}
                            onClick={() => {
                                e.groupPorts(categoryPorts.map((p) => p.id));
                                setGrouping(false);
                            }}
                        >
                            <Group />
                            Raggruppa {categoryPorts.length} porte
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </TooltipProvider>
    );
}
