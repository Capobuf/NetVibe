import { useId, useRef, useState } from 'react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    ChevronDown,
    Plus,
    Trash2,
} from 'lucide-react';
import {
    Choice,
    NumberField,
} from '@/components/hardware-editor/editor-fields';
import { IconButton } from '@/components/icon-button';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { LabelLengthSettings, LabelPhysicalSettings } from './label-settings';
import {
    endpointFields,
    labelMacros,
    labelCompositions,
    labelPreset,
    type LabelBlock,
    type LabelRow,
    type LabelSettings,
    type LabelWidth,
} from '@/lib/cable-labels';

function MacroText({
    label,
    value,
    onChange,
    endpoint = false,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    endpoint?: boolean;
}) {
    const id = useId();
    const input = useRef<HTMLTextAreaElement>(null);
    return (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Textarea
                ref={input}
                id={id}
                value={value}
                maxLength={500}
                required
                rows={2}
                onChange={(event) => onChange(event.target.value)}
            />
            <Select
                value=""
                onValueChange={(macro) => {
                    const element = input.current;
                    const start = element?.selectionStart ?? value.length;
                    const end = element?.selectionEnd ?? start;
                    const insertion = `{{${macro}}}`;
                    onChange(
                        value.slice(0, start) + insertion + value.slice(end),
                    );
                    requestAnimationFrame(() => {
                        element?.focus();
                        element?.setSelectionRange(
                            start + insertion.length,
                            start + insertion.length,
                        );
                    });
                }}
            >
                <SelectTrigger
                    className="w-full"
                    aria-label={`Inserisci segnaposto in ${label}`}
                >
                    <SelectValue placeholder="Inserisci segnaposto…" />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        {Object.entries(
                            endpoint ? endpointFields : labelMacros,
                        ).map(([macro, description]) => (
                            <SelectItem key={macro} value={macro}>
                                {description} · {`{{${macro}}}`}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                </SelectContent>
            </Select>
        </Field>
    );
}
function RowsEditor({
    rows,
    onChange,
    width,
    advanced = false,
}: {
    rows: LabelRow[];
    advanced?: boolean;
    onChange: (rows: LabelRow[]) => void;
    width: LabelWidth;
}) {
    const id = useId();
    function changeRow(index: number, row: LabelRow) {
        onChange(rows.map((current, i) => (i === index ? row : current)));
    }
    function moveRow(index: number, direction: number) {
        const copy = [...rows];
        [copy[index], copy[index + direction]] = [
            copy[index + direction],
            copy[index],
        ];
        onChange(copy);
    }
    function blockChange(rowIndex: number, index: number, block: LabelBlock) {
        const row = rows[rowIndex];
        changeRow(rowIndex, {
            ...row,
            blocks: row.blocks.map((current, i) =>
                i === index ? block : current,
            ),
        });
    }
    return (
        <div className="flex flex-col gap-4">
            {rows.map((row, rowIndex) => (
                <section
                    key={rowIndex}
                    className="flex flex-col gap-4 border-b pb-4 last:border-0 last:pb-0"
                >
                    {advanced && rows.length > 1 && (
                        <div className="flex items-center justify-between gap-2">
                            <h3 className="text-sm font-medium">
                                Riga {rowIndex + 1}
                            </h3>
                            <div className="flex gap-1">
                                <IconButton
                                    label="Sposta riga sopra"
                                    disabled={rowIndex === 0}
                                    onClick={() => moveRow(rowIndex, -1)}
                                >
                                    <ArrowUp />
                                </IconButton>
                                <IconButton
                                    label="Sposta riga sotto"
                                    disabled={rowIndex === rows.length - 1}
                                    onClick={() => moveRow(rowIndex, 1)}
                                >
                                    <ArrowDown />
                                </IconButton>
                                <IconButton
                                    label="Elimina riga"
                                    disabled={rows.length === 1}
                                    onClick={() =>
                                        onChange(
                                            rows.filter(
                                                (_, i) => i !== rowIndex,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </IconButton>
                            </div>
                        </div>
                    )}
                    {row.blocks.map((block, index) => (
                        <FieldGroup key={index} className="gap-3">
                            {advanced && row.blocks.length > 1 && (
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-xs text-muted-foreground">
                                        {row.blocks.length === 1
                                            ? 'Larghezza intera'
                                            : index === 0
                                              ? 'Sinistra'
                                              : index === row.blocks.length - 1
                                                ? 'Destra'
                                                : 'Centro'}
                                    </span>
                                    <div className="flex gap-1">
                                        <IconButton
                                            label="Sposta blocco a sinistra"
                                            disabled={index === 0}
                                            onClick={() => {
                                                const blocks = [...row.blocks];
                                                [
                                                    blocks[index],
                                                    blocks[index - 1],
                                                ] = [
                                                    blocks[index - 1],
                                                    blocks[index],
                                                ];
                                                changeRow(rowIndex, {
                                                    ...row,
                                                    blocks,
                                                });
                                            }}
                                        >
                                            <ArrowLeft />
                                        </IconButton>
                                        <IconButton
                                            label="Sposta blocco a destra"
                                            disabled={
                                                index === row.blocks.length - 1
                                            }
                                            onClick={() => {
                                                const blocks = [...row.blocks];
                                                [
                                                    blocks[index],
                                                    blocks[index + 1],
                                                ] = [
                                                    blocks[index + 1],
                                                    blocks[index],
                                                ];
                                                changeRow(rowIndex, {
                                                    ...row,
                                                    blocks,
                                                });
                                            }}
                                        >
                                            <ArrowRight />
                                        </IconButton>
                                        <IconButton
                                            label="Elimina blocco"
                                            disabled={row.blocks.length === 1}
                                            onClick={() =>
                                                changeRow(rowIndex, {
                                                    ...row,
                                                    blocks: row.blocks.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                })
                                            }
                                        >
                                            <Trash2 />
                                        </IconButton>
                                    </div>
                                </div>
                            )}
                            <MacroText
                                label={
                                    rows.length === 1 && row.blocks.length === 1
                                        ? 'Testo'
                                        : `Testo ${rowIndex + 1}.${index + 1}`
                                }
                                value={block.text}
                                onChange={(text) =>
                                    blockChange(rowIndex, index, {
                                        ...block,
                                        text,
                                    })
                                }
                            />
                            <FieldGroup className="grid grid-cols-2 gap-3">
                                <NumberField
                                    live
                                    label="Carattere (mm)"
                                    value={block.size}
                                    min={2}
                                    max={6}
                                    step={0.1}
                                    onChange={(size) =>
                                        blockChange(rowIndex, index, {
                                            ...block,
                                            size,
                                        })
                                    }
                                />
                                <Choice
                                    label="Allineamento"
                                    value={block.align}
                                    choices={{
                                        left: 'Sinistra',
                                        center: 'Centro',
                                        right: 'Destra',
                                    }}
                                    onChange={(align) =>
                                        blockChange(rowIndex, index, {
                                            ...block,
                                            align: align as LabelBlock['align'],
                                        })
                                    }
                                />
                            </FieldGroup>
                        </FieldGroup>
                    ))}
                    <div className="flex flex-wrap items-end gap-3">
                        {advanced && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={row.blocks.length === 3}
                                onClick={() =>
                                    changeRow(rowIndex, {
                                        ...row,
                                        blocks: [
                                            ...row.blocks,
                                            {
                                                text: '{{nome_cavo}}',
                                                size: row.blocks[0].size,
                                                align: 'center',
                                            },
                                        ],
                                    })
                                }
                            >
                                <Plus />
                                Blocco
                            </Button>
                        )}
                        {row.blocks.length > 1 && (
                            <Field className="max-w-48">
                                <FieldLabel htmlFor={`${id}-${rowIndex}`}>
                                    Separatore
                                </FieldLabel>
                                <Input
                                    id={`${id}-${rowIndex}`}
                                    maxLength={20}
                                    value={row.separator ?? ''}
                                    onChange={(event) =>
                                        changeRow(rowIndex, {
                                            ...row,
                                            separator: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                        )}
                    </div>
                </section>
            ))}
            {advanced && (
                <Button
                    type="button"
                    variant="outline"
                    disabled={rows.length === 4}
                    onClick={() => onChange([...rows, labelPreset(width)[0]])}
                >
                    <Plus />
                    Aggiungi riga
                </Button>
            )}
        </div>
    );
}
export function CompositionPicker({
    value,
    onChange,
}: {
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <ToggleGroup
            type="single"
            variant="outline"
            value={value}
            onValueChange={(value) => {
                if (value) onChange(value);
            }}
            aria-label="Composizione iniziale"
            className="grid w-full grid-cols-1 gap-2 sm:grid-cols-2"
        >
            {Object.entries(labelCompositions).map(([key, composition]) => (
                <ToggleGroupItem
                    key={key}
                    value={key}
                    className="h-auto min-h-20 min-w-0 flex-col items-start gap-2 rounded-md border p-3 text-left whitespace-normal"
                >
                    <span className="text-sm font-medium">
                        {composition.name}
                    </span>
                    <span className="text-xs whitespace-pre-line">
                        {composition.example}
                    </span>
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

export function LabelEditor({
    settings,
    width,
    faceLength,
    onChange,
}: {
    settings: LabelSettings;
    width: LabelWidth;
    faceLength: number;
    onChange: (settings: LabelSettings) => void;
}) {
    const [face, setFace] = useState('front');
    const [customizing, setCustomizing] = useState(false);
    const customizationId = useId();
    const compositionId = useId();
    const composition =
        Object.keys(labelCompositions).find((key) => {
            const preset = labelPreset(width, key);
            return (
                preset.length === settings.front.length &&
                preset.every(
                    (row, index) =>
                        row.separator === settings.front[index].separator &&
                        row.blocks.length ===
                            settings.front[index].blocks.length &&
                        row.blocks.every(
                            (block, blockIndex) =>
                                block.text ===
                                settings.front[index].blocks[blockIndex].text,
                        ),
                )
            );
        }) ?? 'custom';
    const blocks = settings.front.flatMap((row) => row.blocks);
    const uniformStyle = blocks.every(
        (block) =>
            block.size === blocks[0].size && block.align === blocks[0].align,
    );
    const changeStyle = (style: Partial<Pick<LabelBlock, 'size' | 'align'>>) =>
        onChange({
            ...settings,
            front: settings.front.map((row) => ({
                ...row,
                blocks: row.blocks.map((block) => ({ ...block, ...style })),
            })),
        });
    return (
        <Tabs defaultValue="content" className="gap-6">
            <TabsList
                aria-label="Impostazioni profilo"
                className="grid w-full grid-cols-3"
            >
                <TabsTrigger value="content">Testo</TabsTrigger>
                <TabsTrigger value="dimensions">Nastro</TabsTrigger>
                <TabsTrigger value="cable">Cavo</TabsTrigger>
            </TabsList>
            <TabsContent value="content" className="flex flex-col gap-6">
                <Field>
                    <FieldLabel htmlFor={compositionId}>
                        Composizione
                    </FieldLabel>
                    <Select
                        value={composition}
                        onValueChange={(preset) =>
                            onChange({
                                ...settings,
                                front: labelPreset(width, preset),
                            })
                        }
                    >
                        <SelectTrigger id={compositionId} className="w-full">
                            <SelectValue>
                                {composition === 'custom'
                                    ? 'Personalizzata'
                                    : labelCompositions[
                                          composition as keyof typeof labelCompositions
                                      ].name}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                {composition === 'custom' && (
                                    <SelectItem value="custom" disabled>
                                        Personalizzata
                                    </SelectItem>
                                )}
                                {Object.entries(labelCompositions).map(
                                    ([key, item]) => (
                                        <SelectItem key={key} value={key}>
                                            <span className="flex flex-col gap-1 py-1">
                                                <span>{item.name}</span>
                                                <span className="text-xs whitespace-pre-line text-muted-foreground">
                                                    {item.example}
                                                </span>
                                            </span>
                                        </SelectItem>
                                    ),
                                )}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </Field>
                {!customizing &&
                    (uniformStyle ? (
                        <FieldGroup className="grid grid-cols-2 gap-3">
                            <NumberField
                                live
                                label="Carattere (mm)"
                                value={blocks[0].size}
                                min={2}
                                max={6}
                                step={0.1}
                                onChange={(size) => changeStyle({ size })}
                            />
                            <Choice
                                label="Allineamento"
                                value={blocks[0].align}
                                choices={{
                                    left: 'Sinistra',
                                    center: 'Centro',
                                    right: 'Destra',
                                }}
                                onChange={(align) =>
                                    changeStyle({
                                        align: align as LabelBlock['align'],
                                    })
                                }
                            />
                        </FieldGroup>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Stili diversi per i blocchi
                        </p>
                    ))}
                <Button
                    type="button"
                    variant="outline"
                    className="w-full justify-between"
                    aria-expanded={customizing}
                    aria-controls={customizationId}
                    onClick={() => setCustomizing(!customizing)}
                >
                    {customizing
                        ? 'Chiudi personalizzazione'
                        : 'Personalizza testo e facce'}
                    <ChevronDown
                        className={customizing ? 'rotate-180' : undefined}
                    />
                </Button>
                {customizing && (
                    <div
                        id={customizationId}
                        className="flex flex-col gap-5 border-t pt-5"
                    >
                        <Choice
                            label="Seconda faccia della bandiera"
                            value={settings.back_mode}
                            choices={{
                                repeat: 'Ripeti sulla seconda faccia',
                                opposite: 'Ripeti con estremità opposta',
                                custom: 'Personalizza seconda faccia',
                            }}
                            onChange={(back_mode) => {
                                onChange({
                                    ...settings,
                                    back_mode:
                                        back_mode as LabelSettings['back_mode'],
                                });
                                setFace('front');
                            }}
                        />
                        <Tabs
                            value={face}
                            onValueChange={setFace}
                            className="gap-4"
                        >
                            <TabsList aria-label="Testo della bandiera">
                                <TabsTrigger value="front">
                                    Faccia 1
                                </TabsTrigger>
                                <TabsTrigger
                                    value="back"
                                    disabled={settings.back_mode !== 'custom'}
                                >
                                    Faccia 2
                                </TabsTrigger>
                            </TabsList>
                            {(['front', 'back'] as const).map((side) => (
                                <TabsContent key={side} value={side}>
                                    <RowsEditor
                                        rows={settings[side]}
                                        width={width}
                                        advanced
                                        onChange={(rows) =>
                                            onChange({
                                                ...settings,
                                                [side]: rows,
                                            })
                                        }
                                    />
                                </TabsContent>
                            ))}
                        </Tabs>
                        {[
                            ...settings.front,
                            ...(settings.back_mode === 'custom'
                                ? settings.back
                                : []),
                        ].some((row) =>
                            row.blocks.some((block) =>
                                /\{\{estremita(?:_[ab])?\}\}/.test(block.text),
                            ),
                        ) && (
                            <MacroText
                                endpoint
                                label="Formato dei collegamenti"
                                value={settings.endpoint_format}
                                onChange={(endpoint_format) =>
                                    onChange({ ...settings, endpoint_format })
                                }
                            />
                        )}
                        <Choice
                            label="Orientamento seconda faccia"
                            value={String(settings.back_rotation)}
                            choices={{
                                '0': 'Stesso verso',
                                '180': 'Ruotata di 180°',
                            }}
                            onChange={(rotation) =>
                                onChange({
                                    ...settings,
                                    back_rotation: Number(rotation) as 0 | 180,
                                })
                            }
                        />
                    </div>
                )}
            </TabsContent>
            <TabsContent value="dimensions" className="flex flex-col gap-6">
                <LabelLengthSettings
                    settings={settings}
                    faceLength={faceLength}
                    onChange={onChange}
                />
            </TabsContent>
            <TabsContent value="cable">
                <LabelPhysicalSettings
                    settings={settings}
                    onChange={onChange}
                />
            </TabsContent>
        </Tabs>
    );
}
