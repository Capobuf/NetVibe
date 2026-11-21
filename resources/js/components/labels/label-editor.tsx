import { useId, useRef, useState, type ReactNode } from 'react';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    Braces,
    ChevronDown,
    MoreHorizontal,
    Plus,
    Trash2,
} from 'lucide-react';
import {
    Choice,
    NumberField,
} from '@/components/hardware-editor/editor-fields';
import { IconButton } from '@/components/icon-button';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
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
import { ErrorDot } from './label-preview';
import { LabelLengthSettings, LabelPhysicalSettings } from './label-settings';
import {
    endpointFields,
    labelCompositions,
    labelPreset,
    type LabelBlock,
    type LabelError,
    type LabelRow,
    type LabelSettings,
    type LabelWidth,
} from '@/lib/cable-labels';

const placeholderGroups = (
    endpoint: boolean,
): { label: string; prefix: string; full?: string }[] =>
    endpoint
        ? [{ label: 'Estremità', prefix: '' }]
        : [
              { label: 'Estremità corrente', prefix: '', full: 'estremita' },
              { label: 'Estremità A', prefix: 'a.', full: 'estremita_a' },
              { label: 'Estremità B', prefix: 'b.', full: 'estremita_b' },
          ];

function MacroText({
    label,
    value,
    onChange,
    endpoint = false,
    errors,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    endpoint?: boolean;
    errors?: LabelError[];
}) {
    const id = useId();
    const input = useRef<HTMLTextAreaElement>(null);
    // Caret to restore once the menu has closed and released focus.
    const caret = useRef<number | null>(null);
    function insert(macro: string) {
        const element = input.current;
        const start = element?.selectionStart ?? value.length;
        const end = element?.selectionEnd ?? start;
        const insertion = `{{${macro}}}`;
        onChange(value.slice(0, start) + insertion + value.slice(end));
        caret.current = start + insertion.length;
    }
    const fields = (prefix: string, full?: string) => (
        <>
            {Object.entries(endpointFields).map(([key, name]) => (
                <DropdownMenuItem
                    key={key}
                    onSelect={() => insert(prefix + key)}
                >
                    {name}
                </DropdownMenuItem>
            ))}
            {full && (
                <DropdownMenuItem onSelect={() => insert(full)}>
                    Collegamento completo
                </DropdownMenuItem>
            )}
        </>
    );
    const [current, ...others] = placeholderGroups(endpoint);
    return (
        <Field className="gap-1.5">
            <div className="flex items-center justify-between gap-2">
                <FieldLabel htmlFor={id}>{label}</FieldLabel>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="-my-1 h-7 px-2 text-muted-foreground"
                            aria-label={`Inserisci segnaposto in ${label}`}
                        >
                            <Braces />
                            Segnaposto
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        onCloseAutoFocus={(event) => {
                            if (caret.current === null) return;
                            event.preventDefault();
                            input.current?.focus();
                            input.current?.setSelectionRange(
                                caret.current,
                                caret.current,
                            );
                            caret.current = null;
                        }}
                    >
                        <DropdownMenuGroup>
                            {!endpoint && (
                                <DropdownMenuLabel>
                                    {current.label}
                                </DropdownMenuLabel>
                            )}
                            {fields(current.prefix, current.full)}
                        </DropdownMenuGroup>
                        {others.length > 0 && (
                            <>
                                <DropdownMenuSeparator />
                                {others.map((group) => (
                                    <DropdownMenuSub key={group.label}>
                                        <DropdownMenuSubTrigger>
                                            {group.label}
                                        </DropdownMenuSubTrigger>
                                        <DropdownMenuSubContent>
                                            {fields(group.prefix, group.full)}
                                        </DropdownMenuSubContent>
                                    </DropdownMenuSub>
                                ))}
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => insert('nome_cavo')}
                                >
                                    Nome del cavo
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            <Textarea
                ref={input}
                id={id}
                value={value}
                maxLength={500}
                required
                rows={2}
                className="font-mono text-sm"
                aria-invalid={!!errors?.length}
                onChange={(event) => onChange(event.target.value)}
            />
            <FieldError errors={errors} />
        </Field>
    );
}

function TextStyle({
    block,
    onChange,
}: {
    block: Pick<LabelBlock, 'size' | 'align'>;
    onChange: (style: Partial<Pick<LabelBlock, 'size' | 'align'>>) => void;
}) {
    const id = useId();
    return (
        <FieldGroup className="grid grid-cols-2 gap-3">
            <NumberField
                live
                label="Carattere (mm)"
                value={block.size}
                min={2}
                max={6}
                step={0.1}
                onChange={(size) => onChange({ size })}
            />
            <Field className="gap-1.5">
                <FieldLabel id={id}>Allineamento</FieldLabel>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={block.align}
                    aria-labelledby={id}
                    className="w-full"
                    onValueChange={(align) => {
                        if (align)
                            onChange({ align: align as LabelBlock['align'] });
                    }}
                >
                    {(
                        [
                            ['left', 'Sinistra', AlignLeft],
                            ['center', 'Centro', AlignCenter],
                            ['right', 'Destra', AlignRight],
                        ] as const
                    ).map(([value, name, Icon]) => (
                        <ToggleGroupItem
                            key={value}
                            value={value}
                            aria-label={name}
                            className="flex-1"
                        >
                            <Icon />
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </Field>
        </FieldGroup>
    );
}

function RowCard({
    row,
    title,
    errors,
    onChange,
    actions,
}: {
    row: LabelRow;
    title: string;
    errors: LabelError[];
    onChange: (row: LabelRow) => void;
    actions: ReactNode;
}) {
    const separatorId = useId();
    const [selected, setSelected] = useState(0);
    const index = Math.min(selected, row.blocks.length - 1);
    const block = row.blocks[index];
    const positions =
        row.blocks.length === 2
            ? ['Sinistra', 'Destra']
            : ['Sinistra', 'Centro', 'Destra'];
    function move(direction: number) {
        const blocks = [...row.blocks];
        [blocks[index], blocks[index + direction]] = [
            blocks[index + direction],
            blocks[index],
        ];
        onChange({ ...row, blocks });
        setSelected(index + direction);
    }
    return (
        <section className="overflow-hidden rounded-md border">
            <header className="flex min-h-10 items-center justify-between gap-2 border-b bg-muted/40 py-1 pr-1 pl-3">
                <h3 className="text-sm font-medium">{title}</h3>
                <div className="flex">{actions}</div>
            </header>
            <div className="flex flex-col gap-3 p-3">
                {row.blocks.length > 1 && (
                    <div className="flex items-center gap-1">
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={String(index)}
                            aria-label={`Blocco di ${title}`}
                            className="min-w-0 flex-1"
                            onValueChange={(value) => {
                                if (value) setSelected(Number(value));
                            }}
                        >
                            {row.blocks.map((_, i) => (
                                <ToggleGroupItem
                                    key={i}
                                    value={String(i)}
                                    className="flex-1"
                                >
                                    {positions[i]}
                                    {errors.some(
                                        (error) => error.block === i,
                                    ) && <ErrorDot />}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    aria-label={`Azioni blocco ${positions[index]}`}
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    disabled={index === 0}
                                    onSelect={() => move(-1)}
                                >
                                    <ArrowLeft />
                                    Sposta a sinistra
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    disabled={index === row.blocks.length - 1}
                                    onSelect={() => move(1)}
                                >
                                    <ArrowRight />
                                    Sposta a destra
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() =>
                                        onChange({
                                            ...row,
                                            blocks: row.blocks.filter(
                                                (_, i) => i !== index,
                                            ),
                                        })
                                    }
                                >
                                    <Trash2 />
                                    Elimina blocco
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                )}
                <MacroText
                    label="Testo"
                    value={block.text}
                    errors={errors.filter((error) => error.block === index)}
                    onChange={(text) =>
                        onChange({
                            ...row,
                            blocks: row.blocks.map((current, i) =>
                                i === index ? { ...current, text } : current,
                            ),
                        })
                    }
                />
                <TextStyle
                    block={block}
                    onChange={(style) =>
                        onChange({
                            ...row,
                            blocks: row.blocks.map((current, i) =>
                                i === index
                                    ? { ...current, ...style }
                                    : current,
                            ),
                        })
                    }
                />
                <div className="flex items-end justify-between gap-3">
                    {row.blocks.length > 1 && (
                        <Field className="max-w-28 gap-1.5">
                            <FieldLabel htmlFor={separatorId}>
                                Separatore
                            </FieldLabel>
                            <Input
                                id={separatorId}
                                maxLength={20}
                                className="font-mono"
                                value={row.separator ?? ''}
                                onChange={(event) =>
                                    onChange({
                                        ...row,
                                        separator: event.target.value,
                                    })
                                }
                            />
                        </Field>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="ml-auto"
                        disabled={row.blocks.length === 3}
                        onClick={() => {
                            onChange({
                                ...row,
                                blocks: [
                                    ...row.blocks,
                                    {
                                        text: '{{nome_cavo}}',
                                        size: row.blocks[0].size,
                                        align: 'center',
                                    },
                                ],
                            });
                            setSelected(row.blocks.length);
                        }}
                    >
                        <Plus />
                        Blocco affiancato
                    </Button>
                </div>
            </div>
        </section>
    );
}

function RowsEditor({
    rows,
    onChange,
    width,
    errors,
}: {
    rows: LabelRow[];
    errors: LabelError[];
    onChange: (rows: LabelRow[]) => void;
    width: LabelWidth;
}) {
    function moveRow(index: number, direction: number) {
        const copy = [...rows];
        [copy[index], copy[index + direction]] = [
            copy[index + direction],
            copy[index],
        ];
        onChange(copy);
    }
    return (
        <div className="flex flex-col gap-3">
            {rows.map((row, rowIndex) => (
                <RowCard
                    key={rowIndex}
                    row={row}
                    title={`Riga ${rowIndex + 1}`}
                    errors={errors.filter((error) => error.row === rowIndex)}
                    onChange={(next) =>
                        onChange(
                            rows.map((current, i) =>
                                i === rowIndex ? next : current,
                            ),
                        )
                    }
                    actions={
                        rows.length > 1 && (
                            <>
                                <IconButton
                                    label="Sposta riga sopra"
                                    variant="ghost"
                                    className="size-8"
                                    disabled={rowIndex === 0}
                                    onClick={() => moveRow(rowIndex, -1)}
                                >
                                    <ArrowUp />
                                </IconButton>
                                <IconButton
                                    label="Sposta riga sotto"
                                    variant="ghost"
                                    className="size-8"
                                    disabled={rowIndex === rows.length - 1}
                                    onClick={() => moveRow(rowIndex, 1)}
                                >
                                    <ArrowDown />
                                </IconButton>
                                <IconButton
                                    label="Elimina riga"
                                    variant="ghost"
                                    className="size-8"
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
                            </>
                        )
                    }
                />
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
    errors,
    onChange,
}: {
    settings: LabelSettings;
    width: LabelWidth;
    faceLength: number;
    errors: LabelError[];
    onChange: (settings: LabelSettings) => void;
}) {
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
    // A profile that no longer matches a preset opens on its own rows.
    const [customizing, setCustomizing] = useState(composition === 'custom');
    const [face, setFace] = useState('front');
    const at = (group: LabelError['at']) =>
        errors.filter((error) => error.at === group);
    const textErrors = at('text');
    const customizationId = useId();
    const compositionId = useId();
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
                <TabsTrigger value="content">
                    Testo
                    {textErrors.length > 0 && <ErrorDot />}
                </TabsTrigger>
                <TabsTrigger value="dimensions">
                    Nastro
                    {at('length').length > 0 && <ErrorDot />}
                </TabsTrigger>
                <TabsTrigger value="cable">
                    Cavo
                    {at('cable').length > 0 && <ErrorDot />}
                </TabsTrigger>
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
                    {/* Open rows show their own errors next to the text that causes them. */}
                    <FieldError
                        errors={textErrors.filter(
                            (error) => !customizing || error.row === undefined,
                        )}
                    />
                </Field>
                {!customizing &&
                    (uniformStyle ? (
                        <TextStyle block={blocks[0]} onChange={changeStyle} />
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
                        <Tabs
                            value={face}
                            onValueChange={setFace}
                            className="gap-4"
                        >
                            <TabsList
                                aria-label="Testo della bandiera"
                                className="grid w-full grid-cols-2"
                            >
                                {(['front', 'back'] as const).map(
                                    (side, index) => (
                                        <TabsTrigger key={side} value={side}>
                                            Faccia {index + 1}
                                            {textErrors.some(
                                                (error) => error.rows === side,
                                            ) && <ErrorDot />}
                                        </TabsTrigger>
                                    ),
                                )}
                            </TabsList>
                            <TabsContent value="front">
                                <RowsEditor
                                    rows={settings.front}
                                    width={width}
                                    errors={textErrors.filter(
                                        (error) => error.rows === 'front',
                                    )}
                                    onChange={(front) =>
                                        onChange({ ...settings, front })
                                    }
                                />
                            </TabsContent>
                            <TabsContent
                                value="back"
                                className="flex flex-col gap-4"
                            >
                                <Choice
                                    label="Contenuto"
                                    value={settings.back_mode}
                                    choices={{
                                        repeat: 'Uguale alla faccia 1',
                                        opposite:
                                            'Uguale, con estremità opposta',
                                        custom: 'Personalizzato',
                                    }}
                                    onChange={(back_mode) =>
                                        onChange({
                                            ...settings,
                                            back_mode:
                                                back_mode as LabelSettings['back_mode'],
                                        })
                                    }
                                />
                                <Choice
                                    label="Orientamento"
                                    value={String(settings.back_rotation)}
                                    choices={{
                                        '0': 'Stesso verso della faccia 1',
                                        '180': 'Ruotata di 180°',
                                    }}
                                    onChange={(rotation) =>
                                        onChange({
                                            ...settings,
                                            back_rotation: Number(rotation) as
                                                | 0
                                                | 180,
                                        })
                                    }
                                />
                                {settings.back_mode === 'custom' && (
                                    <RowsEditor
                                        rows={settings.back}
                                        width={width}
                                        errors={textErrors.filter(
                                            (error) => error.rows === 'back',
                                        )}
                                        onChange={(back) =>
                                            onChange({ ...settings, back })
                                        }
                                    />
                                )}
                            </TabsContent>
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
                    </div>
                )}
            </TabsContent>
            <TabsContent value="dimensions" className="flex flex-col gap-6">
                <LabelLengthSettings
                    settings={settings}
                    faceLength={faceLength}
                    errors={at('length')}
                    onChange={onChange}
                />
            </TabsContent>
            <TabsContent value="cable">
                <LabelPhysicalSettings
                    settings={settings}
                    errors={at('cable')}
                    onChange={onChange}
                />
            </TabsContent>
        </Tabs>
    );
}
