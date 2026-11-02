import { useId } from 'react';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';

export function RackFloorField({
    value,
    onChange,
    disabled,
    error,
}: {
    value?: string | null;
    onChange?: (value: string | null) => void;
    disabled?: boolean;
    error?: string;
}) {
    const id = useId();
    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor={id}>Piano</FieldLabel>
            <Input
                id={id}
                name="floor"
                list={`${id}-floors`}
                value={value === undefined ? undefined : (value ?? '')}
                onChange={
                    onChange
                        ? (event) => onChange(event.target.value || null)
                        : undefined
                }
                maxLength={12}
                pattern="PT|P-?[1-9][0-9]*"
                placeholder="PT, P1, P2, P-1…"
                disabled={disabled}
                aria-invalid={!!error}
            />
            <datalist id={`${id}-floors`}>
                <option value="PT">Piano terra</option>
                <option value="P1">Primo piano</option>
                <option value="P2">Secondo piano</option>
                <option value="P3">Terzo piano</option>
                <option value="P-1">Primo piano interrato</option>
                <option value="P-2">Secondo piano interrato</option>
                <option value="P-3">Terzo piano interrato</option>
            </datalist>
            <InputError message={error} />
        </Field>
    );
}
