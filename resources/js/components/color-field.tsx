import { SaveButton } from '@/components/save-button';
import { Pipette, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { borderColors, parseCustomColors } from '@/lib/hardware-editor/colors';

export function ColorField({
    label = 'Colore',
    value,
    fallbackValue = '#8998aa',
    disabled = false,
    onChange,
    onPreview,
}: {
    label?: string;
    value?: string;
    fallbackValue?: string;
    disabled?: boolean;
    onChange: (color: string) => void;
    onPreview?: (color: string | null) => void;
}) {
    const id = useId();
    const { auth } = usePage().props;
    const [preview, setPreview] = useState<string | null>(null);
    const [customColors, setCustomColors] = useState<string[]>([]);
    const [paletteReady, setPaletteReady] = useState(false);
    const [error, setError] = useState('');
    const display = preview ?? value;
    const colors = [
        ...borderColors,
        ...customColors.map((color) => ({ name: color, color })),
    ];
    useEffect(() => {
        setPaletteReady(false);
        setCustomColors([]);
        setError('');
        try {
            setCustomColors(
                parseCustomColors(
                    localStorage.getItem(`netvibe:port-colors:${auth.user.id}`),
                ),
            );
            setPaletteReady(true);
        } catch {
            setError(
                'Impossibile leggere i colori salvati. Verifica i dati del sito nel browser e ricarica.',
            );
        }
    }, [auth.user.id]);
    useEffect(() => () => onPreview?.(null), [onPreview]);
    function saveCustomColors(colors: string[]) {
        if (!paletteReady) return;
        try {
            const next = parseCustomColors(JSON.stringify(colors));
            localStorage.setItem(
                `netvibe:port-colors:${auth.user.id}`,
                JSON.stringify(next),
            );
            setCustomColors(next);
            setError('');
        } catch {
            setError(
                'Impossibile salvare la palette. Consenti il salvataggio dei dati del sito nel browser.',
            );
        }
    }
    function commit(color: string) {
        setPreview(null);
        onPreview?.(null);
        onChange(color);
    }
    return (
        <Field>
            <div className="flex items-center justify-between gap-2">
                <FieldLabel id={id}>{label}</FieldLabel>
                <span className="text-xs text-muted-foreground">
                    {display ?? 'Valori diversi'}
                </span>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <ToggleGroup
                    type="single"
                    size="sm"
                    value={display ?? ''}
                    disabled={disabled}
                    onValueChange={(color) => {
                        if (color) commit(color);
                    }}
                    aria-labelledby={id}
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
                            {customColors.includes(color) && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="icon"
                                    disabled={disabled}
                                    aria-label={`Elimina colore ${color}`}
                                    className="invisible absolute -top-1 -right-1 size-5 rounded-full border transition-[visibility] duration-0 group-hover/color:visible group-hover/color:delay-1000 group-has-focus-visible/color:visible group-has-focus-visible/color:delay-0"
                                    onClick={() =>
                                        saveCustomColors(
                                            customColors.filter(
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
                            aria-label={`${label} personalizzato`}
                            disabled={disabled}
                            className="absolute inset-0 size-full cursor-pointer rounded-full p-0 opacity-0"
                            value={display ?? fallbackValue}
                            onChange={(event) => {
                                if (onPreview) {
                                    setPreview(event.target.value);
                                    onPreview(event.target.value);
                                } else onChange(event.target.value);
                            }}
                            onBlur={() => {
                                if (preview !== null) commit(preview);
                            }}
                        />
                    </div>
                </ToggleGroup>
            </div>
            {display &&
                !colors.some(
                    ({ color }) => color === display.toLowerCase(),
                ) && (
                    <SaveButton
                        type="button"
                        variant="outline"
                        size="sm"
                        processing={false}
                        disabled={disabled || !paletteReady}
                        onClick={() =>
                            saveCustomColors([...customColors, display])
                        }
                    >
                        Salva colore nella palette
                    </SaveButton>
                )}
            <InputError message={error} />
        </Field>
    );
}
