import { useEffect, useId, useState, type ReactNode } from 'react';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export function NumberField({
    label,
    value,
    onChange,
    min = -10000,
    max = 10000,
    step = 1,
    disabled = false,
    live = false,
}: {
    label: string;
    value: number;
    onChange: (value: number) => void;
    min?: number;
    max?: number;
    step?: number;
    disabled?: boolean;
    live?: boolean;
}) {
    const id = useId();
    const [text, setText] = useState(String(Math.round(value * 100) / 100));
    const [error, setError] = useState(false);
    useEffect(() => {
        setText(String(Math.round(value * 100) / 100));
        setError(false);
    }, [value]);
    function apply(input = text, showError = true) {
        const n = Number(input);
        if (
            !input.trim() ||
            !Number.isFinite(n) ||
            n < min ||
            n > max ||
            (step === 1 && !Number.isInteger(n))
        ) {
            if (showError) setError(true);
            return;
        }
        setError(false);
        if (n !== value) onChange(n);
    }
    return (
        <Field className="gap-1.5" data-invalid={error}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Input
                id={id}
                type="number"
                required
                className="tabular-nums"
                value={text}
                min={min}
                max={max}
                step={step}
                disabled={disabled}
                aria-invalid={error}
                onChange={(event) => {
                    setText(event.target.value);
                    if (live) apply(event.target.value, false);
                }}
                onBlur={() => apply()}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        event.currentTarget.blur();
                    }
                }}
            />
            {error && (
                <FieldError>
                    Valore da {min} a {max}
                    {step === 1 ? ', intero' : ''}.
                </FieldError>
            )}
        </Field>
    );
}
export function Choice({
    label,
    value,
    choices,
    onChange,
    disabled = false,
}: {
    label: string;
    value: string;
    choices: Record<string, ReactNode>;
    onChange: (value: string) => void;
    disabled?: boolean;
}) {
    const id = useId();
    return (
        <Field className="gap-1.5">
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Select value={value} onValueChange={onChange} disabled={disabled}>
                <SelectTrigger id={id} className="w-full">
                    <SelectValue placeholder="Valori diversi" />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        {Object.entries(choices).map(([key, name]) => (
                            <SelectItem key={key} value={key}>
                                {name}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                </SelectContent>
            </Select>
        </Field>
    );
}
