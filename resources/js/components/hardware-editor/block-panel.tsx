import { Check, Grid2X2, X } from 'lucide-react';
import { IconButton } from '@/components/icon-button';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Slider } from '@/components/ui/slider';
import {
    insertableConnectors,
    type BlockOptions,
    type ConnectorType,
} from '@/lib/hardware-editor/document';
import { ConnectorIcon } from './connector-icon';
import { Choice, NumberField } from './editor-fields';

const portCounts = [2, 4, 6, 8, 12, 16, 24, 48, 52];

export function BlockPanel({
    options,
    onChange,
    onInsert,
    onClose,
}: {
    options: BlockOptions;
    onChange: (options: BlockOptions) => void;
    onInsert: () => void;
    onClose: () => void;
}) {
    const rows = Math.ceil(options.count / options.columns);
    const patch = (changes: Partial<BlockOptions>) =>
        onChange({ ...options, ...changes });
    const setCount = (count: number) =>
        patch({ count, columns: Math.min(options.columns, count) });
    const upperStep = portCounts.findIndex((count) => count >= options.count);
    const sliderValue =
        upperStep < 0
            ? portCounts.length - 1
            : upperStep === 0
              ? 0
              : upperStep -
                1 +
                (options.count - portCounts[upperStep - 1]) /
                    (portCounts[upperStep] - portCounts[upperStep - 1]);
    return (
        <form
            className="flex h-full flex-col"
            onKeyDown={(event) => {
                if (event.key === 'Escape' && !event.defaultPrevented) {
                    event.preventDefault();
                    onClose();
                }
            }}
            onSubmit={(event) => {
                event.preventDefault();
                onInsert();
            }}
        >
            <div className="flex items-center justify-between gap-2 border-b p-3">
                <h2 className="flex items-center gap-2 text-sm font-medium">
                    <Grid2X2 className="size-4" />
                    Blocco porte
                </h2>
                <IconButton
                    label="Annulla blocco porte"
                    variant="ghost"
                    onClick={onClose}
                >
                    <X />
                </IconButton>
            </div>
            <div className="flex-1 overflow-y-auto p-4">
                <FieldGroup>
                    <Choice
                        label="Connettore"
                        value={options.type}
                        choices={Object.fromEntries(
                            insertableConnectors.map(([key, item]) => [
                                key,
                                <span className="flex items-center gap-2">
                                    <ConnectorIcon type={key} />
                                    {item.name}
                                </span>,
                            ]),
                        )}
                        onChange={(type) =>
                            patch({ type: type as ConnectorType })
                        }
                    />
                    <div className="grid grid-cols-[minmax(0,1fr)_5rem] items-start gap-3">
                        <Field className="gap-1.5">
                            <FieldLabel id="block-count-label">
                                Numero di porte
                            </FieldLabel>
                            <Slider
                                aria-labelledby="block-count-label"
                                aria-valuetext={`${options.count} porte`}
                                className="h-10"
                                min={0}
                                max={portCounts.length - 1}
                                step={1}
                                value={[sliderValue]}
                                onValueChange={([index]) =>
                                    setCount(portCounts[index])
                                }
                            />
                            <div
                                className="relative h-4 text-xs text-muted-foreground tabular-nums"
                                aria-hidden="true"
                            >
                                {portCounts.map((count, index) => (
                                    <span
                                        key={count}
                                        className="absolute"
                                        style={{
                                            left: `${(index / (portCounts.length - 1)) * 100}%`,
                                            transform: `translateX(${index === 0 ? 0 : index === portCounts.length - 1 ? -100 : -50}%)`,
                                        }}
                                    >
                                        {count}
                                    </span>
                                ))}
                            </div>
                        </Field>
                        <NumberField
                            live
                            label="Porte"
                            value={options.count}
                            min={1}
                            max={96}
                            onChange={setCount}
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <NumberField
                            live
                            label="Colonne"
                            value={options.columns}
                            min={1}
                            max={options.count}
                            onChange={(columns) => patch({ columns })}
                        />
                        <NumberField
                            live
                            label="Righe"
                            value={rows}
                            min={1}
                            max={options.count}
                            onChange={(rows) =>
                                patch({
                                    columns: Math.ceil(options.count / rows),
                                })
                            }
                        />
                    </div>
                    {(
                        [
                            ['gapX', 'Spazio orizzontale', 'Orizzontale'],
                            ['gapY', 'Spazio verticale', 'Verticale'],
                        ] as const
                    ).map(([key, label, shortLabel]) => (
                        <div
                            key={key}
                            className="grid grid-cols-[minmax(0,1fr)_5rem] items-start gap-3"
                        >
                            <Field className="gap-1.5">
                                <FieldLabel id={`block-${key}-label`}>
                                    {label}
                                </FieldLabel>
                                <Slider
                                    aria-labelledby={`block-${key}-label`}
                                    className="h-10"
                                    min={0}
                                    max={300}
                                    step={0.1}
                                    value={[options[key]]}
                                    onValueChange={([value]) =>
                                        patch({ [key]: value })
                                    }
                                />
                                <div
                                    className="relative h-4 text-xs text-muted-foreground tabular-nums"
                                    aria-hidden="true"
                                >
                                    {[0, 75, 150, 225, 300].map((value) => (
                                        <span
                                            key={value}
                                            className="absolute"
                                            style={{
                                                left: `${value / 3}%`,
                                                transform: `translateX(${value === 0 ? 0 : value === 300 ? -100 : -50}%)`,
                                            }}
                                        >
                                            {value}
                                        </span>
                                    ))}
                                </div>
                            </Field>
                            <NumberField
                                live
                                label={shortLabel}
                                value={options[key]}
                                min={0}
                                max={300}
                                step={0.1}
                                onChange={(value) => patch({ [key]: value })}
                            />
                        </div>
                    ))}
                    <div className="grid grid-cols-[minmax(0,1fr)_5rem] items-start gap-3">
                        <Field className="gap-1.5">
                            <FieldLabel id="block-scale-label">
                                Scala porte %
                            </FieldLabel>
                            <Slider
                                aria-labelledby="block-scale-label"
                                className="h-10"
                                min={20}
                                max={800}
                                step={0.1}
                                value={[options.scale * 100]}
                                onValueChange={([scale]) =>
                                    patch({ scale: scale / 100 })
                                }
                            />
                            <div
                                className="relative h-4 text-xs text-muted-foreground tabular-nums"
                                aria-hidden="true"
                            >
                                {[20, 200, 400, 600, 800].map((value) => (
                                    <span
                                        key={value}
                                        className="absolute"
                                        style={{
                                            left: `${((value - 20) / 780) * 100}%`,
                                            transform: `translateX(${value === 20 ? 0 : value === 800 ? -100 : -50}%)`,
                                        }}
                                    >
                                        {value}
                                    </span>
                                ))}
                            </div>
                        </Field>
                        <NumberField
                            live
                            label="Scala %"
                            value={options.scale * 100}
                            min={20}
                            max={800}
                            step={0.1}
                            onChange={(scale) => patch({ scale: scale / 100 })}
                        />
                    </div>
                    <Choice
                        label="Rotazione porte"
                        value={String(options.rotation)}
                        choices={{
                            0: '0°',
                            90: '90°',
                            180: '180°',
                            270: '270°',
                        }}
                        onChange={(rotation) =>
                            patch({ rotation: Number(rotation) })
                        }
                    />
                    <Choice
                        label="Disposizione"
                        value={options.columnFirst ? 'columns' : 'rows'}
                        choices={{ rows: 'Per righe', columns: 'Per colonne' }}
                        onChange={(value) =>
                            patch({ columnFirst: value === 'columns' })
                        }
                    />
                    <div className="grid grid-cols-2 gap-3">
                        <NumberField
                            live
                            label="Centro X"
                            value={options.x}
                            step={0.01}
                            onChange={(x) => patch({ x })}
                        />
                        <NumberField
                            live
                            label="Centro Y"
                            value={options.y}
                            step={0.01}
                            onChange={(y) => patch({ y })}
                        />
                    </div>
                </FieldGroup>
            </div>
            <div className="flex items-center justify-between gap-2 border-t p-3">
                <Button type="button" variant="outline" onClick={onClose}>
                    Annulla
                </Button>
                <Button type="submit">
                    <Check />
                    Inserisci {options.count} porte
                </Button>
            </div>
        </form>
    );
}
