import { useId, useRef, useState } from 'react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
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
import { Separator } from '@/components/ui/separator';
import { LabelPhysicalSettings } from './label-settings';
import {
    endpointFields,
    labelMacros,
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
}: {
    rows: LabelRow[];
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
                    className="flex flex-col gap-4 rounded-lg border p-4"
                >
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
                                        rows.filter((_, i) => i !== rowIndex),
                                    )
                                }
                            >
                                <Trash2 />
                            </IconButton>
                        </div>
                    </div>
                    {row.blocks.map((block, index) => (
                        <FieldGroup key={index} className="gap-3">
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
                                            [blocks[index], blocks[index - 1]] =
                                                [
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
                                            [blocks[index], blocks[index + 1]] =
                                                [
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
                            <MacroText
                                label={`Testo ${rowIndex + 1}.${index + 1}`}
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
                                    label="Testo (mm)"
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
            <Button
                type="button"
                variant="outline"
                disabled={rows.length === 4}
                onClick={() => onChange([...rows, labelPreset(width)[0]])}
            >
                <Plus />
                Aggiungi riga
            </Button>
        </div>
    );
}
export function LabelEditor({
    settings,
    width,
    onChange,
}: {
    settings: LabelSettings;
    width: LabelWidth;
    onChange: (settings: LabelSettings) => void;
}) {
    const [face, setFace] = useState('front');
    return (
        <Tabs defaultValue="content" className="gap-5">
            <TabsList aria-label="Impostazioni template">
                <TabsTrigger value="content">Contenuto</TabsTrigger>
                <TabsTrigger value="dimensions">Dimensioni</TabsTrigger>
            </TabsList>
            <TabsContent value="dimensions">
                <LabelPhysicalSettings
                    settings={settings}
                    onChange={onChange}
                />
            </TabsContent>
            <TabsContent value="content" className="flex flex-col gap-5">
                <MacroText
                    endpoint
                    label="Formato delle estremità"
                    value={settings.endpoint_format}
                    onChange={(endpoint_format) =>
                        onChange({ ...settings, endpoint_format })
                    }
                />
                <Separator />
                <Choice
                    label="Seconda faccia"
                    value={settings.back_mode}
                    choices={{
                        repeat: 'Ripeti la prima faccia',
                        opposite: 'Prima faccia con estremità opposta',
                        custom: 'Composizione indipendente',
                    }}
                    onChange={(back_mode) => {
                        onChange({
                            ...settings,
                            back_mode: back_mode as LabelSettings['back_mode'],
                        });
                        setFace('front');
                    }}
                />
                <Choice
                    label="Orientamento seconda faccia"
                    value={String(settings.back_rotation)}
                    choices={{ '0': 'Stesso verso', '180': 'Ruotata di 180°' }}
                    onChange={(rotation) =>
                        onChange({
                            ...settings,
                            back_rotation: Number(rotation) as 0 | 180,
                        })
                    }
                />
                <Tabs value={face} onValueChange={setFace} className="gap-4">
                    <TabsList aria-label="Composizione faccia">
                        <TabsTrigger value="front">Faccia 1</TabsTrigger>
                        <TabsTrigger
                            value="back"
                            disabled={settings.back_mode !== 'custom'}
                        >
                            Faccia 2
                        </TabsTrigger>
                    </TabsList>
                    {(['front', 'back'] as const).map((side) => (
                        <TabsContent
                            key={side}
                            value={side}
                            className="flex flex-col gap-4"
                        >
                            <Select
                                value=""
                                onValueChange={(preset) =>
                                    onChange({
                                        ...settings,
                                        [side]: labelPreset(width, preset),
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-full"
                                    aria-label="Applica composizione iniziale"
                                >
                                    <SelectValue placeholder="Applica composizione iniziale…" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectItem value="endpoint">
                                            Estremità corrente
                                        </SelectItem>
                                        <SelectItem value="name">
                                            Solo nome
                                        </SelectItem>
                                        <SelectItem value="links">
                                            Nome con collegamenti sotto
                                        </SelectItem>
                                        <SelectItem value="inline">
                                            A | nome | B
                                        </SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                            <RowsEditor
                                rows={settings[side]}
                                width={width}
                                onChange={(rows) =>
                                    onChange({ ...settings, [side]: rows })
                                }
                            />
                        </TabsContent>
                    ))}
                </Tabs>
            </TabsContent>
        </Tabs>
    );
}
