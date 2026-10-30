import {
    Copy,
    ClipboardPaste,
    Scissors,
    Crop,
    Layers,
    Undo2,
    Group,
    ImagePlus,
    Lock,
    Maximize,
    Pencil,
    Plus,
    RotateCw,
    Scan,
    Settings2,
    Trash2,
    Ungroup,
    Unlock,
} from 'lucide-react';
import {
    ContextMenuContent,
    ContextMenuGroup,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuShortcut,
    ContextMenuSub,
    ContextMenuSubContent,
    ContextMenuSubTrigger,
} from '@/components/ui/context-menu';
import {
    insertableConnectors,
    type ConnectorType,
} from '@/lib/hardware-editor/document';
import { ConnectorIcon } from './connector-icon';
import { orderActions } from './editor-layers';
import { alignmentActions } from './editor-inspector';
import type { Editor } from './use-editor';

export function EditorContextMenu({
    editor: e,
    add,
    paste,
    properties,
    label,
    image,
    fit,
}: {
    editor: Editor;
    add: (type: ConnectorType) => void;
    paste: () => void;
    properties: () => void;
    label: () => void;
    image: () => void;
    fit: () => void;
}) {
    const picture = e.doc.faces[e.face].image;
    return (
        <ContextMenuContent
            className="w-64"
            onKeyDown={(event) => event.stopPropagation()}
        >
            <ContextMenuGroup>
                {e.selection.kind === 'ports' && e.selected.length > 0 && (
                    <>
                        <ContextMenuItem onClick={e.copy}>
                            <Copy />
                            Copia
                            <ContextMenuShortcut>⌘/Ctrl C</ContextMenuShortcut>
                        </ContextMenuItem>
                        <ContextMenuItem
                            disabled={e.selected.some(e.portLocked)}
                            onClick={e.cut}
                        >
                            <Scissors />
                            Taglia
                            <ContextMenuShortcut>⌘/Ctrl X</ContextMenuShortcut>
                        </ContextMenuItem>
                    </>
                )}
                <ContextMenuItem disabled={!e.canPaste} onClick={paste}>
                    <ClipboardPaste />
                    Incolla<ContextMenuShortcut>⌘/Ctrl V</ContextMenuShortcut>
                </ContextMenuItem>
                <ContextMenuSeparator />
                {(e.selection.kind === 'ports' ||
                    e.selection.kind === 'image') && (
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
                                        disabled={!e.canReorder(action.value)}
                                        onClick={() => e.reorder(action.value)}
                                    >
                                        <action.icon />
                                        {action.label}
                                    </ContextMenuItem>
                                ))}
                            </ContextMenuGroup>
                        </ContextMenuSubContent>
                    </ContextMenuSub>
                )}
                {e.selection.kind === 'ports' && e.selected.length ? (
                    <>
                        {e.selected.length === 1 && (
                            <ContextMenuItem onClick={label}>
                                <Pencil />
                                Modifica etichetta / numero
                            </ContextMenuItem>
                        )}
                        {e.group && (
                            <ContextMenuItem
                                onClick={() => {
                                    e.setEnteredGroup(e.group);
                                    e.setSelection({
                                        kind: 'ports',
                                        ids: [e.selected[0].id],
                                    });
                                    properties();
                                }}
                            >
                                <Scan />
                                Entra nel gruppo
                            </ContextMenuItem>
                        )}
                        <ContextMenuSeparator />
                        <ContextMenuItem onClick={e.duplicate}>
                            <Copy />
                            Duplica
                            <ContextMenuShortcut>⌘/Ctrl D</ContextMenuShortcut>
                        </ContextMenuItem>
                        <ContextMenuItem
                            disabled={e.selected.some(e.portLocked)}
                            onClick={() => e.rotate()}
                        >
                            <RotateCw />
                            Ruota di 90°
                        </ContextMenuItem>
                        <ContextMenuItem
                            disabled={e.selected.some(e.portLocked)}
                            onClick={() => e.rotate(180)}
                        >
                            <RotateCw />
                            Ruota di 180°
                        </ContextMenuItem>
                        {e.selected.length > 1 && (
                            <ContextMenuSub>
                                <ContextMenuSubTrigger>
                                    <Scan />
                                    Allinea e distribuisci
                                </ContextMenuSubTrigger>
                                <ContextMenuSubContent>
                                    <ContextMenuGroup>
                                        {alignmentActions.map((action) => (
                                            <ContextMenuItem
                                                key={action.value}
                                                disabled={
                                                    !e.canArrange(action.value)
                                                }
                                                onClick={() =>
                                                    e.arrange(action.value)
                                                }
                                            >
                                                <action.icon />
                                                {action.title}
                                            </ContextMenuItem>
                                        ))}
                                    </ContextMenuGroup>
                                </ContextMenuSubContent>
                            </ContextMenuSub>
                        )}
                        {e.group ? (
                            <ContextMenuItem onClick={e.ungroup}>
                                <Ungroup />
                                Separa gruppo
                            </ContextMenuItem>
                        ) : e.selected.length > 1 ? (
                            <ContextMenuItem onClick={() => e.groupPorts()}>
                                <Group />
                                Raggruppa
                            </ContextMenuItem>
                        ) : null}
                        <ContextMenuSeparator />
                        <ContextMenuItem onClick={properties}>
                            <Settings2 />
                            Proprietà
                        </ContextMenuItem>
                        <ContextMenuItem
                            variant="destructive"
                            onClick={e.remove}
                        >
                            <Trash2 />
                            {e.group
                                ? 'Elimina gruppo'
                                : e.selected.length === 1
                                  ? 'Elimina porta'
                                  : 'Elimina selezione'}
                            <ContextMenuShortcut>Canc</ContextMenuShortcut>
                        </ContextMenuItem>
                    </>
                ) : e.selection.kind === 'image' && picture ? (
                    <>
                        <ContextMenuItem
                            disabled={picture.locked || picture.hidden}
                            onClick={e.startCrop}
                        >
                            <Crop />
                            Ritaglia immagine
                        </ContextMenuItem>
                        {e.canResetCrop && (
                            <ContextMenuItem
                                disabled={picture.locked}
                                onClick={e.resetCrop}
                            >
                                <Undo2 />
                                Ripristina immagine completa
                            </ContextMenuItem>
                        )}
                        <ContextMenuItem
                            onClick={() =>
                                e.patchImage({ locked: !picture.locked })
                            }
                        >
                            {picture.locked ? <Unlock /> : <Lock />}
                            {picture.locked
                                ? 'Sblocca immagine'
                                : 'Blocca immagine'}
                        </ContextMenuItem>
                        <ContextMenuItem
                            disabled={picture.locked}
                            onClick={e.fitImage}
                        >
                            <Maximize />
                            Adatta alla scocca
                        </ContextMenuItem>
                        <ContextMenuItem onClick={image}>
                            <ImagePlus />
                            Sostituisci immagine
                        </ContextMenuItem>
                        <ContextMenuSeparator />
                        <ContextMenuItem onClick={properties}>
                            <Settings2 />
                            Proprietà
                        </ContextMenuItem>
                        <ContextMenuItem
                            variant="destructive"
                            onClick={e.remove}
                        >
                            <Trash2 />
                            Rimuovi immagine
                        </ContextMenuItem>
                    </>
                ) : e.selection.kind === 'shell' ? (
                    <>
                        <ContextMenuItem
                            onClick={() =>
                                e.patchShellState({
                                    locked: !e.shellState.locked,
                                })
                            }
                        >
                            {e.shellState.locked ? <Unlock /> : <Lock />}
                            {e.shellState.locked
                                ? 'Sblocca scocca'
                                : 'Blocca scocca'}
                        </ContextMenuItem>
                        <ContextMenuItem onClick={image}>
                            <ImagePlus />
                            Importa immagine
                        </ContextMenuItem>
                        <ContextMenuSeparator />
                        <ContextMenuItem onClick={properties}>
                            <Settings2 />
                            Proprietà
                        </ContextMenuItem>
                    </>
                ) : (
                    <>
                        <ContextMenuSub>
                            <ContextMenuSubTrigger>
                                <Plus />
                                Aggiungi porta qui
                            </ContextMenuSubTrigger>
                            <ContextMenuSubContent>
                                <ContextMenuGroup>
                                    {insertableConnectors.map(
                                        ([type, item]) => (
                                            <ContextMenuItem
                                                key={type}
                                                onClick={() =>
                                                    add(type as ConnectorType)
                                                }
                                            >
                                                <ConnectorIcon type={type} />
                                                {item.name}
                                            </ContextMenuItem>
                                        ),
                                    )}
                                </ContextMenuGroup>
                            </ContextMenuSubContent>
                        </ContextMenuSub>
                        <ContextMenuItem onClick={fit}>
                            <Maximize />
                            Adatta alla vista
                        </ContextMenuItem>
                    </>
                )}
            </ContextMenuGroup>
        </ContextMenuContent>
    );
}
