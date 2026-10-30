import {
    Copy,
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
    connectorTypes,
    type ConnectorType,
} from '@/lib/hardware-editor/document';
import { orderActions } from './editor-layers';
import { alignmentActions } from './editor-inspector';
import type { Editor } from './use-editor';

export function EditorContextMenu({
    editor: e,
    add,
    properties,
    label,
    image,
    fit,
}: {
    editor: Editor;
    add: (type: ConnectorType) => void;
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
                        <ContextMenuItem onClick={properties}>
                            <Settings2 />
                            Proprietà
                        </ContextMenuItem>
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
                            onClick={e.rotate}
                        >
                            <RotateCw />
                            Ruota di 90°
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
                            <ContextMenuItem onClick={e.groupPorts}>
                                <Group />
                                Raggruppa
                            </ContextMenuItem>
                        ) : null}
                        <ContextMenuSeparator />
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
                        <ContextMenuItem onClick={properties}>
                            <Settings2 />
                            Modifica immagine
                        </ContextMenuItem>
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
                        <ContextMenuItem onClick={properties}>
                            <Settings2 />
                            Dimensioni e bordi
                        </ContextMenuItem>
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
                                    {Object.entries(connectorTypes).map(
                                        ([type, item]) => (
                                            <ContextMenuItem
                                                key={type}
                                                onClick={() =>
                                                    add(type as ConnectorType)
                                                }
                                            >
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
