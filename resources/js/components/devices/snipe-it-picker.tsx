import { useEffect, useState } from 'react';
import {
    Combobox,
    ComboboxInput,
    ComboboxContent,
    ComboboxList,
    ComboboxItem,
    ComboboxEmpty,
} from '@/components/ui/combobox';
import { options as optionsRoute } from '@/routes/companies/settings/integrations/snipe-it';

export type SnipeOption = {
    id: number;
    name: string;
    detail?: string | null;
    url?: string;
    model?: SnipeOption | null;
};

export function SnipeItPicker({
    company,
    kind,
    value,
    onChange,
    disabled,
    label,
}: {
    company: number;
    kind: 'models' | 'devices';
    value: SnipeOption | null;
    onChange: (value: SnipeOption | null) => void;
    disabled?: boolean;
    label: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [items, setItems] = useState<SnipeOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setLoading(true);
            setError(false);
            try {
                const response = await fetch(
                    optionsRoute.url(company, { query: { kind, q: query } }),
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    },
                );
                if (!response.ok) throw new Error('options');
                const data: SnipeOption[] = await response.json();
                if (!controller.signal.aborted) setItems(data);
            } catch {
                if (!controller.signal.aborted) setError(true);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 200);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [company, kind, open, query]);
    const options =
        value && !items.some((item) => item.id === value.id)
            ? [value, ...items]
            : items;
    return (
        <Combobox
            items={options}
            value={value}
            onValueChange={onChange}
            open={open}
            onOpenChange={setOpen}
            onInputValueChange={setQuery}
            itemToStringLabel={(item: SnipeOption) => item.name}
            isItemEqualToValue={(a: SnipeOption, b: SnipeOption) =>
                a.id === b.id
            }
            filter={null}
            disabled={disabled}
        >
            <ComboboxInput aria-label={label} placeholder="Cerca in NetVibe…" />
            <ComboboxContent>
                <ComboboxEmpty>
                    {loading
                        ? 'Ricerca…'
                        : error
                          ? 'Ricerca non riuscita. Riprova.'
                          : 'Nessun risultato'}
                </ComboboxEmpty>
                <ComboboxList>
                    {(item: SnipeOption) => (
                        <ComboboxItem key={item.id} value={item}>
                            <div className="flex flex-col gap-1">
                                <span>{item.name}</span>
                                {item.detail ? (
                                    <span className="text-xs text-muted-foreground">
                                        {item.detail}
                                    </span>
                                ) : null}
                            </div>
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}
