import { useId, useState } from 'react';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import InputError from '@/components/input-error';

export function MikrotikEditMode({ error }: { error?: string }) {
    const id = useId();
    const [value, setValue] = useState('');
    if (!error && !value) return null;
    return (
        <Field data-invalid={Boolean(error)}>
            <FieldLabel htmlFor={id}>Significato della modifica</FieldLabel>
            <Select
                name="mikrotik_edit_mode"
                required
                value={value}
                onValueChange={setValue}
            >
                <SelectTrigger
                    id={id}
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? `${id}-error` : undefined}
                >
                    <SelectValue placeholder="Scegli come conservare il valore" />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        <SelectItem value="planned">
                            Modifica prevista
                        </SelectItem>
                        <SelectItem value="manual">
                            Mantieni valore manuale
                        </SelectItem>
                    </SelectGroup>
                </SelectContent>
            </Select>
            <InputError id={`${id}-error`} message={error} />
        </Field>
    );
}
