import { Plus, X } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { IconButton } from '@/components/icon-button';
import InputError from '@/components/input-error';
import { TagBadge, TagColorPicker } from '@/components/networks/tag-badge';
import { Button } from '@/components/ui/button';
import {
    Combobox,
    ComboboxInput,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import {
    Field,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { IpAddress, IpStatus, Tag } from '@/types/network';

export default function IpMetadataFields({
    statuses,
    tags,
    ipAddress,
    disabled,
    readOnly,
    errors,
}: {
    statuses: IpStatus[];
    tags: Tag[];
    ipAddress?: IpAddress;
    disabled: boolean;
    readOnly: boolean;
    errors: Record<string, string>;
}) {
    const id = useId();
    // Keep the list inside the IP dialog's focus boundary.
    const tagContainer = useRef<HTMLFieldSetElement>(null);
    const [status, setStatus] = useState(
        String(ipAddress?.status?.id ?? 'none'),
    );
    const [selected, setSelected] = useState<Tag[]>(ipAddress?.tags ?? []);
    const [newTags, setNewTags] = useState<Omit<Tag, 'id'>[]>([]);
    const [search, setSearch] = useState('');
    const name = search.trim();
    const matching = tags.filter((tag) =>
        tag.name.toLocaleLowerCase().includes(name.toLocaleLowerCase()),
    );
    const existing = tags.find(
        (tag) => tag.name.toLocaleLowerCase() === name.toLocaleLowerCase(),
    );
    const canAdd =
        name !== '' &&
        !newTags.some(
            (tag) => tag.name.toLocaleLowerCase() === name.toLocaleLowerCase(),
        ) &&
        (!existing || !selected.some((tag) => tag.id === existing.id));
    const tagError = Object.entries(errors).find(([key]) =>
        /^(tags|new_tags)(\.|$)/.test(key),
    )?.[1];

    function addTag() {
        if (!canAdd) return;
        if (existing) setSelected([...selected, existing]);
        else setNewTags([...newTags, { name, color: 'gray' }]);
        setSearch('');
    }

    return (
        <>
            <Field
                data-invalid={Boolean(errors.ip_status_id)}
                data-disabled={disabled || readOnly}
            >
                <FieldLabel htmlFor={`${id}-status`}>Stato</FieldLabel>
                <input
                    type="hidden"
                    name="ip_status_id"
                    value={status === 'none' ? '' : status}
                />
                <Select
                    value={status}
                    onValueChange={setStatus}
                    disabled={disabled || readOnly}
                >
                    <SelectTrigger
                        id={`${id}-status`}
                        className="w-full"
                        aria-invalid={Boolean(errors.ip_status_id)}
                        aria-describedby={`${id}-status-error`}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectItem value="none">Nessuno stato</SelectItem>
                            {statuses.map((item) => (
                                <SelectItem
                                    key={item.id}
                                    value={String(item.id)}
                                >
                                    {item.name}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <InputError
                    id={`${id}-status-error`}
                    message={errors.ip_status_id}
                />
            </Field>
            <FieldSet
                ref={tagContainer}
                className="flex min-w-0 flex-col gap-3"
                disabled={disabled || readOnly}
            >
                <FieldLegend variant="label">Tag</FieldLegend>
                {selected.map((tag, index) => (
                    <span key={tag.id} hidden>
                        <input
                            type="hidden"
                            name={`tags[${index}][id]`}
                            value={tag.id}
                        />
                        <input
                            type="hidden"
                            name={`tags[${index}][color]`}
                            value={tag.color}
                        />
                    </span>
                ))}
                {newTags.map((tag, index) => (
                    <span key={tag.name} hidden>
                        <input
                            type="hidden"
                            name={`new_tags[${index}][name]`}
                            value={tag.name}
                        />
                        <input
                            type="hidden"
                            name={`new_tags[${index}][color]`}
                            value={tag.color}
                        />
                    </span>
                ))}
                <div className="flex flex-wrap gap-2">
                    {[
                        ...selected,
                        ...newTags.map((tag) => ({ ...tag, id: null })),
                    ].map((tag) => (
                        <TagBadge
                            key={tag.id ?? `new-${tag.name}`}
                            color={tag.color}
                            className="max-w-full gap-1 break-words whitespace-normal"
                        >
                            {tag.name}
                            {!readOnly ? (
                                <TagColorPicker
                                    name={tag.name}
                                    color={tag.color}
                                    disabled={disabled}
                                    onChange={(color) =>
                                        tag.id === null
                                            ? setNewTags(
                                                  newTags.map((item) =>
                                                      item.name === tag.name
                                                          ? { ...item, color }
                                                          : item,
                                                  ),
                                              )
                                            : setSelected(
                                                  selected.map((item) =>
                                                      item.id === tag.id
                                                          ? { ...item, color }
                                                          : item,
                                                  ),
                                              )
                                    }
                                />
                            ) : null}
                            {!readOnly ? (
                                <IconButton
                                    type="button"
                                    variant="ghost"
                                    className="size-6"
                                    label={`Rimuovi tag ${tag.name}`}
                                    disabled={disabled}
                                    onClick={() =>
                                        tag.id === null
                                            ? setNewTags(
                                                  newTags.filter(
                                                      (item) =>
                                                          item.name !==
                                                          tag.name,
                                                  ),
                                              )
                                            : setSelected(
                                                  selected.filter(
                                                      (item) =>
                                                          item.id !== tag.id,
                                                  ),
                                              )
                                    }
                                >
                                    <X />
                                </IconButton>
                            ) : null}
                        </TagBadge>
                    ))}
                    {readOnly && selected.length === 0 ? (
                        <span className="text-sm text-muted-foreground">
                            Nessun tag
                        </span>
                    ) : null}
                </div>
                {!readOnly ? (
                    <>
                        <Label htmlFor={`${id}-tag-search`} className="sr-only">
                            Cerca o crea tag
                        </Label>
                        <Combobox
                            multiple
                            openOnInputClick
                            items={matching}
                            value={selected}
                            inputValue={search}
                            onInputValueChange={setSearch}
                            onValueChange={(items) =>
                                setSelected(
                                    items.map(
                                        (tag) =>
                                            selected.find(
                                                (item) => item.id === tag.id,
                                            ) ?? tag,
                                    ),
                                )
                            }
                            itemToStringLabel={(tag) => tag.name}
                            isItemEqualToValue={(item, value) =>
                                item.id === value.id
                            }
                            filter={null}
                            disabled={disabled}
                        >
                            <ComboboxInput
                                id={`${id}-tag-search`}
                                maxLength={255}
                                placeholder="Cerca e seleziona tag…"
                                className="w-full"
                                disabled={disabled}
                                aria-invalid={Boolean(tagError)}
                                aria-describedby={`${id}-tag-error`}
                            />
                            <ComboboxContent container={tagContainer}>
                                <ComboboxEmpty>
                                    Nessun tag trovato
                                </ComboboxEmpty>
                                <ComboboxList>
                                    {(tag: Tag) => (
                                        <ComboboxItem key={tag.id} value={tag}>
                                            <TagBadge
                                                color={
                                                    selected.find(
                                                        (item) =>
                                                            item.id === tag.id,
                                                    )?.color ?? tag.color
                                                }
                                            >
                                                {tag.name}
                                            </TagBadge>
                                        </ComboboxItem>
                                    )}
                                </ComboboxList>
                            </ComboboxContent>
                        </Combobox>
                        {canAdd && !existing ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="w-fit max-w-full"
                                disabled={disabled}
                                onClick={addTag}
                            >
                                <Plus data-icon="inline-start" />
                                <span className="truncate">Crea «{name}»</span>
                            </Button>
                        ) : null}
                    </>
                ) : null}
                <InputError id={`${id}-tag-error`} message={tagError} />
            </FieldSet>
        </>
    );
}
