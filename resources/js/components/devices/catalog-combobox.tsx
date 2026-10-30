import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus } from 'lucide-react';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
import InputError from '@/components/input-error';

type CatalogItem = { id: number; name: string };
type Option = CatalogItem & { create?: boolean };

export async function createCatalogItem(
    url: string,
    name: string,
): Promise<CatalogItem> {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
        },
        body: JSON.stringify({ name }),
    });
    const result = await response.json().catch(() => null);
    if (!response.ok)
        throw new Error(
            result?.errors?.name?.[0] ??
                'Impossibile aggiungere la voce. Riprova.',
        );
    return result as CatalogItem;
}

export function CatalogCombobox({
    id,
    name,
    items: choices,
    value,
    onChange,
    onCreate,
    onCreatingChange,
    placeholder,
    required = false,
    disabled = false,
    error,
}: {
    id: string;
    name: string;
    items: CatalogItem[];
    value: string;
    onChange: (value: string) => void;
    onCreate?: (
        name: string,
    ) => CatalogItem | void | Promise<CatalogItem | void>;
    onCreatingChange?: (creating: boolean) => void;
    placeholder: string;
    required?: boolean;
    disabled?: boolean;
    error?: string;
}) {
    const [added, setAdded] = useState<CatalogItem[]>([]);
    const options = [
        ...choices,
        ...added.filter(
            (item) => !choices.some((choice) => choice.id === item.id),
        ),
    ];
    const selected = options.find((item) => String(item.id) === value) ?? null;
    const [search, setSearch] = useState(selected?.name ?? '');
    const [creating, setCreating] = useState(false);
    const [creationError, setCreationError] = useState('');
    const [container, setContainer] = useState<HTMLDivElement | null>(null);
    const text = search.trim();
    const exact = options.some(
        (item) => item.name.toLocaleLowerCase() === text.toLocaleLowerCase(),
    );
    const filtered: Option[] = options.filter((item) =>
        item.name.toLocaleLowerCase().includes(text.toLocaleLowerCase()),
    );
    if (onCreate && text && !exact)
        filtered.unshift({ id: 0, name: text, create: true });

    async function create() {
        setCreating(true);
        onCreatingChange?.(true);
        setCreationError('');
        try {
            const item = await onCreate?.(text);
            if (item) {
                setAdded((current) => [...current, item]);
                onChange(String(item.id));
                router.reload({ only: ['manufacturers', 'types'] });
            }
        } catch (error) {
            setCreationError(
                error instanceof Error
                    ? error.message
                    : 'Impossibile aggiungere la voce.',
            );
        } finally {
            setCreating(false);
            onCreatingChange?.(false);
        }
    }
    return (
        <div ref={setContainer} className="flex flex-col gap-2">
            <Combobox
                items={filtered}
                value={selected as Option | null}
                name={name}
                required={required}
                autoHighlight
                openOnInputClick
                onInputValueChange={setSearch}
                itemToStringLabel={(item) => item.name}
                itemToStringValue={(item) => String(item.id)}
                isItemEqualToValue={(item, current) => item.id === current.id}
                filter={null}
                disabled={disabled || creating}
                onValueChange={(item) => {
                    if (item?.create) void create();
                    else {
                        onChange(item ? String(item.id) : '');
                        setCreationError('');
                    }
                }}
            >
                <ComboboxInput
                    id={id}
                    placeholder={placeholder}
                    maxLength={255}
                    className="w-full"
                    showClear={!required}
                    disabled={disabled || creating}
                    aria-busy={creating}
                    aria-invalid={!!(error || creationError)}
                    aria-describedby={`${id}-error`}
                />
                <ComboboxContent container={container}>
                    <ComboboxEmpty>Nessun risultato</ComboboxEmpty>
                    <ComboboxList>
                        {(item: Option) => (
                            <ComboboxItem key={item.id} value={item}>
                                {item.create ? (
                                    <>
                                        <Plus />
                                        <span>Aggiungi «{item.name}»</span>
                                    </>
                                ) : (
                                    item.name
                                )}
                            </ComboboxItem>
                        )}
                    </ComboboxList>
                </ComboboxContent>
            </Combobox>
            <InputError id={`${id}-error`} message={creationError || error} />
        </div>
    );
}
