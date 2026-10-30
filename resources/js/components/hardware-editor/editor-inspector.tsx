import {
    Copy,
    ChevronDown,
    Crop,
    Undo2,
    Group,
    ImagePlus,
    Lock,
    Maximize,
    Pencil,
    Pipette,
    RotateCw,
    Scan,
    Trash2,
    Ungroup,
    Unlock,
    X,
    AlignHorizontalDistributeCenter,
    AlignVerticalDistributeCenter,
    AlignStartVertical,
    AlignCenterVertical,
    AlignEndVertical,
    AlignStartHorizontal,
    AlignCenterHorizontal,
    AlignEndHorizontal,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { IconButton } from '@/components/icon-button';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Collapsible,
    CollapsibleTrigger,
    CollapsibleContent,
} from '@/components/ui/collapsible';
import { Slider } from '@/components/ui/slider';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { LayerOrderMenu } from './editor-layers';
import { Separator } from '@/components/ui/separator';
import {
    connectorTypes,
    labelPositions,
    type Port,
} from '@/lib/hardware-editor/document';
import { NumberField, Choice } from './editor-fields';
import type { Editor } from './use-editor';

export const alignmentActions = [
    { value: 'left', title: 'Allinea a sinistra', icon: AlignStartVertical },
    {
        value: 'center-x',
        title: 'Centra orizzontalmente',
        icon: AlignCenterVertical,
    },
    { value: 'right', title: 'Allinea a destra', icon: AlignEndVertical },
    { value: 'top', title: 'Allinea in alto', icon: AlignStartHorizontal },
    {
        value: 'center-y',
        title: 'Centra verticalmente',
        icon: AlignCenterHorizontal,
    },
    { value: 'bottom', title: 'Allinea in basso', icon: AlignEndHorizontal },
    {
        value: 'distribute-x',
        title: 'Distribuisci orizzontalmente',
        icon: AlignHorizontalDistributeCenter,
    },
    {
        value: 'distribute-y',
        title: 'Distribuisci verticalmente',
        icon: AlignVerticalDistributeCenter,
    },
];
export function EditorInspector({
    editor: e,
    onImage,
    onLabel,
    onClose,
}: {
    editor: Editor;
    onImage: () => void;
    onLabel: () => void;
    onClose: () => void;
}) {
    const [gap, setGap] = useState(16);
    const single = e.selected.length === 1 ? e.selected[0] : null;
    const image = e.doc.faces[e.face].image;
    const title =
        e.selection.kind === 'image'
            ? 'Immagine'
            : e.selection.kind === 'shell'
              ? 'Scocca'
              : e.selected.length
                ? e.group
                    ? 'Gruppo di porte'
                    : single
                      ? connectorTypes[single.type].name
                      : `${e.selected.length} porte`
                : 'Pannello';
    const common = <K extends keyof Port>(key: K) =>
        e.selected.every((p) => p[key] === e.selected[0]?.[key])
            ? e.selected[0]?.[key]
            : undefined;
    return (
        <div className="flex h-full flex-col">
            <div className="flex items-center justify-between gap-2 border-b px-4 py-3">
                <h2 className="min-w-0 flex-1 truncate text-sm font-semibold">
                    {title}
                </h2>
                {(e.selection.kind === 'ports' ||
                    e.selection.kind === 'image') && (
                    <LayerOrderMenu editor={e} />
                )}
                <IconButton
                    label="Chiudi proprietà"
                    variant="ghost"
                    onClick={onClose}
                >
                    <X />
                </IconButton>
            </div>
            <div className="flex flex-1 flex-col gap-5 overflow-y-auto p-4">
                {e.selection.kind === 'ports' && e.selected.length ? (
                    <>
                        {single && (
                            <FieldGroup>
                                <Field className="gap-1.5">
                                    <FieldLabel htmlFor="port-label">
                                        Etichetta / numero
                                    </FieldLabel>
                                    <div className="flex gap-2">
                                        <Input
                                            id="port-label"
                                            value={
                                                single.label ||
                                                'Senza etichetta'
                                            }
                                            readOnly
                                            onClick={onLabel}
                                        />
                                        <IconButton
                                            label="Modifica etichetta"
                                            onClick={onLabel}
                                        >
                                            <Pencil />
                                        </IconButton>
                                    </div>
                                </Field>
                            </FieldGroup>
                        )}
                        <FieldGroup>
                            <div>
                                <Choice
                                    disabled={e.selected.some(e.portLocked)}
                                    label="Orientamento"
                                    value={common('rotation')?.toString() ?? ''}
                                    choices={{
                                        0: '0°',
                                        90: '90°',
                                        180: '180°',
                                        270: '270°',
                                    }}
                                    onChange={(rotation) =>
                                        e.patchPorts({
                                            rotation: Number(rotation),
                                        })
                                    }
                                />
                            </div>
                            <Choice
                                label="Posizione etichetta"
                                value={common('labelPosition') ?? ''}
                                choices={labelPositions}
                                onChange={(labelPosition) =>
                                    e.patchPorts({
                                        labelPosition:
                                            labelPosition as Port['labelPosition'],
                                    })
                                }
                            />
                            {e.selected.some((p) => p.type === 'rj45') && (
                                <Field orientation="horizontal">
                                    <Checkbox
                                        id="port-poe"
                                        checked={
                                            e.selected
                                                .filter(
                                                    (p) => p.type === 'rj45',
                                                )
                                                .every((p) => p.poe)
                                                ? true
                                                : e.selected.some((p) => p.poe)
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        onCheckedChange={(poe) =>
                                            e.patchPorts({ poe: poe === true })
                                        }
                                    />
                                    <FieldLabel htmlFor="port-poe">
                                        Capacità PoE
                                    </FieldLabel>
                                </Field>
                            )}
                        </FieldGroup>
                        <BorderColorField
                            key={e.selection.ids.join(',')}
                            editor={e}
                            value={common('borderColor')}
                        />
                        <OpacityField
                            editor={e}
                            value={
                                e.selected.every(
                                    (p) => p.opacity === e.selected[0].opacity,
                                )
                                    ? e.selected[0].opacity
                                    : undefined
                            }
                            onCommit={(opacity) => e.patchPorts({ opacity })}
                        />
                        <Transformations>
                            <FieldGroup>
                                {single && (
                                    <>
                                        {' '}
                                        <div className="grid grid-cols-2 gap-3">
                                            <NumberField
                                                label="X"
                                                value={single.x}
                                                step={0.1}
                                                disabled={e.selected.some(
                                                    e.portLocked,
                                                )}
                                                onChange={(x) =>
                                                    e.patchPorts({ x })
                                                }
                                            />
                                            <NumberField
                                                label="Y"
                                                value={single.y}
                                                step={0.1}
                                                disabled={e.selected.some(
                                                    e.portLocked,
                                                )}
                                                onChange={(y) =>
                                                    e.patchPorts({ y })
                                                }
                                            />
                                        </div>
                                    </>
                                )}
                                <NumberField
                                    label={
                                        common('scale') === undefined
                                            ? 'Scala % (multipla)'
                                            : 'Scala %'
                                    }
                                    value={(common('scale') ?? 1) * 100}
                                    disabled={e.selected.some(e.portLocked)}
                                    min={20}
                                    max={800}
                                    onChange={(scale) =>
                                        e.patchPorts({ scale: scale / 100 })
                                    }
                                />
                            </FieldGroup>
                        </Transformations>
                        {e.selected.length > 1 && (
                            <>
                                <Separator />
                                <h3 className="text-sm font-medium">
                                    Allineamento
                                </h3>
                                <div className="grid grid-cols-4 gap-1">
                                    {alignmentActions.map((action) => (
                                        <IconButton
                                            key={action.value}
                                            label={action.title}
                                            variant="ghost"
                                            disabled={
                                                !e.canArrange(action.value)
                                            }
                                            onClick={() =>
                                                e.arrange(action.value)
                                            }
                                        >
                                            <action.icon />
                                        </IconButton>
                                    ))}
                                </div>
                                <FieldGroup>
                                    <NumberField
                                        label="Spaziatura"
                                        value={gap}
                                        min={0}
                                        max={1000}
                                        onChange={setGap}
                                    />
                                    <div className="grid grid-cols-2 gap-2">
                                        <Button
                                            variant="outline"
                                            disabled={!e.canArrange('gap-x')}
                                            onClick={() =>
                                                e.arrange('gap-x', gap)
                                            }
                                        >
                                            Tra colonne
                                        </Button>
                                        <Button
                                            variant="outline"
                                            disabled={!e.canArrange('gap-y')}
                                            onClick={() =>
                                                e.arrange('gap-y', gap)
                                            }
                                        >
                                            Tra righe
                                        </Button>
                                    </div>
                                </FieldGroup>
                            </>
                        )}
                        <Separator />
                        <div className="flex flex-col gap-2">
                            {e.group ? (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            e.setEnteredGroup(e.group);
                                            e.setSelection({
                                                kind: 'ports',
                                                ids: [e.selected[0].id],
                                            });
                                        }}
                                    >
                                        <Scan />
                                        Entra nel gruppo
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={e.ungroup}
                                    >
                                        <Ungroup />
                                        Separa gruppo
                                    </Button>
                                </>
                            ) : e.selected.length > 1 ? (
                                <Button
                                    variant="outline"
                                    onClick={e.groupPorts}
                                >
                                    <Group />
                                    Raggruppa
                                </Button>
                            ) : null}
                            {e.enteredGroup && (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        const ids = e.ports
                                            .filter(
                                                (p) =>
                                                    p.group === e.enteredGroup,
                                            )
                                            .map((p) => p.id);
                                        e.setEnteredGroup(null);
                                        e.setSelection({ kind: 'ports', ids });
                                    }}
                                >
                                    Esci dal gruppo
                                </Button>
                            )}
                            <div className="grid grid-cols-2 gap-2">
                                <Button variant="outline" onClick={e.duplicate}>
                                    <Copy />
                                    Duplica
                                </Button>
                                <Button
                                    variant="outline"
                                    disabled={e.selected.some(e.portLocked)}
                                    onClick={e.rotate}
                                >
                                    <RotateCw />
                                    Ruota
                                </Button>
                            </div>
                            <Button variant="destructive" onClick={e.remove}>
                                <Trash2 />
                                {e.group
                                    ? 'Elimina gruppo'
                                    : single
                                      ? 'Elimina porta'
                                      : 'Elimina selezione'}
                            </Button>
                        </div>
                    </>
                ) : e.selection.kind === 'image' && image ? (
                    <>
                        <OpacityField
                            editor={e}
                            value={image.opacity}
                            onCommit={(opacity) => e.patchImage({ opacity })}
                        />
                        <Button
                            variant="outline"
                            disabled={image.locked || image.hidden}
                            onClick={e.startCrop}
                        >
                            <Crop />
                            Ritaglia immagine
                        </Button>
                        {e.canResetCrop && (
                            <Button
                                variant="ghost"
                                disabled={image.locked}
                                onClick={e.resetCrop}
                            >
                                <Undo2 />
                                Ripristina immagine completa
                            </Button>
                        )}
                        <Transformations>
                            <FieldGroup>
                                <div className="grid grid-cols-2 gap-3">
                                    <NumberField
                                        label="X"
                                        value={image.x}
                                        step={0.1}
                                        onChange={(x) => e.patchImage({ x })}
                                        disabled={image.locked}
                                    />
                                    <NumberField
                                        label="Y"
                                        value={image.y}
                                        step={0.1}
                                        onChange={(y) => e.patchImage({ y })}
                                        disabled={image.locked}
                                    />
                                </div>
                                <NumberField
                                    label="Scala %"
                                    value={image.scale * 100}
                                    min={0.1}
                                    max={800}
                                    step={0.1}
                                    disabled={image.locked}
                                    onChange={(scale) =>
                                        e.patchImage({ scale: scale / 100 })
                                    }
                                />
                            </FieldGroup>
                        </Transformations>
                        <FieldGroup>
                            <Field orientation="horizontal">
                                <Checkbox
                                    id="clip-image"
                                    checked={image.clip}
                                    onCheckedChange={(clip) =>
                                        e.patchImage({ clip: clip === true })
                                    }
                                />
                                <FieldLabel htmlFor="clip-image">
                                    Ritaglia alla scocca
                                </FieldLabel>
                            </Field>
                        </FieldGroup>
                        <Separator />
                        <Button
                            variant="outline"
                            onClick={() =>
                                e.patchImage({ locked: !image.locked })
                            }
                        >
                            {image.locked ? <Unlock /> : <Lock />}
                            {image.locked
                                ? 'Sblocca immagine'
                                : 'Blocca immagine'}
                        </Button>
                        <Button
                            variant="outline"
                            onClick={e.fitImage}
                            disabled={image.locked}
                        >
                            <Maximize />
                            Adatta alla scocca
                        </Button>
                        <Button variant="outline" onClick={onImage}>
                            <ImagePlus />
                            Sostituisci immagine
                        </Button>
                        <Button variant="destructive" onClick={e.remove}>
                            <Trash2 />
                            Rimuovi immagine
                        </Button>
                    </>
                ) : (
                    <>
                        <FieldGroup>
                            <Field className="gap-1.5">
                                <FieldLabel htmlFor="panel-name">
                                    Nome pannello
                                </FieldLabel>
                                <Input
                                    id="panel-name"
                                    key={e.doc.name}
                                    defaultValue={e.doc.name}
                                    maxLength={100}
                                    onBlur={(event) => {
                                        const name = event.target.value.trim();
                                        if (name) e.commit({ ...e.doc, name });
                                        else event.target.value = e.doc.name;
                                    }}
                                />
                            </Field>
                            <div className="grid grid-cols-2 gap-3">
                                <NumberField
                                    label="Larghezza"
                                    value={e.doc.shell.width}
                                    min={40}
                                    max={5000}
                                    step={0.1}
                                    disabled={e.shellState.locked}
                                    onChange={(width) =>
                                        e.commit({
                                            ...e.doc,
                                            shell: { ...e.doc.shell, width },
                                        })
                                    }
                                />
                                <NumberField
                                    label="Altezza"
                                    value={e.doc.shell.height}
                                    min={40}
                                    max={5000}
                                    step={0.1}
                                    disabled={e.shellState.locked}
                                    onChange={(height) =>
                                        e.commit({
                                            ...e.doc,
                                            shell: { ...e.doc.shell, height },
                                        })
                                    }
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <NumberField
                                    label="Raggio"
                                    value={e.doc.shell.radius}
                                    min={0}
                                    max={200}
                                    disabled={e.shellState.locked}
                                    onChange={(radius) =>
                                        e.commit({
                                            ...e.doc,
                                            shell: { ...e.doc.shell, radius },
                                        })
                                    }
                                />
                                <NumberField
                                    label="Bordo"
                                    value={e.doc.shell.border}
                                    min={0}
                                    max={12}
                                    step={0.5}
                                    disabled={e.shellState.locked}
                                    onChange={(border) =>
                                        e.commit({
                                            ...e.doc,
                                            shell: { ...e.doc.shell, border },
                                        })
                                    }
                                />
                            </div>
                        </FieldGroup>
                        <Button
                            variant="outline"
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
                        </Button>
                        <Separator />
                        <h3 className="text-sm font-medium">
                            {e.face === 'front' ? 'Frontale' : 'Retro'}
                        </h3>
                        <Button variant="outline" onClick={onImage}>
                            <ImagePlus />
                            {image
                                ? 'Sostituisci immagine'
                                : 'Importa immagine'}
                        </Button>
                        {image && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    e.setSelection({ kind: 'image', ids: [] })
                                }
                            >
                                <Scan />
                                Seleziona immagine
                            </Button>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

function Transformations({ children }: { children: ReactNode }) {
    return (
        <Collapsible className="rounded-lg border">
            <CollapsibleTrigger asChild>
                <Button variant="ghost" className="w-full justify-between">
                    Trasformazioni
                    <ChevronDown />
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent className="p-3 pt-1">
                {children}
            </CollapsibleContent>
        </Collapsible>
    );
}
const borderColors = [
    { name: 'Grigio', color: '#8998aa' },
    { name: 'Bianco', color: '#ffffff' },
    { name: 'Azzurro', color: '#65b9d0' },
    { name: 'Giallo', color: '#facc15' },
    { name: 'Rosso', color: '#ef4444' },
];

function BorderColorField({
    editor: e,
    value,
}: {
    editor: Editor;
    value?: string;
}) {
    const display = e.borderColorPreview ?? value;
    useEffect(
        () => () => e.setBorderColorPreview(null),
        [e.setBorderColorPreview],
    );
    return (
        <Field>
            <div className="flex items-center justify-between gap-2">
                <FieldLabel id="port-border-color-label">
                    Colore bordo
                </FieldLabel>
                <span className="text-xs text-muted-foreground">
                    {display ?? 'Valori diversi'}
                </span>
            </div>
            <div className="flex items-center gap-2">
                <ToggleGroup
                    type="single"
                    size="sm"
                    value={display ?? ''}
                    onValueChange={(borderColor) => {
                        if (borderColor) {
                            e.setBorderColorPreview(null);
                            e.patchPorts({ borderColor });
                        }
                    }}
                    aria-labelledby="port-border-color-label"
                    className="gap-2"
                >
                    {borderColors.map(({ name, color }) => (
                        <ToggleGroupItem
                            key={color}
                            value={color}
                            aria-label={name}
                            className="group size-8 rounded-full p-0 first:rounded-full last:rounded-full"
                        >
                            <span
                                className="size-6 rounded-full border border-foreground/20 ring-foreground ring-offset-background group-data-[state=on]:ring-2 group-data-[state=on]:ring-offset-2"
                                style={{ backgroundColor: color }}
                            />
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
                <div
                    data-selected={
                        !!display &&
                        !borderColors.some(({ color }) => color === display)
                    }
                    className="relative flex size-8 shrink-0 items-center justify-center rounded-full border border-input has-focus-visible:ring-4 has-focus-visible:ring-ring/50 data-[selected=true]:ring-2 data-[selected=true]:ring-foreground data-[selected=true]:ring-offset-2 data-[selected=true]:ring-offset-background"
                >
                    <Pipette className="size-4" aria-hidden="true" />
                    <Input
                        type="color"
                        aria-label="Colore bordo personalizzato"
                        className="absolute inset-0 size-full cursor-pointer rounded-full p-0 opacity-0"
                        value={display ?? e.selected[0].borderColor}
                        onChange={(event) =>
                            e.setBorderColorPreview(event.target.value)
                        }
                        onBlur={() => {
                            if (e.borderColorPreview !== null)
                                e.patchPorts({
                                    borderColor: e.borderColorPreview,
                                });
                            e.setBorderColorPreview(null);
                        }}
                    />
                </div>
            </div>
        </Field>
    );
}
function OpacityField({
    editor: e,
    value,
    onCommit,
}: {
    editor: Editor;
    value?: number;
    onCommit: (opacity: number) => void;
}) {
    const display = e.opacityPreview ?? value;
    useEffect(() => () => e.setOpacityPreview(null), [e.setOpacityPreview]);
    return (
        <Field>
            <div className="flex items-center justify-between gap-2">
                <FieldLabel id="opacity-label">Opacità</FieldLabel>
                <span className="text-xs text-muted-foreground tabular-nums">
                    {display === undefined
                        ? 'Valori diversi'
                        : `${Math.round(display * 100)}%`}
                </span>
            </div>
            <Slider
                aria-labelledby="opacity-label"
                disabled={!!e.crop}
                className="h-6"
                value={[Math.round((display ?? 1) * 100)]}
                min={0}
                max={100}
                step={1}
                onValueChange={([n]) => e.setOpacityPreview(n / 100)}
                onValueCommit={([n]) => {
                    onCommit(n / 100);
                    e.setOpacityPreview(null);
                }}
            />
        </Field>
    );
}
