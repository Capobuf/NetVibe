import { useForm } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import {
    activate,
    refresh,
    resolve,
} from '@/actions/App/Http/Controllers/UnifiInventoryController';
import { DeviceTable } from '@/components/devices/device-table';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import TextLink from '@/components/text-link';
import { formatDateTime } from '@/lib/utils';
import { show } from '@/routes/companies/sites/devices';
import { UnifiMark } from '@/components/unifi-mark';
import {
    unifiState,
    unifiType,
    type UnifiConnection,
    type UnifiInventoryDevice,
    type UnifiSite,
} from './types';

export function UnifiInventoryPreview({
    connection,
    readonly,
}: {
    connection: UnifiConnection;
    readonly: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [selection, setSelection] = useState<{
        site: UnifiSite;
        device: UnifiInventoryDevice;
    } | null>(null);
    const form = useForm({
        revision: connection.revision ?? '',
        confirmed: true,
    });
    const sites = connection.sites.filter(
        (site) => site.site && site.confirmed_at,
    );
    if (!sites.length) return null;
    const activated = sites.every((site) => site.inventory?.activated_at);
    const ready = sites.every(
        (site) =>
            site.available &&
            site.inventory?.last_synced_at &&
            site.inventory.status === 'ok',
    );
    function submit(action: 'refresh' | 'activate') {
        form.transform(() => ({
            revision: connection.revision,
            confirmed: true,
        }));
        form.submit(
            action === 'refresh'
                ? refresh(connection.id)
                : activate(connection.id),
            {
                preserveScroll: true,
            },
        );
    }
    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                <UnifiMark data-icon="inline-start" />
                {activated
                    ? 'Inventario e sincronizzazione'
                    : 'Anteprima inventario'}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="flex max-h-[90dvh] flex-col sm:max-w-6xl">
                    <DialogHeader>
                        <DialogTitle>
                            {activated
                                ? 'Inventario UniFi'
                                : 'Attiva inventario UniFi'}
                        </DialogTitle>
                        <DialogDescription>{connection.name}</DialogDescription>
                    </DialogHeader>
                    <div className="flex min-h-0 flex-col gap-6 overflow-y-auto">
                        {sites.map((site, index) => (
                            <section
                                key={site.id}
                                className="flex min-w-0 flex-col gap-4"
                            >
                                {index > 0 ? <Separator /> : null}
                                <header className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h3 className="font-medium">
                                            {site.name}
                                        </h3>
                                        <p className="text-sm text-muted-foreground">
                                            {[
                                                site.site?.company.name,
                                                site.site?.name,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {site.inventory?.last_synced_at
                                            ? `Ultima lettura: ${formatDateTime(site.inventory.last_synced_at)}`
                                            : 'Anteprima da acquisire'}
                                        {site.inventory?.status &&
                                        site.inventory.status !== 'ok'
                                            ? ' · Non aggiornato'
                                            : ''}
                                    </p>
                                </header>
                                <DeviceTable
                                    name="unifi-inventory"
                                    variant="list"
                                    searchLabel="Cerca apparati UniFi"
                                    rows={site.inventory?.devices ?? []}
                                    columns={[
                                        {
                                            id: 'name',
                                            label: 'Nome UniFi',
                                            visible: true,
                                        },
                                        {
                                            id: 'model',
                                            label: 'Modello',
                                            visible: true,
                                        },
                                        {
                                            id: 'type',
                                            label: 'Tipo',
                                            visible: true,
                                        },
                                        {
                                            id: 'ip_address',
                                            label: 'IP gestione',
                                            visible: true,
                                        },
                                        {
                                            id: 'mac_address',
                                            label: 'MAC',
                                            visible: true,
                                        },
                                        {
                                            id: 'state',
                                            label: 'Stato',
                                            visible: true,
                                        },
                                        {
                                            id: 'device',
                                            label: 'Dispositivo NetVibe',
                                            visible: true,
                                        },
                                    ]}
                                    value={(row, column) =>
                                        column === 'device'
                                            ? (row.device?.name ??
                                              row.candidates
                                                  .map(
                                                      (candidate) =>
                                                          candidate.name,
                                                  )
                                                  .join(', '))
                                            : column === 'type'
                                              ? unifiType(row.observed.features)
                                              : column === 'state'
                                                ? unifiState(
                                                      row.observed.state,
                                                      !connection.enabled ||
                                                          connection.check_status !==
                                                              'ok' ||
                                                          !site.available ||
                                                          !row.present ||
                                                          site.inventory
                                                              ?.status !== 'ok',
                                                  )
                                                : row.observed[column]
                                    }
                                    render={(row, column) =>
                                        column === 'device' ? (
                                            row.device && site.site ? (
                                                <TextLink
                                                    href={show({
                                                        company:
                                                            site.site.company
                                                                .id,
                                                        site: site.site.id,
                                                        device: row.device.id,
                                                    })}
                                                >
                                                    {row.device.name}
                                                </TextLink>
                                            ) : (
                                                <div className="flex flex-col gap-1">
                                                    <span>
                                                        {row.status ===
                                                        'ignored'
                                                            ? 'Ignorato'
                                                            : row.status ===
                                                                'deleted'
                                                              ? 'Eliminato da NetVibe'
                                                              : row.candidates
                                                                      .length
                                                                ? row.candidates
                                                                      .map(
                                                                          (
                                                                              candidate,
                                                                          ) =>
                                                                              candidate.name,
                                                                      )
                                                                      .join(
                                                                          ', ',
                                                                      )
                                                                : 'Nuovo dispositivo'}
                                                    </span>
                                                    {row.problem ? (
                                                        <span className="text-xs text-muted-foreground">
                                                            {row.problem}
                                                        </span>
                                                    ) : null}
                                                </div>
                                            )
                                        ) : column === 'type' ? (
                                            unifiType(row.observed.features) ||
                                            '—'
                                        ) : column === 'state' ? (
                                            unifiState(
                                                row.observed.state,
                                                !connection.enabled ||
                                                    connection.check_status !==
                                                        'ok' ||
                                                    !site.available ||
                                                    !row.present ||
                                                    site.inventory?.status !==
                                                        'ok',
                                            )
                                        ) : (
                                            row.observed[column] || '—'
                                        )
                                    }
                                    actions={(device) =>
                                        !readonly &&
                                        device.status !== 'linked' &&
                                        (device.candidates.length > 0 ||
                                            device.status !== 'pending' ||
                                            device.problem) ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    !connection.enabled ||
                                                    !device.present ||
                                                    site.inventory?.status !==
                                                        'ok'
                                                }
                                                onClick={() =>
                                                    setSelection({
                                                        site,
                                                        device,
                                                    })
                                                }
                                            >
                                                Scegli
                                            </Button>
                                        ) : null
                                    }
                                    empty={
                                        site.inventory?.last_synced_at
                                            ? 'Nessun apparato adottato'
                                            : 'Aggiorna l’anteprima per acquisire gli apparati'
                                    }
                                />
                            </section>
                        ))}
                        {form.hasErrors ? (
                            <Alert variant="destructive">
                                <AlertDescription>
                                    {Object.values(form.errors).join(' ')}
                                </AlertDescription>
                            </Alert>
                        ) : null}
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Chiudi
                        </Button>
                        {!readonly ? (
                            <>
                                <Button
                                    variant="outline"
                                    disabled={
                                        !connection.enabled || form.processing
                                    }
                                    onClick={() => submit('refresh')}
                                >
                                    {form.processing ? (
                                        <Spinner />
                                    ) : (
                                        <RefreshCw data-icon="inline-start" />
                                    )}
                                    {activated
                                        ? 'Sincronizza ora'
                                        : 'Aggiorna anteprima'}
                                </Button>
                                {!activated ? (
                                    <Button
                                        disabled={
                                            !connection.enabled ||
                                            !ready ||
                                            form.processing
                                        }
                                        onClick={() => submit('activate')}
                                    >
                                        Attiva importazione
                                    </Button>
                                ) : null}
                            </>
                        ) : null}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {selection ? (
                <ResolveDevice
                    key={selection.device.id}
                    connection={connection}
                    {...selection}
                    onClose={() => setSelection(null)}
                />
            ) : null}
        </>
    );
}

function ResolveDevice({
    connection,
    site,
    device,
    onClose,
}: {
    connection: UnifiConnection;
    site: UnifiSite;
    device: UnifiInventoryDevice;
    onClose: () => void;
}) {
    const candidates = device.candidates.filter(
        (candidate) => candidate.current_site,
    );
    const form = useForm({
        revision: connection.revision ?? '',
        action: 'link',
        device_id: '',
        confirmed_relink: false,
    });
    const candidate = candidates.find(
        (item) => String(item.id) === form.data.device_id,
    );
    function submit(action: 'link' | 'create' | 'ignore') {
        form.transform((data) => ({
            ...data,
            revision: connection.revision,
            action,
        }));
        form.submit(
            resolve({ unifiConnection: connection.id, unifiDevice: device.id }),
            {
                preserveScroll: true,
                onSuccess: onClose,
            },
        );
    }
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !form.processing) onClose();
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {device.observed.name ?? device.external_id}
                    </DialogTitle>
                    <DialogDescription>
                        {[site.site?.company.name, site.site?.name]
                            .filter(Boolean)
                            .join(' · ')}
                    </DialogDescription>
                </DialogHeader>
                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                    <dt className="text-muted-foreground">Modello</dt>
                    <dd>{device.observed.model ?? '—'}</dd>
                    <dt className="text-muted-foreground">MAC</dt>
                    <dd>{device.observed.mac_address ?? '—'}</dd>
                </dl>
                {device.problem ? (
                    <Alert>
                        <AlertDescription>{device.problem}</AlertDescription>
                    </Alert>
                ) : null}
                {candidates.length ? (
                    <Field>
                        <FieldLabel htmlFor={`unifi-candidate-${device.id}`}>
                            Dispositivo NetVibe
                        </FieldLabel>
                        <Select
                            value={form.data.device_id}
                            onValueChange={(value) => {
                                form.setData('device_id', value);
                                form.setData('confirmed_relink', false);
                            }}
                        >
                            <SelectTrigger id={`unifi-candidate-${device.id}`}>
                                <SelectValue placeholder="Seleziona il dispositivo" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    {candidates.map((candidate) => (
                                        <SelectItem
                                            key={candidate.id}
                                            value={String(candidate.id)}
                                        >
                                            {candidate.name}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </Field>
                ) : null}
                {candidate?.requires_relink ? (
                    <Alert>
                        <AlertDescription className="flex flex-col gap-3">
                            <p>
                                Il collegamento UniFi attuale (
                                {candidate.linked_source}) verrà sostituito.
                            </p>
                            <Field orientation="horizontal">
                                <Checkbox
                                    id={`unifi-relink-${device.id}`}
                                    checked={form.data.confirmed_relink}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'confirmed_relink',
                                            checked === true,
                                        )
                                    }
                                />
                                <FieldLabel
                                    htmlFor={`unifi-relink-${device.id}`}
                                >
                                    Confermo il ricollegamento
                                </FieldLabel>
                            </Field>
                        </AlertDescription>
                    </Alert>
                ) : null}
                {form.hasErrors ? (
                    <Alert variant="destructive">
                        <AlertDescription>
                            {Object.values(form.errors).join(' ')}
                        </AlertDescription>
                    </Alert>
                ) : null}
                <DialogFooter className="flex-wrap">
                    <Button
                        variant="ghost"
                        disabled={form.processing}
                        onClick={() => submit('ignore')}
                    >
                        Ignora
                    </Button>
                    <Button
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => submit('create')}
                    >
                        Crea nuovo
                    </Button>
                    {candidates.length ? (
                        <Button
                            disabled={
                                form.processing ||
                                !form.data.device_id ||
                                (candidate?.requires_relink &&
                                    !form.data.confirmed_relink)
                            }
                            onClick={() => submit('link')}
                        >
                            {form.processing ? <Spinner /> : null}Collega
                        </Button>
                    ) : null}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
