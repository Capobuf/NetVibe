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
import {
    interfaceName,
    type Device,
    type DeviceInterface,
} from '@/types/device';
import type { IpAddress } from '@/types/network';
export function IpAssignmentFields({
    devices,
    ipAddress,
    disabled,
    errors,
    onInterfaceChange,
    onAssignmentChange,
}: {
    devices: Device[];
    ipAddress?: Pick<IpAddress, 'device_id' | 'device_interface_id'>;
    onInterfaceChange?: (item: DeviceInterface | undefined) => void;
    onAssignmentChange?: (
        deviceId: number | null,
        interfaceId: number | null,
    ) => void;
    disabled: boolean;
    errors: Record<string, string>;
}) {
    const id = useId();
    const [deviceId, setDeviceId] = useState(
        String(ipAddress?.device_id ?? 'none'),
    );
    const [interfaceId, setInterfaceId] = useState(
        String(ipAddress?.device_interface_id ?? 'none'),
    );
    const device = devices.find((d) => String(d.id) === deviceId);
    const ports = device?.model?.panel
        ? [
              ...device.model.panel.faces.front.ports,
              ...device.model.panel.faces.rear.ports,
          ]
        : [];
    return (
        <div className="grid gap-5 sm:grid-cols-2">
            <Field data-invalid={!!errors.device_id}>
                <FieldLabel htmlFor={`${id}-device`}>Dispositivo</FieldLabel>
                <input
                    type="hidden"
                    name="device_id"
                    value={deviceId === 'none' ? '' : deviceId}
                />
                <Select
                    value={deviceId}
                    onValueChange={(v) => {
                        setDeviceId(v);
                        setInterfaceId('none');
                        onInterfaceChange?.(undefined);
                        onAssignmentChange?.(
                            v === 'none' ? null : Number(v),
                            null,
                        );
                    }}
                    disabled={disabled}
                >
                    <SelectTrigger
                        id={`${id}-device`}
                        className="w-full"
                        aria-invalid={!!errors.device_id}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectItem value="none">
                                Nessun dispositivo
                            </SelectItem>
                            {devices.map((d) => (
                                <SelectItem key={d.id} value={String(d.id)}>
                                    {d.name}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <InputError message={errors.device_id} />
            </Field>
            <Field data-invalid={!!errors.device_interface_id}>
                <FieldLabel htmlFor={`${id}-interface`}>Interfaccia</FieldLabel>
                <input
                    type="hidden"
                    name="device_interface_id"
                    value={interfaceId === 'none' ? '' : interfaceId}
                />
                <Select
                    value={interfaceId}
                    onValueChange={(value) => {
                        setInterfaceId(value);
                        onAssignmentChange?.(
                            device?.id ?? null,
                            value === 'none' ? null : Number(value),
                        );
                        onInterfaceChange?.(
                            device?.interfaces.find(
                                (item) => String(item.id) === value,
                            ),
                        );
                    }}
                    disabled={disabled || !device}
                >
                    <SelectTrigger
                        id={`${id}-interface`}
                        className="w-full"
                        aria-invalid={!!errors.device_interface_id}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectItem value="none">
                                Nessuna interfaccia
                            </SelectItem>
                            {device?.interfaces.map((i) => (
                                <SelectItem key={i.id} value={String(i.id)}>
                                    {interfaceName(i, ports)}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <InputError message={errors.device_interface_id} />
            </Field>
        </div>
    );
}
