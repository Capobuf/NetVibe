import { Form } from '@inertiajs/react';
import { Check, Trash2 } from 'lucide-react';
import { useId, useRef, useState, type ComponentProps } from 'react';
import { IconButton } from '@/components/icon-button';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Combobox,
    ComboboxChip,
    ComboboxChips,
    ComboboxChipsInput,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxItem,
    ComboboxList,
    ComboboxValue,
    useComboboxAnchor,
} from '@/components/ui/combobox';
import {
    store,
    update,
    destroy,
} from '@/routes/companies/sites/subnets/summary-entries';
import type { SubnetSummaryEntry } from '@/types/network';

export type SummaryRoute = { company: number; site: number; subnet: number };

export default function SubnetSummaryEntryForm({
    route,
    entry,
    onOpenChange,
    onCloseAutoFocus,
}: {
    route: SummaryRoute;
    entry?: SubnetSummaryEntry;
    onOpenChange: (open: boolean) => void;
    onCloseAutoFocus: ComponentProps<typeof DialogContent>['onCloseAutoFocus'];
}) {
    const [values, setValues] = useState<string[]>(entry?.values ?? []);
    const [search, setSearch] = useState('');
    const anchor = useComboboxAnchor();
    // Keep the combobox popup inside the dialog's focus boundary.
    const popupContainer = useRef<HTMLDivElement>(null);
    const id = useId();
    const candidate = search.trim();
    const canAdd = candidate !== '' && !values.includes(candidate);
    const items = [...new Set(canAdd ? [...values, candidate] : values)];

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent
                ref={popupContainer}
                onCloseAutoFocus={onCloseAutoFocus}
                aria-describedby={undefined}
                className="sm:max-w-2xl"
            >
                <DialogHeader>
                    <DialogTitle>
                        {entry ? 'Modifica voce' : 'Nuova voce del riepilogo'}
                    </DialogTitle>
                </DialogHeader>
                <Form
                    {...(entry
                        ? update.form({ ...route, summaryEntry: entry.id })
                        : store.form(route))}
                    transform={(data) => ({
                        ...data,
                        values: canAdd ? [...values, candidate] : values,
                    })}
                    onSuccess={() => onOpenChange(false)}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => {
                        const valuesError = Object.entries(errors).find(
                            ([key]) =>
                                key === 'values' || key.startsWith('values.'),
                        )?.[1];

                        return (
                            <>
                                <FieldGroup className="grid gap-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                                    <Field data-invalid={Boolean(errors.name)}>
                                        <FieldLabel htmlFor={`${id}-name`}>
                                            Nome
                                        </FieldLabel>
                                        <Input
                                            id={`${id}-name`}
                                            name="name"
                                            defaultValue={entry?.name ?? ''}
                                            maxLength={255}
                                            required
                                            autoFocus
                                            disabled={processing}
                                            aria-invalid={Boolean(errors.name)}
                                            aria-describedby={`${id}-name-error`}
                                        />
                                        <InputError
                                            id={`${id}-name-error`}
                                            message={errors.name}
                                        />
                                    </Field>
                                    <Field
                                        className="min-w-0"
                                        data-invalid={Boolean(valuesError)}
                                    >
                                        <FieldLabel htmlFor={`${id}-values`}>
                                            Valori
                                        </FieldLabel>
                                        <Combobox
                                            multiple
                                            autoHighlight
                                            openOnInputClick
                                            items={items}
                                            value={values}
                                            inputValue={search}
                                            onInputValueChange={setSearch}
                                            onValueChange={(selected) => {
                                                setValues(selected);
                                                if (
                                                    selected.length >
                                                    values.length
                                                )
                                                    setSearch('');
                                            }}
                                            disabled={processing}
                                        >
                                            <ComboboxChips ref={anchor}>
                                                <ComboboxValue>
                                                    {values.map(
                                                        (value, index) => (
                                                            <ComboboxChip
                                                                key={index}
                                                                className="h-auto max-w-full min-w-0"
                                                                removeLabel={`Rimuovi ${value}`}
                                                            >
                                                                <span className="min-w-0 break-words whitespace-pre-wrap">
                                                                    {value}
                                                                </span>
                                                            </ComboboxChip>
                                                        ),
                                                    )}
                                                </ComboboxValue>
                                                <ComboboxChipsInput
                                                    id={`${id}-values`}
                                                    className="max-w-full"
                                                    placeholder="Aggiungi valore…"
                                                    maxLength={2000}
                                                    required={
                                                        values.length === 0
                                                    }
                                                    disabled={processing}
                                                    aria-invalid={Boolean(
                                                        valuesError,
                                                    )}
                                                    aria-describedby={`${id}-values-error`}
                                                />
                                            </ComboboxChips>
                                            <ComboboxContent
                                                anchor={anchor}
                                                container={popupContainer}
                                            >
                                                <ComboboxEmpty>
                                                    Nessun valore
                                                </ComboboxEmpty>
                                                <ComboboxList>
                                                    {(value: string) => (
                                                        <ComboboxItem
                                                            key={value}
                                                            value={value}
                                                        >
                                                            <span className="min-w-0 break-words whitespace-pre-wrap">
                                                                {values.includes(
                                                                    value,
                                                                )
                                                                    ? value
                                                                    : `Aggiungi «${value}»`}
                                                            </span>
                                                        </ComboboxItem>
                                                    )}
                                                </ComboboxList>
                                            </ComboboxContent>
                                        </Combobox>
                                        <InputError
                                            id={`${id}-values-error`}
                                            message={valuesError}
                                        />
                                    </Field>
                                </FieldGroup>
                                <DialogFooter>
                                    {entry ? (
                                        <div
                                            className="sm:mr-auto"
                                            // The confirmation portal must not also submit the edit form.
                                            onSubmit={(event) =>
                                                event.stopPropagation()
                                            }
                                        >
                                            <DeleteEntry
                                                route={route}
                                                entry={entry}
                                                disabled={processing}
                                                onDeleted={() =>
                                                    onOpenChange(false)
                                                }
                                            />
                                        </div>
                                    ) : null}
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Annulla
                                        </Button>
                                    </DialogClose>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <Check data-icon="inline-start" />
                                        )}
                                        Salva voce
                                    </Button>
                                </DialogFooter>
                            </>
                        );
                    }}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteEntry({
    route,
    entry,
    disabled,
    onDeleted,
}: {
    route: SummaryRoute;
    entry: SubnetSummaryEntry;
    disabled: boolean;
    onDeleted: () => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <IconButton
                    label={`Elimina ${entry.name}`}
                    variant="ghost"
                    disabled={disabled}
                >
                    <Trash2 />
                </IconButton>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Elimina «{entry.name}»</DialogTitle>
                    <DialogDescription>
                        La voce e tutti i suoi valori saranno eliminati
                        definitivamente dal riepilogo.
                    </DialogDescription>
                </DialogHeader>
                <ul className="flex flex-col gap-2 text-sm">
                    {entry.values.map((value, index) => (
                        <li
                            key={index}
                            className="break-words whitespace-pre-wrap"
                        >
                            {value}
                        </li>
                    ))}
                </ul>
                <Form
                    {...destroy.form({ ...route, summaryEntry: entry.id })}
                    onSuccess={() => {
                        setOpen(false);
                        onDeleted();
                    }}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="confirmed" value="1" />
                            <InputError message={errors.confirmed} />
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={processing}
                                        autoFocus
                                    >
                                        Annulla
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing ? (
                                        <Spinner />
                                    ) : (
                                        <Trash2 data-icon="inline-start" />
                                    )}
                                    Elimina voce
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
