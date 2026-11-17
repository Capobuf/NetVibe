import {
    ClipboardPaste,
    Copy,
    Crop,
    ExternalLink,
    EyeOff,
    Layers,
    Lock,
    MapPinOff,
    Maximize,
    Plus,
    RectangleHorizontal,
    Ruler,
    Scissors,
    Undo2,
    Unlock,
    Waypoints,
} from 'lucide-react';
import { orderActions } from '@/components/hardware-editor/editor-layers';
import {
    ContextMenuContent,
    ContextMenuGroup,
    ContextMenuItem,
    ContextMenuLabel,
    ContextMenuSeparator,
    ContextMenuShortcut,
    ContextMenuSub,
    ContextMenuSubContent,
    ContextMenuSubTrigger,
} from '@/components/ui/context-menu';
import type { LayerOrderAction } from '@/lib/hardware-editor/document';

/** The element under the pointer; editing actions switch the plan to edit mode. */
export type FloorContextItem = {
    label: string;
    locked: boolean;
    footprint: boolean;
    /** Only devices with a catalog model can be copied into a new device. */
    copyable: boolean;
};

export function FloorContextMenu({
    item,
    canPaste,
    pasteLabel,
    showLinks,
    cropped,
    canRestack,
    onShowLinks,
    onOpen,
    onCopy,
    onCut,
    onPaste,
    onRestack,
    onFootprint,
    onHide,
    onLock,
    onRemove,
    onNewDevice,
    onCrop,
    onResetCrop,
    onScale,
    onFit,
}: {
    item: FloorContextItem | null;
    canPaste: boolean;
    pasteLabel: string;
    showLinks: boolean;
    cropped: boolean;
    canRestack: (action: LayerOrderAction) => boolean;
    onShowLinks: () => void;
    onOpen: () => void;
    onCopy: () => void;
    onCut: () => void;
    onPaste: () => void;
    onRestack: (action: LayerOrderAction) => void;
    onFootprint: () => void;
    onHide: () => void;
    onLock: () => void;
    onRemove: () => void;
    onNewDevice: () => void;
    onCrop: () => void;
    onResetCrop: () => void;
    onScale: () => void;
    onFit: () => void;
}) {
    const paste = (
        <ContextMenuItem disabled={!canPaste} onClick={onPaste}>
            <ClipboardPaste />
            {pasteLabel}
            <ContextMenuShortcut>⌘/Ctrl V</ContextMenuShortcut>
        </ContextMenuItem>
    );
    if (!item)
        return (
            <ContextMenuContent className="w-64">
                <ContextMenuGroup>
                    {paste}
                    <ContextMenuItem onClick={onNewDevice}>
                        <Plus />
                        Nuovo dispositivo qui
                    </ContextMenuItem>
                </ContextMenuGroup>
                <ContextMenuSeparator />
                <ContextMenuGroup>
                    <ContextMenuItem onClick={onCrop}>
                        <Crop />
                        Ritaglia planimetria
                    </ContextMenuItem>
                    {cropped && (
                        <ContextMenuItem onClick={onResetCrop}>
                            <Undo2 />
                            Ripristina planimetria intera
                        </ContextMenuItem>
                    )}
                    <ContextMenuItem onClick={onScale}>
                        <Ruler />
                        Imposta scala
                    </ContextMenuItem>
                </ContextMenuGroup>
                <ContextMenuSeparator />
                <ContextMenuItem onClick={onFit}>
                    <Maximize />
                    Adatta alla vista
                </ContextMenuItem>
            </ContextMenuContent>
        );
    return (
        <ContextMenuContent className="w-64">
            <ContextMenuGroup>
                <ContextMenuLabel className="truncate">
                    {item.label}
                </ContextMenuLabel>
                <ContextMenuItem onClick={onShowLinks}>
                    <Waypoints />
                    {showLinks
                        ? 'Nascondi collegamenti'
                        : 'Mostra collegamenti'}
                </ContextMenuItem>
                <ContextMenuItem onClick={onOpen}>
                    <ExternalLink />
                    Apri scheda
                </ContextMenuItem>
            </ContextMenuGroup>
            <ContextMenuSeparator />
            <ContextMenuGroup>
                <ContextMenuItem disabled={!item.copyable} onClick={onCopy}>
                    <Copy />
                    Copia
                    <ContextMenuShortcut>⌘/Ctrl C</ContextMenuShortcut>
                </ContextMenuItem>
                <ContextMenuItem disabled={item.locked} onClick={onCut}>
                    <Scissors />
                    Taglia
                    <ContextMenuShortcut>⌘/Ctrl X</ContextMenuShortcut>
                </ContextMenuItem>
                {paste}
            </ContextMenuGroup>
            <ContextMenuSeparator />
            <ContextMenuGroup>
                <ContextMenuSub>
                    <ContextMenuSubTrigger>
                        <Layers />
                        Ordine di sovrapposizione
                    </ContextMenuSubTrigger>
                    <ContextMenuSubContent>
                        <ContextMenuGroup>
                            {orderActions.map((action) => (
                                <ContextMenuItem
                                    key={action.value}
                                    disabled={!canRestack(action.value)}
                                    onClick={() => onRestack(action.value)}
                                >
                                    <action.icon />
                                    {action.label}
                                </ContextMenuItem>
                            ))}
                        </ContextMenuGroup>
                    </ContextMenuSubContent>
                </ContextMenuSub>
                <ContextMenuItem disabled={item.locked} onClick={onFootprint}>
                    <RectangleHorizontal />
                    {item.footprint ? 'Rimuovi ingombro' : 'Disegna ingombro'}
                </ContextMenuItem>
                <ContextMenuItem onClick={onHide}>
                    <EyeOff />
                    Nascondi
                </ContextMenuItem>
                <ContextMenuItem onClick={onLock}>
                    {item.locked ? <Unlock /> : <Lock />}
                    {item.locked ? 'Sblocca' : 'Blocca'}
                </ContextMenuItem>
            </ContextMenuGroup>
            <ContextMenuSeparator />
            <ContextMenuItem
                variant="destructive"
                disabled={item.locked}
                onClick={onRemove}
            >
                <MapPinOff />
                Rimuovi dal piano
                <ContextMenuShortcut>Canc</ContextMenuShortcut>
            </ContextMenuItem>
        </ContextMenuContent>
    );
}
