import { useId } from 'react';
import { Choice } from '@/components/hardware-editor/editor-fields';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Slider } from '@/components/ui/slider';
import type { LabelSettings } from '@/lib/cable-labels';

export function Millimetres({
    label,
    value,
    min,
    max,
    onChange,
}: {
    label: string;
    value: number;
    min: number;
    max: number;
    onChange: (value: number) => void;
}) {
    const id = useId();
    return (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <div className="flex items-center gap-4">
                <Slider
                    aria-label={label}
                    aria-valuetext={`${value} millimetri`}
                    value={[value]}
                    min={min}
                    max={max}
                    step={0.1}
                    onValueChange={(values) => onChange(values[0])}
                />
                <Input
                    id={id}
                    className="w-24 shrink-0"
                    type="number"
                    min={min}
                    max={max}
                    step={0.1}
                    required
                    value={value}
                    onChange={(event) => {
                        const next = event.target.valueAsNumber;
                        if (Number.isFinite(next)) onChange(next);
                    }}
                />
            </div>
        </Field>
    );
}
export function LabelPhysicalSettings({
    settings,
    onChange,
}: {
    settings: LabelSettings;
    onChange: (settings: LabelSettings) => void;
}) {
    return (
        <FieldGroup className="gap-5">
            <Choice
                label="Profilo del cavo"
                value={settings.profile}
                choices={{ round: 'Singolo / tondo', duplex: 'Fibra duplex' }}
                onChange={(profile) =>
                    onChange({
                        ...settings,
                        profile: profile as LabelSettings['profile'],
                    })
                }
            />
            <Millimetres
                label={
                    settings.profile === 'duplex'
                        ? 'Diametro di una guaina (mm)'
                        : 'Diametro del cavo (mm)'
                }
                value={settings.diameter}
                min={0.5}
                max={30}
                onChange={(diameter) => onChange({ ...settings, diameter })}
            />
            <Millimetres
                label="Zona di avvolgimento (mm)"
                value={settings.wrap}
                min={2}
                max={160}
                onChange={(wrap) => onChange({ ...settings, wrap })}
            />
            <Millimetres
                label="Lunghezza massima di ogni faccia (mm)"
                value={settings.max_length}
                min={8}
                max={150}
                onChange={(max_length) => onChange({ ...settings, max_length })}
            />
        </FieldGroup>
    );
}
