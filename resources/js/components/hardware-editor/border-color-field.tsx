import { Pipette, Save, X } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { borderColors } from '@/lib/hardware-editor/colors';
import type { Editor } from './use-editor';

export function BorderColorField({
    editor: e,
    value,
}: {
    editor: Editor;
    value?: string;
}) {
    const display = e.borderColorPreview ?? value;
    const colors = [
        ...borderColors,
        ...e.customColors.map((color) => ({ name: color, color })),
    ];
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
            <div className="flex flex-wrap items-center gap-2">
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
                    className="flex-wrap justify-start gap-2"
                >
                    {colors.map(({ name, color }) => (
                        <div key={color} className="group/color relative">
                            <ToggleGroupItem
                                value={color}
                                aria-label={name}
                                className="group size-8 rounded-full p-0 first:rounded-full last:rounded-full"
                            >
                                <span
                                    className="size-6 rounded-full border border-foreground/20 ring-foreground ring-offset-background group-data-[state=on]:ring-2 group-data-[state=on]:ring-offset-2"
                                    style={{ backgroundColor: color }}
                                />
                            </ToggleGroupItem>
                            {e.customColors.includes(color) && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="icon"
                                    aria-label={`Elimina colore ${color}`}
                                    className="invisible absolute -top-1 -right-1 size-5 rounded-full border transition-[visibility] duration-0 group-hover/color:visible group-hover/color:delay-1000 group-has-focus-visible/color:visible group-has-focus-visible/color:delay-0"
                                    onClick={() =>
                                        e.saveCustomColors(
                                            e.customColors.filter(
                                                (item) => item !== color,
                                            ),
                                        )
                                    }
                                >
                                    <X />
                                </Button>
                            )}
                        </div>
                    ))}
                    <div
                        data-selected={
                            !!display &&
                            !colors.some(({ color }) => color === display)
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
                </ToggleGroup>
            </div>
            {display &&
                !colors.some(
                    ({ color }) => color === display.toLowerCase(),
                ) && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!e.paletteReady}
                        onClick={() =>
                            e.saveCustomColors([...e.customColors, display])
                        }
                    >
                        <Save />
                        Salva colore nella palette
                    </Button>
                )}
        </Field>
    );
}
