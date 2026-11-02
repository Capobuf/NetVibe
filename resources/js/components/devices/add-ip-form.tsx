import { SaveButton } from '@/components/save-button';
import { MacConfirmation } from '@/components/devices/mac-confirmation';
import { Form } from '@inertiajs/react';
import { useId, useState } from 'react';
import { Plus } from 'lucide-react';
import IpAddressForm from '@/components/networks/ip-address-form';
import { ipv4Number, ipv4RangeSummary } from '@/lib/ipv4';
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
import { interfaceName, type Device } from '@/types/device';
import type { Subnet, ExcludedRange, IpStatus, Tag } from '@/types/network';
import type { Port } from '@/lib/hardware-editor/document';
export type AvailableIp = {
    id: number;
    ip: string;
    hostname: string | null;
    mac_address: string | null;
    description: string | null;
    subnet: { id: number; cidr: string };
};
export function AddIpForm({
    company,
    site,
    device,
    ports,
    ips,
    initialInterface,
    subnets,
    statuses,
    tags,
}: {
    company: number;
    site: number;
    device: Device;
    ports: Port[];
    ips: AvailableIp[];
    initialInterface?: number;
    subnets: (Subnet & { excludedRanges: ExcludedRange[] })[];
    statuses: IpStatus[];
    tags: Tag[];
}) {
    const interfaces = device.interfaces;
    const id = useId();
    const [open, setOpen] = useState(false);
    const [creating, setCreating] = useState(false);
    const [subnetId, setSubnetId] = useState('');
    const subnet = subnets.find((item) => String(item.id) === subnetId);
    const [search, setSearch] = useState('');
    const [selection, setSelection] = useState('');
    const [interfaceId, setInterfaceId] = useState(
        String(initialInterface ?? 'none'),
    );
    const query = search.trim();
    const filtered = ips.filter((ip) =>
        [ip.ip, ip.hostname, ip.description, ip.subnet.cidr]
            .join(' ')
            .toLowerCase()
            .includes(query.toLowerCase()),
    );
    const canCreate =
        ipv4Number(query) !== null && !ips.some((ip) => ip.ip === query);
    const matchingSubnets = canCreate
        ? subnets.filter(
              (item) => ipv4RangeSummary(item.cidr, query, query) !== null,
          )
        : [];
    function backToSearch() {
        setCreating(false);
        setSelection('');
        requestAnimationFrame(() =>
            document.getElementById(`${id}-ip-search`)?.focus(),
        );
    }
    function changeOpen(value: boolean) {
        setOpen(value);
        if (value) {
            setCreating(false);
            setSubnetId('');
            setSearch('');
            setSelection('');
            setInterfaceId(String(initialInterface ?? 'none'));
        }
    }
    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <Plus data-icon="inline-start" />
                    Aggiungi IP
                </Button>
            </DialogTrigger>
            <DialogContent
                aria-describedby={undefined}
                className="max-h-[90dvh] overflow-y-auto sm:max-w-xl"
            >
                <DialogHeader>
                    <DialogTitle>
                        {initialInterface
                            ? `Aggiungi IP · ${interfaceName(
                                  interfaces.find(
                                      (item) => item.id === initialInterface,
                                  )!,
                                  ports,
                              )}`
                            : 'Aggiungi IP'}
                    </DialogTitle>
                </DialogHeader>
                {!selection && !creating ? (
                    <div className="flex flex-col gap-3">
                        <Field>
                            <FieldLabel htmlFor={`${id}-ip-search`}>
                                Cerca IP
                            </FieldLabel>
                            <Input
                                id={`${id}-ip-search`}
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                autoFocus
                                autoComplete="off"
                                placeholder="IP, hostname o subnet"
                            />
                        </Field>
                        {query && (
                            <>
                                {filtered.length ? (
                                    <ul
                                        aria-label="IP disponibili"
                                        className="max-h-64 overflow-y-auto rounded-lg border p-1"
                                    >
                                        {filtered.map((ip) => (
                                            <li key={ip.id}>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    className="h-auto w-full justify-start gap-3 px-3 py-2 text-left"
                                                    onClick={() =>
                                                        setSelection(
                                                            String(ip.id),
                                                        )
                                                    }
                                                >
                                                    <span className="font-medium tabular-nums">
                                                        {ip.ip}
                                                    </span>
                                                    <span className="truncate text-muted-foreground">
                                                        {ip.hostname ||
                                                            ip.subnet.cidr}
                                                    </span>
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p
                                        role="status"
                                        className="text-sm text-muted-foreground"
                                    >
                                        Nessun IP collegabile trovato.
                                    </p>
                                )}
                                {canCreate &&
                                    (matchingSubnets.length ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="justify-start"
                                            onClick={() => {
                                                setSubnetId(
                                                    matchingSubnets.length === 1
                                                        ? String(
                                                              matchingSubnets[0]
                                                                  .id,
                                                          )
                                                        : '',
                                                );
                                                setCreating(true);
                                            }}
                                        >
                                            <Plus data-icon="inline-start" />
                                            Crea {query}
                                        </Button>
                                    ) : (
                                        <p
                                            role="status"
                                            className="text-sm text-muted-foreground"
                                        >
                                            L’IP non appartiene a nessuna subnet
                                            della sede.
                                        </p>
                                    ))}
                            </>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-col gap-6">
                        <div className="flex items-center justify-between gap-3">
                            <p className="font-medium tabular-nums">
                                {creating
                                    ? `Nuovo IP · ${query}`
                                    : ips.find(
                                          (ip) => String(ip.id) === selection,
                                      )?.ip}
                            </p>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                autoFocus
                                onClick={backToSearch}
                            >
                                Cambia IP
                            </Button>
                        </div>
                        {selection ? (
                            <Form
                                {...store.form({
                                    company,
                                    site,
                                    device: device.id,
                                })}
                                onSuccess={() => setOpen(false)}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing, errors }) => (
                                    <FieldGroup>
                                        <input
                                            type="hidden"
                                            name="ip_address_id"
                                            value={selection}
                                        />
                                        <InputError
                                            message={errors.ip_address_id}
                                        />
                                        {initialInterface ? (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="device_interface_id"
                                                    value={initialInterface}
                                                />
                                                <InputError
                                                    message={
                                                        errors.device_interface_id
                                                    }
                                                />
                                            </>
                                        ) : (
                                            <Field
                                                data-invalid={
                                                    !!errors.device_interface_id
                                                }
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
                                                    onValueChange={
                                                        setInterfaceId
                                                    }
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
                                                            {interfaces.map(
                                                                (i) => (
                                                                    <SelectItem
                                                                        key={
                                                                            i.id
                                                                        }
                                                                        value={String(
                                                                            i.id,
                                                                        )}
                                                                    >
                                                                        {interfaceName(
                                                                            i,
                                                                            ports,
                                                                        )}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectGroup>
                                                    </SelectContent>
                                                </Select>
                                                <InputError
                                                    message={
                                                        errors.device_interface_id
                                                    }
                                                />
                                            </Field>
                                        )}
                                        <MacConfirmation errors={errors} />
                                        <DialogFooter>
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                >
                                                    Annulla
                                                </Button>
                                            </DialogClose>
                                            <SaveButton
                                                processing={processing}
                                                disabled={!selection}
                                            >
                                                {errors.mac_confirmation
                                                    ? 'Sostituisci MAC'
                                                    : 'Collega'}
                                            </SaveButton>
                                        </DialogFooter>
                                    </FieldGroup>
                                )}
                            </Form>
                        ) : (
                            <>
                                {matchingSubnets.length > 1 && (
                                    <Field>
                                        <FieldLabel htmlFor={`${id}-subnet`}>
                                            Subnet *
                                        </FieldLabel>
                                        <Select
                                            value={subnetId}
                                            onValueChange={setSubnetId}
                                        >
                                            <SelectTrigger
                                                id={`${id}-subnet`}
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Scegli subnet" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectGroup>
                                                    {matchingSubnets.map(
                                                        (item) => (
                                                            <SelectItem
                                                                key={item.id}
                                                                value={String(
                                                                    item.id,
                                                                )}
                                                            >
                                                                {item.cidr}
                                                                {item.name
                                                                    ? ` · ${item.name}`
                                                                    : ''}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                )}
                                {subnet && (
                                    <IpAddressForm
                                        key={subnet.id}
                                        companyId={company}
                                        siteId={site}
                                        subnetId={subnet.id}
                                        suggestedIp={query}
                                        excludedRanges={subnet.excludedRanges}
                                        statuses={statuses}
                                        tags={tags}
                                        devices={[device]}
                                        assignment={{
                                            deviceId: device.id,
                                            interfaceId: initialInterface,
                                            label: device.name,
                                        }}
                                        embedded
                                        onOpenChange={setOpen}
                                    />
                                )}
                            </>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
