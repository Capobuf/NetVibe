// Issue #165 interaction prototype: modules and destinations live only in page state, nothing is saved.
import { useId, useState } from 'react';
import {
    Cable as CableIcon,
    PencilLine,
    RotateCcw,
    Server,
} from 'lucide-react';
import type { PortOverride } from '@/components/hardware-editor/port-symbol';
import type { CatalogModel } from '@/components/racks/quick-device-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Toggle } from '@/components/ui/toggle';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { connectorShapes } from '@/lib/hardware-editor/connector-shapes';
import {
    connectorTypes,
    type ConnectorType,
} from '@/lib/hardware-editor/document';
import {
    cableCode,
    devicePorts,
    endKey,
    endLabel,
    type Cable,
    type CableEnd,
} from '@/lib/rack-cabling';
import type { RackDevice } from '@/types/rack';

// Demonstrative inserts drawn inside the same keystone slot, not a compatibility catalog.
export const keystoneModules = {
    blank: {
        label: 'Vuoto',
        outline: 'M -15 -12 H 15 V 12 H -15 Z',
        detail: 'M -8 0 H 8',
        color: '#64748b',
    },
    rj45: {
        label: 'RJ45',
        ...connectorShapes.rj45,
        color: connectorTypes.rj45.color,
    },
    lc: {
        label: 'Fibra LC',
        outline: 'M -16 -10 H 16 V 10 H -16 Z',
        detail: 'M -12 -5 H -3 V 5 H -12 Z M 3 -5 H 12 V 5 H 3 Z',
        color: '#06b6d4',
    },
    hdmi: {
        label: 'HDMI',
        outline: 'M -14 -6 H 14 V 1 L 10 6 H -10 L -14 1 Z',
        detail: 'M -10 -2 H 10',
        color: '#a78bfa',
    },
    usb: {
        label: 'USB',
        ...connectorShapes.usbA,
        color: connectorTypes.usbA.color,
    },
} satisfies Record<string, PortOverride & { label: string }>;
export type KeystoneModule = keyof typeof keystoneModules;
export type Destination =
    | { kind: 'device'; deviceId: number }
    | { kind: 'text'; text: string };

export function defaultModule(type: ConnectorType): KeystoneModule {
    return type.startsWith('usb') ? 'usb' : 'rj45';
}

export function isPatchPanel(
    device: RackDevice | undefined,
    models: CatalogModel[],
) {
    const type = models.find((model) => model.id === device?.device_model_id)
        ?.model_type?.name;
    return !!type && /patch/i.test(type);
}

export function frontPositions(device: RackDevice) {
    return devicePorts(device).filter(
        (port) => port.face === 'front' && !port.hidden,
    );
}

function ModuleIcon({ module }: { module: KeystoneModule }) {
    const shape: PortOverride = keystoneModules[module];
    return (
        <svg viewBox="-18 -15 36 30" className="h-4 w-6" aria-hidden="true">
            <path
                d={shape.outline}
                fill="#17202c"
                stroke={shape.color}
                strokeWidth={1.5}
                strokeLinejoin="round"
            />
            {shape.detail && (
                <path
                    d={shape.detail}
                    fill="none"
                    stroke={shape.color}
                    strokeWidth={1.5}
                />
            )}
        </svg>
    );
}

type Option = {
    key: string;
    label: string;
    detail: string;
    destination: Destination;
};

function DestinationField({
    device,
    devices,
    value,
    onChange,
}: {
    device: RackDevice;
    devices: RackDevice[];
    value: Destination | null;
    onChange: (destination: Destination | null) => void;
}) {
    const id = useId();
    const options: Option[] = devices
        .filter((candidate) => candidate.id !== device.id)
        .map((candidate) => ({
            key: `device-${candidate.id}`,
            label: candidate.name,
            detail: candidate.rack_item?.rack.name ?? 'Fuori rack',
            destination: { kind: 'device', deviceId: candidate.id },
        }));
    const textOption = (text: string): Option => ({
        key: 'text',
        label: text,
        detail: 'Testo libero',
        destination: { kind: 'text', text },
    });
    const selected =
        value?.kind === 'text'
            ? textOption(value.text)
            : (options.find(
                  (option) =>
                      option.destination.kind === 'device' &&
                      option.destination.deviceId === value?.deviceId,
              ) ?? null);
    const [query, setQuery] = useState(selected?.label ?? '');
    const typed = query.trim();
    const known = options.some(
        (option) =>
            option.label.toLocaleLowerCase() === typed.toLocaleLowerCase(),
    );
    const items = typed && !known ? [...options, textOption(typed)] : options;
    return (
        <Field>
            <FieldLabel htmlFor={id}>Destinazione lato impianto</FieldLabel>
            <Combobox
                items={items}
                value={selected}
                inputValue={query}
                onInputValueChange={setQuery}
                openOnInputClick
                itemToStringLabel={(option) => option.label}
                isItemEqualToValue={(a, b) => a.key === b.key}
                onValueChange={(option) =>
                    onChange(option?.destination ?? null)
                }
            >
                <ComboboxInput
                    id={id}
                    maxLength={255}
                    placeholder="Cerca un dispositivo o descrivi…"
                    showClear
                />
                <ComboboxContent>
                    <ComboboxEmpty>Nessun dispositivo</ComboboxEmpty>
                    <ComboboxList>
                        {(option: Option) => (
                            <ComboboxItem key={option.key} value={option}>
                                {option.destination.kind === 'text' ? (
                                    <>
                                        <PencilLine className="text-muted-foreground" />
                                        Usa «{option.label}»
                                    </>
                                ) : (
                                    <>
                                        <Server className="text-muted-foreground" />
                                        {option.label} · {option.detail}
                                    </>
                                )}
                            </ComboboxItem>
                        )}
                    </ComboboxList>
                </ComboboxContent>
            </Combobox>
            {selected && (
                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    {selected.destination.kind === 'text' ? (
                        <PencilLine className="size-3.5" />
                    ) : (
                        <Server className="size-3.5" />
                    )}
                    {selected.detail}
                </p>
            )}
        </Field>
    );
}

export function PatchPanelInspector({
    device,
    positions,
    devices,
    cables,
    modules,
    destinations,
    editing,
    disabled,
    onSelect,
    onModule,
    onDestination,
    onCable,
    onConnect,
    onReset,
}: {
    device: RackDevice;
    positions: CableEnd[];
    devices: RackDevice[];
    cables: Cable[];
    modules: Record<string, KeystoneModule>;
    destinations: Record<string, Destination>;
    editing: boolean;
    disabled: boolean;
    onSelect: (positions: CableEnd[]) => void;
    onModule: (module: KeystoneModule) => void;
    onDestination: (end: CableEnd, destination: Destination | null) => void;
    onCable: (cable: Cable) => void;
    onConnect: (end: CableEnd) => void;
    onReset: () => void;
}) {
    const ports = frontPositions(device).map((port) => ({
        ...port,
        end: { device_id: device.id, connector_id: port.id },
    }));
    const keys = new Set(positions.map(endKey));
    const chosen = ports.filter((port) => keys.has(endKey(port.end)));
    const moduleOf = (port: (typeof ports)[number]) =>
        modules[endKey(port.end)] ?? defaultModule(port.type);
    const chosenModules = new Set(chosen.map(moduleOf));
    const single = chosen.length === 1 ? chosen[0] : null;
    const cableOf = (end: CableEnd) =>
        cables.find((cable) =>
            [cable.a, cable.b].some((e) => endKey(e) === endKey(end)),
        );
    const otherEnd = (cable: Cable, end: CableEnd) =>
        endKey(cable.a) === endKey(end) ? cable.b : cable.a;
    const destinationLabel = (destination?: Destination) =>
        !destination
            ? '—'
            : destination.kind === 'text'
              ? destination.text
              : (devices.find((d) => d.id === destination.deviceId)?.name ??
                '—');
    const changed =
        Object.keys(modules).length > 0 || Object.keys(destinations).length > 0;
    const singleCable = single && cableOf(single.end);
    return (
        <div className="flex flex-col gap-5 p-4">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <h2 className="truncate text-sm font-medium">
                        {device.name}
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {single
                            ? `Posizione ${single.label}`
                            : `${chosen.length} posizioni`}
                    </p>
                </div>
                <Badge variant="outline" className="shrink-0">
                    Prova non salvata
                </Badge>
            </div>
            <section className="flex flex-col gap-2" aria-label="Posizioni">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-sm font-medium">Posizioni</h3>
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={disabled || chosen.length === ports.length}
                        onClick={() => onSelect(ports.map((port) => port.end))}
                    >
                        Tutte
                    </Button>
                </div>
                <div className="grid grid-cols-6 gap-1">
                    {ports.map((port) => (
                        <Toggle
                            key={port.id}
                            size="sm"
                            variant="outline"
                            className="min-w-0 px-0 tabular-nums data-[state=on]:border-primary data-[state=on]:bg-primary/10 data-[state=on]:text-foreground"
                            pressed={keys.has(endKey(port.end))}
                            disabled={disabled}
                            aria-label={`Posizione ${port.label}`}
                            onPressedChange={(pressed) => {
                                if (pressed) onSelect([...positions, port.end]);
                                else if (positions.length > 1)
                                    onSelect(
                                        positions.filter(
                                            (end) =>
                                                endKey(end) !==
                                                endKey(port.end),
                                        ),
                                    );
                            }}
                        >
                            {port.label}
                        </Toggle>
                    ))}
                </div>
            </section>
            <section className="flex flex-col gap-2" aria-label="Modulo">
                <h3 className="text-sm font-medium">Modulo</h3>
                {editing ? (
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={
                            chosenModules.size === 1
                                ? [...chosenModules][0]
                                : ''
                        }
                        onValueChange={(value) => {
                            if (value) onModule(value as KeystoneModule);
                        }}
                        disabled={disabled}
                        aria-label="Modulo della posizione"
                        className="grid grid-cols-3 gap-1 shadow-none"
                    >
                        {(Object.keys(keystoneModules) as KeystoneModule[]).map(
                            (module) => (
                                <ToggleGroupItem
                                    key={module}
                                    value={module}
                                    className="h-auto flex-col gap-1 rounded-md py-2 data-[state=on]:border-primary data-[state=on]:bg-primary/10 data-[state=on]:text-foreground data-[variant=outline]:border-l"
                                >
                                    <ModuleIcon module={module} />
                                    <span className="text-xs">
                                        {keystoneModules[module].label}
                                    </span>
                                </ToggleGroupItem>
                            ),
                        )}
                    </ToggleGroup>
                ) : (
                    <p className="flex items-center gap-2 text-sm">
                        {chosenModules.size === 1 ? (
                            <>
                                <ModuleIcon module={[...chosenModules][0]} />
                                {keystoneModules[[...chosenModules][0]].label}
                            </>
                        ) : (
                            'Moduli diversi'
                        )}
                    </p>
                )}
            </section>
            <section
                className="flex flex-col gap-2"
                aria-label="Collegamento frontale"
            >
                <h3 className="text-sm font-medium">Collegamento frontale</h3>
                {single ? (
                    singleCable ? (
                        <Button
                            variant="outline"
                            className="h-auto justify-start py-2 text-left whitespace-normal"
                            disabled={disabled}
                            onClick={() => onCable(singleCable)}
                        >
                            <span
                                className="size-3 shrink-0 rounded-full border"
                                style={{ background: singleCable.color }}
                            />
                            <span className="min-w-0">
                                {cableCode(singleCable)}
                                <span className="block text-xs break-words">
                                    {endLabel(
                                        otherEnd(singleCable, single.end),
                                        devices,
                                    )}
                                </span>
                            </span>
                        </Button>
                    ) : (
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-sm text-muted-foreground">
                                Nessun cavo documentato
                            </p>
                            {editing && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={disabled}
                                    onClick={() => onConnect(single.end)}
                                >
                                    <CableIcon />
                                    Collega
                                </Button>
                            )}
                        </div>
                    )
                ) : (
                    <ul className="divide-y overflow-hidden rounded-lg border">
                        {chosen.map((port) => {
                            const cable = cableOf(port.end);
                            return (
                                <li key={port.id}>
                                    <Button
                                        variant="ghost"
                                        className="h-auto min-h-10 w-full justify-start rounded-none px-3 py-2 text-left whitespace-normal"
                                        disabled={disabled}
                                        onClick={() => onSelect([port.end])}
                                    >
                                        <span className="w-6 shrink-0 tabular-nums">
                                            {port.label}
                                        </span>
                                        <span className="min-w-0 text-xs break-words text-muted-foreground">
                                            {
                                                keystoneModules[moduleOf(port)]
                                                    .label
                                            }{' '}
                                            ·{' '}
                                            {cable
                                                ? `${cableCode(cable)} → ${endLabel(otherEnd(cable, port.end), devices)}`
                                                : 'Non collegata'}
                                        </span>
                                    </Button>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>
            {single &&
                (editing ? (
                    <DestinationField
                        key={endKey(single.end)}
                        device={device}
                        devices={devices}
                        value={destinations[endKey(single.end)] ?? null}
                        onChange={(destination) =>
                            onDestination(single.end, destination)
                        }
                    />
                ) : (
                    <section
                        className="flex flex-col gap-1"
                        aria-label="Destinazione lato impianto"
                    >
                        <h3 className="text-sm font-medium">
                            Destinazione lato impianto
                        </h3>
                        <p className="text-sm break-words">
                            {destinationLabel(destinations[endKey(single.end)])}
                        </p>
                    </section>
                ))}
            {editing && changed && (
                <Button
                    variant="ghost"
                    className="self-start"
                    disabled={disabled}
                    onClick={onReset}
                >
                    <RotateCcw />
                    Ripristina prova
                </Button>
            )}
        </div>
    );
}

export function moduleOverrides(
    devices: RackDevice[],
    modules: Record<string, KeystoneModule>,
) {
    const overrides = new Map<number, Map<string, PortOverride>>();
    for (const [key, module] of Object.entries(modules)) {
        const [deviceId, portId] = key.split(':');
        const device = devices.find((d) => d.id === Number(deviceId));
        const port =
            device && frontPositions(device).find((p) => p.id === portId);
        // The model already draws its own connector; only a different insert needs an override.
        if (!port || module === defaultModule(port.type)) continue;
        const map = overrides.get(device.id) ?? new Map();
        map.set(portId, keystoneModules[module]);
        overrides.set(device.id, map);
    }
    return overrides;
}
