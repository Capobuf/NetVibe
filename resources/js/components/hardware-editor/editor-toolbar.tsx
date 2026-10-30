import {
    ChevronDown,
    Copy,
    Grid2X2,
    Group,
    Hand,
    Hash,
    ImagePlus,
    Magnet,
    Layers,
    MoreHorizontal,
    MousePointer2,
    PanelRight,
    Plus,
    Redo2,
    RotateCw,
    Scan,
    Trash2,
    Undo2,
    Ungroup,
} from 'lucide-react';
import { IconButton } from '@/components/icon-button';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    connectorTypes,
    type ConnectorType,
} from '@/lib/hardware-editor/document';
import { alignmentActions } from './editor-inspector';
import type { Editor } from './use-editor';

export function EditorToolbar({
    editor: e,
    blocked,
    previewing,
    tool,
    setTool,
    snap,
    setSnap,
    inspector,
    setInspector,
    layers,
    setLayers,
    add,
    image,
    onBlock,
    onNumbering,
}: {
    editor: Editor;
    blocked: boolean;
    previewing: boolean;
    tool: string;
    setTool: (tool: string) => void;
    snap: boolean;
    setSnap: (snap: boolean) => void;
    layers: boolean;
    setLayers: (open: boolean) => void;
    inspector: boolean;
    setInspector: (open: boolean) => void;
    add: (type: ConnectorType) => void;
    image: () => void;
    onBlock: () => void;
    onNumbering: (mode: 'quick' | 'click') => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2 border-y bg-background px-3 py-2">
            <ToggleGroup
                type="single"
                variant="outline"
                value={tool}
                disabled={blocked || e.loading}
                onValueChange={(value) => {
                    if (value) setTool(value);
                }}
                aria-label="Strumento"
            >
                <Tooltip>
                    <TooltipTrigger asChild>
                        <ToggleGroupItem
                            value="select"
                            aria-label="Seleziona"
                            className="size-10"
                        >
                            <MousePointer2 />
                        </ToggleGroupItem>
                    </TooltipTrigger>
                    <TooltipContent>
                        Seleziona · Ctrl/Cmd+clic per aggiungere
                    </TooltipContent>
                </Tooltip>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <ToggleGroupItem
                            value="pan"
                            aria-label="Sposta vista"
                            className="size-10"
                        >
                            <Hand />
                        </ToggleGroupItem>
                    </TooltipTrigger>
                    <TooltipContent>
                        Sposta vista · Spazio o clic centrale+trascina
                    </TooltipContent>
                </Tooltip>
            </ToggleGroup>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button disabled={blocked || e.loading}>
                        <Plus />
                        Aggiungi
                        <ChevronDown />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    <DropdownMenuGroup>
                        {Object.entries(connectorTypes).map(([type, item]) => (
                            <DropdownMenuItem
                                key={type}
                                onSelect={() => add(type as ConnectorType)}
                            >
                                {item.name}
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={onBlock}>
                            <Grid2X2 />
                            Blocco porte
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={image}>
                            <ImagePlus />
                            Immagine di riferimento
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                </DropdownMenuContent>
            </DropdownMenu>
            <Separator orientation="vertical" className="mx-1 h-6" />
            <IconButton
                label="Annulla · Ctrl/Cmd+Z"
                variant="ghost"
                disabled={!e.canUndo || e.loading || previewing || !!e.crop}
                onClick={e.undo}
            >
                <Undo2 />
            </IconButton>
            <IconButton
                label="Ripristina · Ctrl/Cmd+Shift+Z"
                variant="ghost"
                disabled={!e.canRedo || e.loading || previewing || !!e.crop}
                onClick={e.redo}
            >
                <Redo2 />
            </IconButton>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" disabled={blocked || e.loading}>
                        <Hash />
                        Numera
                        <ChevronDown />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    <DropdownMenuGroup>
                        <DropdownMenuItem
                            disabled={!e.selected.length}
                            onSelect={() => onNumbering('quick')}
                        >
                            <Hash />
                            Numerazione rapida
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => onNumbering('click')}>
                            <MousePointer2 />
                            Numera con clic
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                </DropdownMenuContent>
            </DropdownMenu>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <IconButton
                        label="Azioni selezione"
                        variant="ghost"
                        disabled={blocked || e.loading}
                    >
                        <MoreHorizontal />
                    </IconButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    <DropdownMenuGroup>
                        <DropdownMenuItem
                            onSelect={() => {
                                e.setSelection({
                                    kind: 'ports',
                                    ids: e.ports
                                        .filter((p) => !p.hidden)
                                        .map((p) => p.id),
                                });
                            }}
                            disabled={!e.ports.length}
                        >
                            Seleziona tutte le porte
                            <DropdownMenuShortcut>
                                ⌘/Ctrl A
                            </DropdownMenuShortcut>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            disabled={!e.selected.length}
                            onSelect={e.duplicate}
                        >
                            <Copy />
                            Duplica
                            <DropdownMenuShortcut>
                                ⌘/Ctrl D
                            </DropdownMenuShortcut>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            disabled={
                                !e.selected.length ||
                                e.selected.some(e.portLocked)
                            }
                            onSelect={e.rotate}
                        >
                            <RotateCw />
                            Ruota di 90°
                        </DropdownMenuItem>
                        {e.group ? (
                            <DropdownMenuItem onSelect={e.ungroup}>
                                <Ungroup />
                                Separa gruppo
                            </DropdownMenuItem>
                        ) : (
                            <DropdownMenuItem
                                disabled={e.selected.length < 2}
                                onSelect={e.groupPorts}
                            >
                                <Group />
                                Raggruppa
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuSub>
                            <DropdownMenuSubTrigger
                                disabled={e.selected.length < 2}
                            >
                                <Scan />
                                Allinea e distribuisci
                            </DropdownMenuSubTrigger>
                            <DropdownMenuSubContent>
                                <DropdownMenuGroup>
                                    {alignmentActions.map((action) => (
                                        <DropdownMenuItem
                                            key={action.value}
                                            disabled={
                                                !e.canArrange(action.value)
                                            }
                                            onSelect={() =>
                                                e.arrange(action.value)
                                            }
                                        >
                                            <action.icon />
                                            {action.title}
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuGroup>
                            </DropdownMenuSubContent>
                        </DropdownMenuSub>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            disabled={
                                !e.selected.length &&
                                e.selection.kind !== 'image'
                            }
                            variant="destructive"
                            onSelect={e.remove}
                        >
                            <Trash2 />
                            Elimina selezione
                            <DropdownMenuShortcut>Canc</DropdownMenuShortcut>
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                </DropdownMenuContent>
            </DropdownMenu>
            <div className="ml-auto flex items-center gap-2">
                <ToggleGroup
                    type="multiple"
                    variant="outline"
                    value={snap ? ['snap'] : []}
                    onValueChange={(value) => setSnap(value.includes('snap'))}
                >
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <ToggleGroupItem
                                value="snap"
                                aria-label="Guide magnetiche"
                                className="size-10"
                            >
                                <Magnet />
                            </ToggleGroupItem>
                        </TooltipTrigger>
                        <TooltipContent>
                            Guide magnetiche {snap ? 'attive' : 'disattivate'}
                        </TooltipContent>
                    </Tooltip>
                </ToggleGroup>
                <Button
                    variant={layers ? 'secondary' : 'ghost'}
                    aria-pressed={layers}
                    aria-expanded={layers}
                    onClick={() => setLayers(!layers)}
                >
                    <Layers />
                    <span className="hidden sm:inline">Livelli</span>
                </Button>
                <IconButton
                    aria-pressed={inspector}
                    label="Proprietà"
                    variant={inspector ? 'secondary' : 'ghost'}
                    onClick={() => setInspector(!inspector)}
                >
                    <PanelRight />
                </IconButton>
            </div>
        </div>
    );
}
