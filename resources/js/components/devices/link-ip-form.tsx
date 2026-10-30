import { Form } from '@inertiajs/react';
import { useId, useState } from 'react';
import { Link2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
    DialogClose,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import InputError from '@/components/input-error';
import { store } from '@/routes/companies/sites/devices/ip-addresses';
import { interfaceName, type DeviceInterface } from '@/types/device';
import type { Port } from '@/lib/hardware-editor/document';
export type AvailableIp = {
    id: number;
    ip: string;
    hostname: string | null;
    description: string | null;
    subnet: { id: number; cidr: string };
};
export function LinkIpForm({
    company,
    site,
    device,
    interfaces,
    ports,
    ips,
    initialInterface,
}: {
    company: number;
    site: number;
    device: number;
    interfaces: DeviceInterface[];
    ports: Port[];
    ips: AvailableIp[];
    initialInterface?: number;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [selection, setSelection] = useState('');
    const [interfaceId, setInterfaceId] = useState(
        String(initialInterface ?? 'none'),
    );
    const filtered = ips.filter(
        (ip) =>
            [ip.ip, ip.hostname, ip.description, ip.subnet.cidr]
                .join(' ')
                .toLowerCase()
                .includes(search.toLowerCase()) || String(ip.id) === selection,
    );
    return (
        <Dialog
            open={open}
            onOpenChange={(v) => {
                setOpen(v);
                if (v) {
                    setSearch('');
                    setSelection('');
                    setInterfaceId(String(initialInterface ?? 'none'));
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <Link2 data-icon="inline-start" />
                    Collega IP
                </Button>
            </DialogTrigger>
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {initialInterface
                            ? `Collega IP · ${interfaceName(
                                  interfaces.find(
                                      (item) => item.id === initialInterface,
                                  )!,
                                  ports,
                              )}`
                            : 'Collega IP documentato'}
                    </DialogTitle>
                </DialogHeader>
                <Form
                    {...store.form({ company, site, device })}
                    onSuccess={() => setOpen(false)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <FieldGroup>
                            <Field>
                                <FieldLabel htmlFor={`${id}-ip-search`}>
                                    Cerca IP
                                </FieldLabel>
                                <Input
                                    id={`${id}-ip-search`}
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    autoFocus
                                    placeholder="IP, hostname o subnet"
                                />
                            </Field>
                            <Field data-invalid={!!errors.ip_address_id}>
                                <FieldLabel htmlFor={`${id}-linked-ip`}>
                                    IP *
                                </FieldLabel>
                                <Select
                                    name="ip_address_id"
                                    value={selection}
                                    onValueChange={setSelection}
                                    required
                                    disabled={processing}
                                >
                                    <SelectTrigger
                                        id={`${id}-linked-ip`}
                                        className="w-full"
                                        aria-invalid={!!errors.ip_address_id}
                                    >
                                        <SelectValue
                                            placeholder={
                                                filtered.length
                                                    ? 'Scegli IP'
                                                    : 'Nessun IP disponibile'
                                            }
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {filtered.map((ip) => (
                                                <SelectItem
                                                    key={ip.id}
                                                    value={String(ip.id)}
                                                >
                                                    {ip.ip} ·{' '}
                                                    {ip.hostname ||
                                                        ip.subnet.cidr}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.ip_address_id} />
                            </Field>
                            {initialInterface ? (
                                <>
                                    <input
                                        type="hidden"
                                        name="device_interface_id"
                                        value={initialInterface}
                                    />
                                    <InputError
                                        message={errors.device_interface_id}
                                    />
                                </>
                            ) : (
                                <Field
                                    data-invalid={!!errors.device_interface_id}
                                >
                                    <FieldLabel
                                        htmlFor={`${id}-linked-interface`}
                                    >
                                        Interfaccia
                                    </FieldLabel>
                                    <input
                                        type="hidden"
                                        name="device_interface_id"
                                        value={
                                            interfaceId === 'none'
                                                ? ''
                                                : interfaceId
                                        }
                                    />
                                    <Select
                                        value={interfaceId}
                                        onValueChange={setInterfaceId}
                                        disabled={processing}
                                    >
                                        <SelectTrigger
                                            id={`${id}-linked-interface`}
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                <SelectItem value="none">
                                                    Solo dispositivo
                                                </SelectItem>
                                                {interfaces.map((i) => (
                                                    <SelectItem
                                                        key={i.id}
                                                        value={String(i.id)}
                                                    >
                                                        {interfaceName(
                                                            i,
                                                            ports,
                                                        )}
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errors.device_interface_id}
                                    />
                                </Field>
                            )}
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Annulla
                                    </Button>
                                </DialogClose>
                                <Button disabled={processing || !selection}>
                                    Collega
                                </Button>
                            </DialogFooter>
                        </FieldGroup>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
