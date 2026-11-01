import { MikrotikSyncPanel } from '@/components/devices/mikrotik-sync-panel';
import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    Cable,
    ChevronDown,
    ScanSearch,
    Settings2,
    ShieldAlert,
    Trash2,
} from 'lucide-react';
import InputError from '@/components/input-error';
import { IconButton } from '@/components/icon-button';
import { DeviceTable } from '@/components/devices/device-table';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyContent,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    update,
    verify,
    analyze,
    destroy,
} from '@/routes/companies/sites/devices/mikrotik';

export type MikrotikConnection = {
    activated_at: string | null;
    sync_attempted_at: string | null;
    synced_at: string | null;
    sync_status: string | null;
    sync_message: string | null;
    sync_summary: {
        read: number;
        created: number;
        associated: number;
        updated: number;
        problems: number;
    } | null;
    server_url: string;
    username: string;
    has_password: boolean;
    allow_unverified_certificate: boolean;
    revision: string;
    checked_at: string | null;
    check_status: string | null;
    check_message: string | null;
    identity: {
        name: string;
        version: string;
        board: string | null;
        architecture: string | null;
    } | null;
    analyzed_at: string | null;
    analysis: {
        coverage: {
            path: string;
            label: string;
            status: string;
            count: number | null;
            fields: string[];
            message: string;
        }[];
        findings: { label: string; value: number | null; detail: string }[];
        cases: { label: string; status: string }[];
    } | null;
};

type Props = {
    company: number;
    site: number;
    device: number;
    connection: MikrotikConnection | null;
};

export function MikrotikDevice(props: Props) {
    const { auth } = usePage().props;
    if (!auth.user.is_superadmin) {
        return (
            <Alert>
                <AlertDescription>
                    La connessione MikroTik è gestita dal Superadmin.
                </AlertDescription>
            </Alert>
        );
    }
    return (
        <MikrotikPanel key={props.connection?.revision ?? 'new'} {...props} />
    );
}

function MikrotikPanel({ connection, ...route }: Props) {
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [diagnosticsOpen, setDiagnosticsOpen] = useState(false);
    const [removing, setRemoving] = useState(false);
    const [reading, setReading] = useState<'verify' | 'analyze' | null>(null);
    const form = useForm({
        server_url: connection?.server_url ?? '',
        username: connection?.username ?? '',
        password: '',
        allow_unverified_certificate:
            connection?.allow_unverified_certificate ?? false,
        revision: connection?.revision ?? null,
    });
    const operation = useForm({
        revision: connection?.revision ?? null,
        confirmed: true,
    });
    const busy = form.processing || operation.processing;
    const identity = connection?.identity;
    const report = connection?.analysis;
    const error = Object.values(operation.errors)[0];
    function read(action: 'verify' | 'analyze') {
        setReading(action);
        operation.submit(action === 'verify' ? verify(route) : analyze(route), {
            preserveScroll: true,
            onFinish: () => setReading(null),
        });
    }
    function changeSettings(open: boolean) {
        if (busy) return;
        setSettingsOpen(open);
        if (!open) {
            form.resetAndClearErrors();
            setRemoving(false);
        }
    }
    return (
        <Dialog open={settingsOpen} onOpenChange={changeSettings}>
            <div className="flex min-w-0 flex-col gap-5">
                {connection ? (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex min-w-0 flex-col gap-1">
                            <p className="text-sm font-medium break-words">
                                {identity?.name ?? 'Connessione MikroTik'}
                                {identity ? (
                                    <span className="font-normal text-muted-foreground">
                                        {' '}
                                        · RouterOS {identity.version}
                                    </span>
                                ) : null}
                            </p>
                            <p className="text-sm break-all text-muted-foreground">
                                {connection.server_url}
                            </p>
                        </div>
                        <DialogTrigger asChild>
                            <Button variant="outline" disabled={busy}>
                                <Settings2 data-icon="inline-start" />
                                Impostazioni connessione
                            </Button>
                        </DialogTrigger>
                    </div>
                ) : (
                    <Empty className="border">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <Cable />
                            </EmptyMedia>
                            <EmptyTitle>
                                Connessione MikroTik non configurata
                            </EmptyTitle>
                        </EmptyHeader>
                        <EmptyContent>
                            <DialogTrigger asChild>
                                <Button>
                                    <Settings2 data-icon="inline-start" />
                                    Configura connessione
                                </Button>
                            </DialogTrigger>
                        </EmptyContent>
                    </Empty>
                )}
                <MikrotikSyncPanel
                    connection={connection}
                    route={route}
                    disabled={busy || form.isDirty}
                />
                {connection ? (
                    <Collapsible
                        open={diagnosticsOpen}
                        onOpenChange={setDiagnosticsOpen}
                        className="rounded-xl border"
                    >
                        <CollapsibleTrigger asChild>
                            <Button
                                variant="ghost"
                                disabled={busy}
                                className="group h-auto min-h-12 w-full justify-between gap-3 rounded-xl px-5 py-3"
                            >
                                <span className="flex items-center gap-2">
                                    <ScanSearch data-icon="inline-start" />
                                    Diagnostica
                                </span>
                                <ChevronDown
                                    data-icon="inline-end"
                                    className="transition-transform group-data-[state=open]:rotate-180"
                                />
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="flex min-w-0 flex-col gap-6 p-4 pt-2 sm:p-6 sm:pt-2">
                            <section
                                className="flex flex-col gap-4"
                                aria-label="Verifica connessione"
                                aria-busy={operation.processing}
                            >
                                <h2 className="text-base font-medium">
                                    Verifica connessione
                                </h2>
                                <div
                                    className="flex flex-col gap-4"
                                    aria-live="polite"
                                >
                                    {connection?.checked_at ? (
                                        <div className="flex flex-col gap-2">
                                            <Badge
                                                className="self-start"
                                                variant={
                                                    connection.check_status ===
                                                    'ok'
                                                        ? 'secondary'
                                                        : 'destructive'
                                                }
                                            >
                                                {connection.check_status ===
                                                'ok'
                                                    ? 'Verificata'
                                                    : connection.check_status ===
                                                        'partial'
                                                      ? 'Lettura parziale'
                                                      : 'Verifica non riuscita'}
                                            </Badge>
                                            {connection.check_status !==
                                            'ok' ? (
                                                <p className="text-sm">
                                                    {connection.check_message}
                                                </p>
                                            ) : null}
                                            <p className="text-xs text-muted-foreground">
                                                Ultima verifica:{' '}
                                                {new Date(
                                                    connection.checked_at,
                                                ).toLocaleString('it-IT')}
                                            </p>
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            {connection
                                                ? 'Connessione non ancora verificata.'
                                                : 'Connessione non configurata.'}
                                        </p>
                                    )}
                                    {identity ? (
                                        <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-5 gap-y-3 text-sm">
                                            <dt className="text-muted-foreground">
                                                Identità
                                            </dt>
                                            <dd className="font-medium break-words">
                                                {identity.name}
                                            </dd>
                                            <dt className="text-muted-foreground">
                                                RouterOS
                                            </dt>
                                            <dd>{identity.version}</dd>
                                            <dt className="text-muted-foreground">
                                                Modello rilevato
                                            </dt>
                                            <dd className="break-words">
                                                {identity.board ??
                                                    'Non disponibile'}
                                            </dd>
                                            <dt className="text-muted-foreground">
                                                Architettura
                                            </dt>
                                            <dd>
                                                {identity.architecture ??
                                                    'Non disponibile'}
                                            </dd>
                                        </dl>
                                    ) : null}
                                    {reading ? (
                                        <p className="flex items-center gap-2 text-sm">
                                            <Spinner />
                                            {reading === 'verify'
                                                ? 'Verifica in corso…'
                                                : 'Analisi in corso…'}
                                        </p>
                                    ) : null}
                                    {error ? (
                                        <Alert variant="destructive">
                                            <AlertDescription>
                                                {error}
                                            </AlertDescription>
                                        </Alert>
                                    ) : null}
                                </div>
                                <div className="flex flex-wrap gap-3">
                                    <Button
                                        variant="outline"
                                        disabled={
                                            !connection || busy || form.isDirty
                                        }
                                        onClick={() => read('verify')}
                                    >
                                        <Cable data-icon="inline-start" />
                                        Verifica connessione
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={
                                            !connection || busy || form.isDirty
                                        }
                                        onClick={() => read('analyze')}
                                    >
                                        <ScanSearch data-icon="inline-start" />
                                        Analizza configurazione
                                    </Button>
                                </div>
                            </section>
                            <section
                                className="flex min-w-0 flex-col gap-5"
                                aria-label="Ricognizione RouterOS"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h2 className="text-base font-medium">
                                        Ricognizione RouterOS
                                    </h2>
                                    {connection?.analyzed_at ? (
                                        <p className="text-xs text-muted-foreground">
                                            Ultima analisi:{' '}
                                            {new Date(
                                                connection.analyzed_at,
                                            ).toLocaleString('it-IT')}
                                        </p>
                                    ) : null}
                                </div>
                                {report ? (
                                    <>
                                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                            {report.findings.map((finding) => (
                                                <Card
                                                    key={finding.label}
                                                    className="gap-3 py-4"
                                                >
                                                    <CardHeader className="px-4">
                                                        <CardTitle>
                                                            {finding.label}
                                                        </CardTitle>
                                                    </CardHeader>
                                                    <CardContent className="flex flex-col gap-2 px-4">
                                                        <p className="text-2xl font-medium tabular-nums">
                                                            {finding.value ??
                                                                '—'}
                                                        </p>
                                                        <p className="text-sm text-muted-foreground">
                                                            {finding.detail}
                                                        </p>
                                                    </CardContent>
                                                </Card>
                                            ))}
                                        </div>
                                        <DeviceTable
                                            name="mikrotik-coverage"
                                            rows={report.coverage.map(
                                                (menu, id) => ({
                                                    ...menu,
                                                    id,
                                                }),
                                            )}
                                            columns={[
                                                {
                                                    id: 'label',
                                                    label: 'Categoria',
                                                    visible: true,
                                                },
                                                {
                                                    id: 'message',
                                                    label: 'Esito sul dispositivo',
                                                    visible: true,
                                                },
                                                {
                                                    id: 'count',
                                                    label: 'Elementi',
                                                    visible: true,
                                                },
                                                {
                                                    id: 'path',
                                                    label: 'Menu RouterOS',
                                                    visible: false,
                                                },
                                                {
                                                    id: 'fields',
                                                    label: 'Proprietà riscontrate',
                                                    visible: false,
                                                },
                                            ]}
                                            value={(row, column) =>
                                                column === 'fields'
                                                    ? row.fields.join(', ')
                                                    : row[column]
                                            }
                                        />
                                        <Card>
                                            <CardHeader>
                                                <CardTitle>
                                                    Casistiche documentate
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                <dl className="grid gap-x-8 gap-y-4 md:grid-cols-2">
                                                    {report.cases?.map(
                                                        (item) => (
                                                            <div
                                                                key={item.label}
                                                                className="flex flex-wrap items-center justify-between gap-2 text-sm"
                                                            >
                                                                <dt>
                                                                    {item.label}
                                                                </dt>
                                                                <dd>
                                                                    <Badge variant="outline">
                                                                        {
                                                                            item.status
                                                                        }
                                                                    </Badge>
                                                                </dd>
                                                            </div>
                                                        ),
                                                    )}
                                                </dl>
                                            </CardContent>
                                        </Card>
                                    </>
                                ) : (
                                    <Empty className="rounded-xl border">
                                        <EmptyHeader>
                                            <EmptyTitle>
                                                Nessuna analisi eseguita
                                            </EmptyTitle>
                                        </EmptyHeader>
                                    </Empty>
                                )}
                            </section>
                        </CollapsibleContent>
                    </Collapsible>
                ) : null}
            </div>
            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {connection
                            ? 'Impostazioni connessione'
                            : 'Configura connessione MikroTik'}
                    </DialogTitle>
                </DialogHeader>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(update(route), {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset('password');
                                setSettingsOpen(false);
                            },
                        });
                    }}
                    className="flex flex-col gap-6"
                >
                    <FieldGroup>
                        <Field data-invalid={!!form.errors.server_url}>
                            <FieldLabel htmlFor="mikrotik-url">
                                Indirizzo HTTPS
                            </FieldLabel>
                            <Input
                                id="mikrotik-url"
                                type="url"
                                required
                                autoComplete="off"
                                spellCheck={false}
                                value={form.data.server_url}
                                disabled={busy}
                                aria-invalid={!!form.errors.server_url}
                                aria-describedby={
                                    form.errors.server_url
                                        ? 'mikrotik-url-error'
                                        : undefined
                                }
                                onChange={(event) =>
                                    form.setData(
                                        'server_url',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                id="mikrotik-url-error"
                                message={form.errors.server_url}
                            />
                        </Field>
                        <Field data-invalid={!!form.errors.username}>
                            <FieldLabel htmlFor="mikrotik-username">
                                Username
                            </FieldLabel>
                            <Input
                                id="mikrotik-username"
                                required
                                autoComplete="off"
                                spellCheck={false}
                                value={form.data.username}
                                disabled={busy}
                                aria-invalid={!!form.errors.username}
                                aria-describedby={
                                    form.errors.username
                                        ? 'mikrotik-username-error'
                                        : undefined
                                }
                                onChange={(event) =>
                                    form.setData('username', event.target.value)
                                }
                            />
                            <InputError
                                id="mikrotik-username-error"
                                message={form.errors.username}
                            />
                        </Field>
                        <Field data-invalid={!!form.errors.password}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <FieldLabel htmlFor="mikrotik-password">
                                    Password
                                </FieldLabel>
                                {connection?.has_password ? (
                                    <Badge variant="secondary">
                                        Password salvata
                                    </Badge>
                                ) : null}
                            </div>
                            <Input
                                id="mikrotik-password"
                                type="password"
                                placeholder={
                                    connection?.has_password
                                        ? '•'.repeat(128)
                                        : undefined
                                }
                                autoComplete="new-password"
                                required={!connection}
                                value={form.data.password}
                                disabled={busy}
                                aria-invalid={!!form.errors.password}
                                aria-describedby={
                                    form.errors.password
                                        ? 'mikrotik-password-error'
                                        : undefined
                                }
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                            />
                            <InputError
                                id="mikrotik-password-error"
                                message={form.errors.password}
                            />
                        </Field>
                        <Field orientation="horizontal">
                            <Checkbox
                                id="mikrotik-tls"
                                checked={form.data.allow_unverified_certificate}
                                disabled={busy}
                                onCheckedChange={(value) =>
                                    form.setData(
                                        'allow_unverified_certificate',
                                        value === true,
                                    )
                                }
                            />
                            <div className="flex items-center gap-1">
                                <FieldLabel htmlFor="mikrotik-tls">
                                    Accetta certificato non verificato
                                </FieldLabel>
                                {form.data.allow_unverified_certificate ? (
                                    <IconButton
                                        variant="ghost"
                                        className="shrink-0"
                                        label="L’identità del server non sarà verificata: le credenziali potrebbero essere intercettate."
                                    >
                                        <ShieldAlert className="text-destructive-foreground" />
                                    </IconButton>
                                ) : null}
                            </div>
                        </Field>
                        {Object.entries(form.errors)
                            .filter(
                                ([key]) =>
                                    ![
                                        'server_url',
                                        'username',
                                        'password',
                                    ].includes(key),
                            )
                            .map(([key, message]) => (
                                <InputError key={key} message={message} />
                            ))}
                    </FieldGroup>
                    <DialogFooter className="sm:justify-between">
                        {connection ? (
                            <Dialog
                                open={removing}
                                onOpenChange={(open) => {
                                    if (busy) return;
                                    operation.clearErrors();
                                    setRemoving(open);
                                }}
                            >
                                <DialogTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        disabled={busy}
                                    >
                                        <Trash2 data-icon="inline-start" />
                                        Rimuovi connessione
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Rimuovi connessione MikroTik
                                        </DialogTitle>
                                        <DialogDescription>
                                            Elimina le credenziali e l’ultima
                                            ricognizione. Il dispositivo, le
                                            interfacce e gli IP documentati
                                            restano conservati.
                                        </DialogDescription>
                                    </DialogHeader>
                                    {error ? (
                                        <InputError message={error} />
                                    ) : null}
                                    <DialogFooter>
                                        <Button
                                            variant="outline"
                                            disabled={busy}
                                            onClick={() => {
                                                operation.clearErrors();
                                                setRemoving(false);
                                            }}
                                        >
                                            Annulla
                                        </Button>
                                        <Button
                                            variant="destructive"
                                            disabled={busy}
                                            onClick={() =>
                                                operation.submit(
                                                    destroy(route),
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: () =>
                                                            setSettingsOpen(
                                                                false,
                                                            ),
                                                    },
                                                )
                                            }
                                        >
                                            {operation.processing ? (
                                                <Spinner />
                                            ) : null}
                                            Rimuovi connessione
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        ) : null}
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={() => changeSettings(false)}
                            >
                                Annulla
                            </Button>
                            <Button type="submit" disabled={busy}>
                                {form.processing ? <Spinner /> : null}
                                Salva
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
