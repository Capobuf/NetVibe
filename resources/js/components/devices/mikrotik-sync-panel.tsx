import { useForm, usePage, Link } from '@inertiajs/react';
import { useState } from 'react';
import { RefreshCw, Play } from 'lucide-react';
import { MikrotikMark } from '@/components/mikrotik-mark';
import type { MikrotikConnection } from '@/components/devices/mikrotik-device';
import { DeviceTable } from '@/components/devices/device-table';
import {
    SourceDetails,
    sourceLabel,
    type MikrotikSource,
} from '@/components/networks/mikrotik-source';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardHeader,
    CardTitle,
    CardContent,
    CardFooter,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import {
    activate,
    sync,
    associate,
} from '@/routes/companies/sites/devices/mikrotik';
import { show as subnetShow } from '@/routes/companies/sites/subnets';
import { show as vlanShow } from '@/routes/companies/sites/vlans';

type Source = MikrotikSource & { can_associate: boolean };
export function MikrotikSyncPanel({
    connection,
    disabled,
    route,
}: {
    connection: MikrotikConnection | null;
    disabled: boolean;
    route: { company: number; site: number; device: number };
}) {
    const { mikrotikSources: sources = [] } = usePage<{
        mikrotikSources: Source[];
    }>().props;
    const [allOpen, setAllOpen] = useState(false);
    const [selected, setSelected] = useState<Source | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const form = useForm({
        revision: connection?.revision ?? null,
        confirmed: true,
        source_id: 0,
    });
    const problems = sources.filter(
        (source) => source.problem && source.state !== 'missing',
    );
    const error = Object.values(form.errors)[0];
    const active = Boolean(connection?.activated_at);
    const busy = disabled || form.processing;
    const select = (source: Source) => {
        setSelected(source);
        setConfirmed(false);
        form.clearErrors();
    };
    return (
        <>
            <Card aria-busy={form.processing}>
                <CardHeader className="flex-row items-center justify-between gap-3">
                    <CardTitle className="flex items-center gap-2.5">
                        <MikrotikMark className="h-5" />
                        Sincronizzazione IPAM
                    </CardTitle>
                    <Badge variant={active ? 'secondary' : 'outline'}>
                        {active
                            ? 'Attiva · ogni ora'
                            : connection?.sync_status === 'suspended' ||
                                connection?.sync_status === 'source_changed'
                              ? 'Sospesa'
                              : 'Non attiva'}
                    </Badge>
                </CardHeader>
                <CardContent className="flex flex-col gap-4" aria-live="polite">
                    {connection?.synced_at ? (
                        <p className="text-sm">
                            Ultimo aggiornamento riuscito:{' '}
                            {new Date(connection.synced_at).toLocaleString(
                                'it-IT',
                            )}
                        </p>
                    ) : null}
                    {connection?.sync_attempted_at ? (
                        <p className="text-sm text-muted-foreground">
                            Ultimo tentativo:{' '}
                            {new Date(
                                connection.sync_attempted_at,
                            ).toLocaleString('it-IT')}{' '}
                            · {connection.sync_message}
                        </p>
                    ) : null}
                    {connection?.sync_summary ? (
                        <p className="text-sm">
                            {connection.sync_summary.read} elementi letti ·{' '}
                            {connection.sync_summary.updated} aggiornati ·{' '}
                            {connection.sync_summary.created} creati ·{' '}
                            {connection.sync_summary.associated} nuove
                            associazioni
                        </p>
                    ) : null}
                    {!active && connection ? (
                        <p className="text-sm">
                            {connection.sync_status === 'suspended' ||
                            connection.sync_status === 'source_changed'
                                ? 'Riconfermando la sorgente, le letture precedenti restano conservate con la loro provenienza.'
                                : 'L’attivazione avvia la prima acquisizione e gli aggiornamenti orari di VLAN, subnet e IP configurati.'}
                        </p>
                    ) : null}
                    {error && !selected ? (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    ) : null}
                    {problems.length ? (
                        <SourceTable
                            sources={problems}
                            onSelect={select}
                            name="mikrotik-problems"
                        />
                    ) : null}
                </CardContent>
                <CardFooter className="flex-wrap gap-3">
                    <Button
                        disabled={!connection || busy}
                        onClick={() =>
                            form.submit(
                                active ? sync(route) : activate(route),
                                { preserveScroll: true },
                            )
                        }
                    >
                        {form.processing ? (
                            <Spinner />
                        ) : active ? (
                            <RefreshCw data-icon="inline-start" />
                        ) : (
                            <Play data-icon="inline-start" />
                        )}
                        {active
                            ? 'Sincronizza ora'
                            : connection?.sync_status === 'suspended' ||
                                connection?.sync_status === 'source_changed'
                              ? 'Riconferma sorgente e attiva'
                              : 'Attiva sincronizzazione'}
                    </Button>
                    {sources.length ? (
                        <Button
                            variant="outline"
                            onClick={() => setAllOpen(true)}
                        >
                            Consulta fonti
                        </Button>
                    ) : null}
                </CardFooter>
            </Card>
            <Sheet open={allOpen} onOpenChange={setAllOpen}>
                <SheetContent
                    className="w-full overflow-y-auto sm:max-w-3xl"
                    aria-describedby={undefined}
                >
                    <SheetHeader>
                        <SheetTitle>Fonti raccolte dal dispositivo</SheetTitle>
                    </SheetHeader>
                    <div className="px-4 pb-6">
                        <SourceTable
                            sources={sources}
                            onSelect={select}
                            name="mikrotik-sources"
                        />
                    </div>
                </SheetContent>
            </Sheet>
            <Sheet
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open && !form.processing) setSelected(null);
                }}
            >
                <SheetContent
                    className="w-full overflow-y-auto sm:max-w-lg"
                    aria-describedby={undefined}
                >
                    <SheetHeader>
                        <SheetTitle>Dettaglio della fonte</SheetTitle>
                    </SheetHeader>
                    {selected ? (
                        <div className="flex flex-col gap-4 px-4 pb-6">
                            <SourceDetails sources={[selected]} />
                            {selected.subnet_id ? (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={subnetShow({
                                            company: route.company,
                                            site: route.site,
                                            subnet: selected.subnet_id,
                                        })}
                                    >
                                        Apri subnet
                                    </Link>
                                </Button>
                            ) : selected.vlan_id ? (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={vlanShow({
                                            company: route.company,
                                            site: route.site,
                                            vlan: selected.vlan_id,
                                        })}
                                    >
                                        Apri VLAN
                                    </Link>
                                </Button>
                            ) : null}
                            {selected.can_associate ? (
                                <>
                                    <Field orientation="horizontal">
                                        <Checkbox
                                            id="confirm-mikrotik-association"
                                            checked={confirmed}
                                            onCheckedChange={(value) =>
                                                setConfirmed(value === true)
                                            }
                                        />
                                        <FieldLabel htmlFor="confirm-mikrotik-association">
                                            {selected.kind === 'vlan'
                                                ? 'Confermo che le fonti descrivono lo stesso segmento VLAN.'
                                                : 'Confermo l’attribuzione della VLAN rilevata alla subnet senza VLAN.'}
                                        </FieldLabel>
                                    </Field>
                                    <Button
                                        disabled={!confirmed || busy}
                                        onClick={() => {
                                            form.transform((data) => ({
                                                ...data,
                                                source_id: selected.id,
                                            }));
                                            form.submit(associate(route), {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    setSelected(null),
                                            });
                                        }}
                                    >
                                        Associa a esistente
                                    </Button>
                                </>
                            ) : null}
                            {error ? (
                                <Alert variant="destructive">
                                    <AlertDescription>{error}</AlertDescription>
                                </Alert>
                            ) : null}
                        </div>
                    ) : null}
                </SheetContent>
            </Sheet>
        </>
    );
}

function SourceTable({
    sources,
    onSelect,
    name,
}: {
    sources: Source[];
    onSelect: (source: Source) => void;
    name: string;
}) {
    return (
        <DeviceTable
            name={name}
            rows={sources}
            onRowClick={onSelect}
            columns={[
                { id: 'kind', label: 'Tipo', visible: true },
                { id: 'value', label: 'Dato', visible: true },
                { id: 'problem', label: 'Da verificare', visible: true },
                { id: 'state', label: 'Stato fonte', visible: false },
                { id: 'comment', label: 'Commento', visible: false },
            ]}
            value={(source, column) =>
                column === 'kind'
                    ? sourceLabel(source)
                    : column === 'value'
                      ? (source.ip ??
                        source.data.cidr ??
                        (source.data.tag
                            ? `VLAN ${source.data.tag}`
                            : (source.data.name ??
                              source.data.mac ??
                              source.data.context ??
                              source.data.menu)))
                      : column === 'comment'
                        ? (source.data.comment ?? null)
                        : column === 'state'
                          ? source.state === 'missing'
                              ? 'Non più presente'
                              : source.stale
                                ? 'Non aggiornato'
                                : 'Corrente'
                          : source.problem
            }
            actions={(source) => (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => onSelect(source)}
                >
                    Dettaglio
                </Button>
            )}
        />
    );
}
