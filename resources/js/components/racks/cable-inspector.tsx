import { useId, useRef } from 'react';
import { Cable as CableIcon, Check, Unplug, X } from 'lucide-react';
import { Choice } from '@/components/hardware-editor/editor-fields';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { ColorField } from '@/components/color-field';
import { Textarea } from '@/components/ui/textarea';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
import {
    cableCode,
    cableTypes,
    connectionTypes,
    compatibleCableTypes,
    normalizeCableType,
    devicePorts,
    endKey,
    endLabel,
    type Cable,
    type CableEnd,
} from '@/lib/rack-cabling';
import { connectorTypes } from '@/lib/hardware-editor/document';
import type { RackDevice } from '@/types/rack';

export function EndpointPicker({
    label,
    value,
    devices,
    cables,
    cableId,
    disabled,
    otherEnd,
    allowedDeviceIds,
    onChange,
}: {
    label: string;
    value: CableEnd;
    devices: RackDevice[];
    cables: Cable[];
    cableId: number;
    disabled: boolean;
    otherEnd?: CableEnd;
    allowedDeviceIds?: number[];
    onChange: (end: CableEnd) => void;
}) {
    const id = useId();
    // Keep both popups inside the dialog's focus and pointer boundary.
    const popupContainer = useRef<HTMLDivElement>(null);
    const device = devices.find((d) => d.id === value.device_id);
    const ports = devicePorts(device);
    const options = devices.filter(
        (d) =>
            devicePorts(d).length > 0 &&
            (!allowedDeviceIds || allowedDeviceIds.includes(d.id)),
    );
    const otherPort =
        otherEnd &&
        devicePorts(devices.find((d) => d.id === otherEnd.device_id)).find(
            (p) => p.id === otherEnd.connector_id,
        );
    return (
        <FieldGroup ref={popupContainer} className="gap-3">
            <Field>
                <FieldLabel htmlFor={`${id}-device`}>
                    {label} · Dispositivo
                </FieldLabel>
                <Combobox
                    items={options}
                    value={device ?? null}
                    disabled={disabled}
                    openOnInputClick
                    itemToStringLabel={(d) =>
                        `${d.name} · ${d.rack_item?.rack.name ?? 'Fuori rack'}`
                    }
                    isItemEqualToValue={(a, b) => a.id === b.id}
                    onValueChange={(d) =>
                        onChange({ device_id: d?.id ?? 0, connector_id: '' })
                    }
                >
                    <ComboboxInput
                        id={`${id}-device`}
                        placeholder="Cerca dispositivo…"
                        showClear
                        disabled={disabled}
                    />
                    <ComboboxContent container={popupContainer}>
                        <ComboboxEmpty>Nessun dispositivo</ComboboxEmpty>
                        <ComboboxList>
                            {(d: RackDevice) => (
                                <ComboboxItem key={d.id} value={d}>
                                    {d.name} ·{' '}
                                    {d.rack_item?.rack.name ?? 'Fuori rack'}
                                </ComboboxItem>
                            )}
                        </ComboboxList>
                    </ComboboxContent>
                </Combobox>
            </Field>
            <Field>
                <FieldLabel htmlFor={`${id}-port`}>Porta</FieldLabel>
                <Combobox
                    items={ports}
                    value={
                        ports.find((p) => p.id === value.connector_id) ?? null
                    }
                    disabled={disabled || !device}
                    openOnInputClick
                    itemToStringLabel={(p) =>
                        `${p.label || p.id.slice(0, 8)} · ${p.face === 'front' ? 'Fronte' : 'Retro'} · ${connectorTypes[p.type].name}`
                    }
                    isItemEqualToValue={(a, b) => a.id === b.id}
                    onValueChange={(p) =>
                        onChange({ ...value, connector_id: p?.id ?? '' })
                    }
                >
                    <ComboboxInput
                        id={`${id}-port`}
                        placeholder="Cerca porta…"
                        showClear
                        disabled={disabled || !device}
                    />
                    <ComboboxContent container={popupContainer}>
                        <ComboboxEmpty>Nessuna porta</ComboboxEmpty>
                        <ComboboxList>
                            {(p: (typeof ports)[number]) => {
                                const occupied = cables.find(
                                    (c) =>
                                        c.id !== cableId &&
                                        [c.a, c.b].some(
                                            (end) =>
                                                endKey(end) ===
                                                endKey({
                                                    device_id: value.device_id,
                                                    connector_id: p.id,
                                                }),
                                        ),
                                );
                                const incompatible =
                                    otherPort &&
                                    ((otherEnd?.device_id === value.device_id &&
                                        otherPort.id === p.id) ||
                                        !connectionTypes(p.type).some((type) =>
                                            connectionTypes(
                                                otherPort.type,
                                            ).includes(type),
                                        ));
                                return (
                                    <ComboboxItem
                                        key={p.id}
                                        value={p}
                                        disabled={!!occupied || !!incompatible}
                                    >
                                        {p.label || p.id.slice(0, 8)} ·{' '}
                                        {p.face === 'front'
                                            ? 'Fronte'
                                            : 'Retro'}{' '}
                                        · {connectorTypes[p.type].name}
                                        {occupied
                                            ? ` · ${cableCode(occupied)}`
                                            : incompatible
                                              ? ' · Incompatibile'
                                              : ''}
                                    </ComboboxItem>
                                );
                            }}
                        </ComboboxList>
                    </ComboboxContent>
                </Combobox>
            </Field>
        </FieldGroup>
    );
}

export function CableFields({
    cable,
    devices,
    disabled,
    onChange,
    className,
}: {
    cable: Cable;
    devices: RackDevice[];
    disabled: boolean;
    onChange: (cable: Cable) => void;
    className?: string;
}) {
    const id = useId();
    const types = compatibleCableTypes(cable, devices);
    return (
        <FieldGroup className={className}>
            {types.length > 1 && (
                <div className="sm:col-span-2 sm:max-w-xs">
                    <Choice
                        label="Tipo"
                        value={cable.type}
                        choices={Object.fromEntries(
                            types.map((type) => [type, cableTypes[type]]),
                        )}
                        disabled={disabled}
                        onChange={(type) =>
                            onChange({ ...cable, type: type as Cable['type'] })
                        }
                    />
                </div>
            )}
            <ColorField
                value={cable.color}
                disabled={disabled}
                onChange={(color) => onChange({ ...cable, color })}
            />
            <Field>
                <FieldLabel htmlFor={`${id}-purpose`}>Scopo</FieldLabel>
                <Textarea
                    id={`${id}-purpose`}
                    value={cable.purpose ?? ''}
                    maxLength={10000}
                    disabled={disabled}
                    onChange={(e) =>
                        onChange({ ...cable, purpose: e.target.value || null })
                    }
                />
            </Field>
        </FieldGroup>
    );
}

export function CableInspector({
    cable,
    devices,
    cables,
    editing,
    pending,
    disabled,
    onChange,
    onConfirm,
    onCancel,
    onDisconnect,
}: {
    cable: Cable;
    devices: RackDevice[];
    cables: Cable[];
    editing: boolean;
    pending: boolean;
    disabled: boolean;
    onChange: (cable: Cable) => void;
    onConfirm: () => void;
    onCancel: () => void;
    onDisconnect: () => void;
}) {
    return (
        <div className="flex flex-col gap-4 p-4">
            <div className="flex items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <CableIcon className="size-4" />
                    {cableCode(cable)}
                </h2>
                <Badge variant="outline">
                    {pending ? 'Da confermare' : 'Documentato'}
                </Badge>
            </div>
            {editing ? (
                <FieldGroup className="gap-5">
                    {(['a', 'b'] as const).map((side) => (
                        <EndpointPicker
                            key={side}
                            label={side.toUpperCase()}
                            value={cable[side]}
                            devices={devices}
                            cables={cables}
                            cableId={cable.id}
                            disabled={disabled}
                            onChange={(end) =>
                                onChange(
                                    normalizeCableType(
                                        { ...cable, [side]: end },
                                        devices,
                                    ),
                                )
                            }
                        />
                    ))}
                    <CableFields
                        cable={cable}
                        devices={devices}
                        disabled={disabled}
                        onChange={onChange}
                    />
                </FieldGroup>
            ) : (
                <dl className="flex flex-col gap-3 text-sm">
                    <div>
                        <dt className="text-xs text-muted-foreground">A</dt>
                        <dd>{endLabel(cable.a, devices)}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">B</dt>
                        <dd>{endLabel(cable.b, devices)}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">Tipo</dt>
                        <dd>{cableTypes[cable.type]}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Colore
                        </dt>
                        <dd className="flex items-center gap-2">
                            <span
                                className="size-3 rounded-full border"
                                style={{ background: cable.color }}
                            />
                            {cable.color}
                        </dd>
                    </div>
                    {cable.purpose && (
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Scopo
                            </dt>
                            <dd className="break-words whitespace-pre-wrap">
                                {cable.purpose}
                            </dd>
                        </div>
                    )}
                </dl>
            )}
            {pending ? (
                <div className="flex flex-wrap gap-2">
                    <Button disabled={disabled} onClick={onConfirm}>
                        <Check />
                        Conferma cavo
                    </Button>
                    <Button
                        variant="outline"
                        disabled={disabled}
                        onClick={onCancel}
                    >
                        <X />
                        Annulla
                    </Button>
                </div>
            ) : (
                editing && (
                    <Button
                        variant="outline"
                        disabled={disabled}
                        onClick={onDisconnect}
                    >
                        <Unplug />
                        Scollega cavo
                    </Button>
                )
            )}
        </div>
    );
}
