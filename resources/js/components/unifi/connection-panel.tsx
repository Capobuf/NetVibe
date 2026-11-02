import { useForm } from '@inertiajs/react';
import {
    Cloud,
    Pause,
    Pencil,
    Play,
    RefreshCw,
    Server,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import {
    destroy,
    discover,
    selectConsoles,
    update,
    verify,
} from '@/actions/App/Http/Controllers/UnifiController';
import { SaveButton } from '@/components/save-button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/utils';
import { UnifiSiteList } from './site-list';
import type { UnifiCompany, UnifiConnection } from './types';

export function UnifiConnectionPanel({
    connection,
    companies,
    search,
    readonly,
    onEdit,
}: {
    connection: UnifiConnection;
    companies: UnifiCompany[];
    search: string;
    readonly: boolean;
    onEdit: () => void;
}) {
    const form = useForm({
        revision: connection.revision ?? '',
        confirmed: true,
    });
    const [pending, setPending] = useState<
        'verify' | 'discover' | 'toggle' | 'destroy' | null
    >(null);
    const [confirm, setConfirm] = useState<'suspend' | 'destroy' | null>(null);
    const savedConsoles = connection.selected_console_ids ?? [];
    const status = !connection.enabled
        ? 'Sospesa'
        : connection.check_status === 'ok'
          ? 'Verificata'
          : connection.checked_at
            ? 'Verifica non riuscita'
            : 'Da verificare';
    const linked = connection.sites.filter((source) => source.site !== null);

    function run(action: 'verify' | 'discover' | 'toggle' | 'destroy') {
        setPending(action);
        form.transform(() =>
            action === 'toggle'
                ? {
                      name: connection.name,
                      mode: connection.mode,
                      server_url: connection.server_url ?? '',
                      api_key: '',
                      allow_unverified_certificate:
                          connection.allow_unverified_certificate ?? false,
                      enabled: !connection.enabled,
                      revision: connection.revision,
                  }
                : { revision: connection.revision, confirmed: true },
        );
        const route =
            action === 'verify'
                ? verify(connection.id)
                : action === 'discover'
                  ? discover(connection.id)
                  : action === 'toggle'
                    ? update(connection.id)
                    : destroy(connection.id);
        form.submit(route, {
            preserveScroll: true,
            onSuccess: () => setConfirm(null),
            onFinish: () => setPending(null),
        });
    }

    return (
        <section
            className="flex min-w-0 flex-col gap-4"
            aria-labelledby={`unifi-connection-${connection.id}`}
        >
            <div className="flex flex-col gap-4">
                <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex min-w-0 flex-col gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            {connection.mode === 'cloud' ? (
                                <Cloud className="size-5 text-muted-foreground" />
                            ) : (
                                <Server className="size-5 text-muted-foreground" />
                            )}
                            <h2
                                id={`unifi-connection-${connection.id}`}
                                className="text-lg font-medium break-words"
                            >
                                {connection.name}
                            </h2>
                            <Badge
                                variant={
                                    connection.enabled &&
                                    connection.check_status === 'ok'
                                        ? 'secondary'
                                        : 'outline'
                                }
                            >
                                {status}
                            </Badge>
                            <Badge variant="outline">
                                {connection.mode === 'cloud'
                                    ? 'Cloud'
                                    : 'Diretta'}
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {connection.checked_at
                                ? `Ultima verifica: ${formatDateTime(connection.checked_at)}`
                                : 'Mai verificata'}
                        </p>
                        {!readonly && connection.mode === 'direct' ? (
                            <p className="text-sm break-all text-muted-foreground">
                                {connection.server_url}
                            </p>
                        ) : null}
                    </div>
                    {!readonly ? (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                disabled={form.processing}
                                onClick={onEdit}
                            >
                                <Pencil data-icon="inline-start" />
                                Modifica
                            </Button>
                            <Button
                                variant="ghost"
                                disabled={form.processing}
                                onClick={() =>
                                    connection.enabled
                                        ? setConfirm('suspend')
                                        : run('toggle')
                                }
                            >
                                {pending === 'toggle' ? (
                                    <Spinner />
                                ) : connection.enabled ? (
                                    <Pause data-icon="inline-start" />
                                ) : (
                                    <Play data-icon="inline-start" />
                                )}
                                {connection.enabled ? 'Sospendi' : 'Riattiva'}
                            </Button>
                            <Button
                                variant="ghost"
                                disabled={form.processing}
                                onClick={() => setConfirm('destroy')}
                            >
                                <Trash2 data-icon="inline-start" />
                                Rimuovi
                            </Button>
                        </div>
                    ) : null}
                </header>
                {connection.check_message &&
                connection.check_status !== 'ok' ? (
                    <Alert variant="destructive" aria-live="polite">
                        <AlertTitle>Verifica non riuscita</AlertTitle>
                        <AlertDescription>
                            {connection.check_message}
                        </AlertDescription>
                    </Alert>
                ) : null}
                {form.hasErrors && !confirm ? (
                    <Alert variant="destructive">
                        <AlertDescription>
                            {Object.values(form.errors).join(' ')}
                        </AlertDescription>
                    </Alert>
                ) : null}
                {!readonly ? (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            disabled={form.processing || !connection.enabled}
                            onClick={() => run('verify')}
                        >
                            {pending === 'verify' ? (
                                <Spinner />
                            ) : (
                                <ShieldCheck data-icon="inline-start" />
                            )}
                            Verifica connessione
                        </Button>
                        <Button
                            variant="outline"
                            disabled={
                                form.processing ||
                                !connection.enabled ||
                                (connection.mode === 'cloud' &&
                                    !savedConsoles.length)
                            }
                            onClick={() => run('discover')}
                        >
                            {pending === 'discover' ? (
                                <Spinner />
                            ) : (
                                <RefreshCw data-icon="inline-start" />
                            )}
                            Aggiorna siti e anteprima
                        </Button>
                    </div>
                ) : null}
            </div>
            {!readonly &&
            connection.mode === 'cloud' &&
            (connection.hosts?.length ?? 0) > 0 ? (
                <ConsoleSelection
                    key={`${connection.id}:${savedConsoles.join(',')}`}
                    connection={connection}
                    disabled={!connection.enabled || form.processing}
                />
            ) : null}
            {!readonly &&
            connection.mode === 'cloud' &&
            connection.check_status === 'ok' &&
            !connection.hosts?.length ? (
                <Alert>
                    <AlertDescription>
                        Nessuna console accessibile con questa API key.
                    </AlertDescription>
                </Alert>
            ) : null}
            <UnifiSiteList
                connection={connection}
                companies={companies}
                search={search}
                readonly={readonly}
            />
            <Dialog
                open={confirm !== null}
                onOpenChange={(open) => {
                    if (!open && !form.processing) setConfirm(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {confirm === 'destroy'
                                ? 'Rimuovere la connessione?'
                                : 'Sospendere la connessione?'}
                        </DialogTitle>
                        <DialogDescription>
                            {confirm === 'destroy'
                                ? 'Le associazioni e le anteprime di questa connessione saranno rimosse. I dati documentati saranno conservati.'
                                : 'Le nuove acquisizioni saranno sospese per tutti i siti collegati. I dati già acquisiti resteranno consultabili.'}
                        </DialogDescription>
                    </DialogHeader>
                    <p className="font-medium">{connection.name}</p>
                    {linked.length ? (
                        <ul className="flex max-h-48 flex-col gap-2 overflow-y-auto text-sm">
                            {linked.map((source) => (
                                <li key={source.id}>
                                    {source.name} ·{' '}
                                    {[
                                        source.site?.company.name,
                                        source.site?.name,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {form.hasErrors ? (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {Object.values(form.errors).join(' ')}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => setConfirm(null)}
                        >
                            Annulla
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={form.processing}
                            onClick={() =>
                                run(
                                    confirm === 'destroy'
                                        ? 'destroy'
                                        : 'toggle',
                                )
                            }
                        >
                            {form.processing ? <Spinner /> : null}
                            {confirm === 'destroy' ? 'Rimuovi' : 'Sospendi'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function ConsoleSelection({
    connection,
    disabled,
}: {
    connection: UnifiConnection;
    disabled: boolean;
}) {
    const form = useForm({
        selected_console_ids: connection.selected_console_ids ?? [],
        revision: connection.revision ?? '',
    });
    const [confirm, setConfirm] = useState(false);
    const removed = connection.sites.filter(
        (source) =>
            source.site &&
            (connection.selected_console_ids ?? []).includes(
                source.console_id,
            ) &&
            !form.data.selected_console_ids.includes(source.console_id),
    );
    const changed =
        JSON.stringify([...form.data.selected_console_ids].sort()) !==
        JSON.stringify([...(connection.selected_console_ids ?? [])].sort());

    function save() {
        form.transform((data) => ({ ...data, revision: connection.revision }));
        form.submit(selectConsoles(connection.id), {
            preserveScroll: true,
            onSuccess: () => setConfirm(false),
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Console da integrare</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <FieldSet disabled={disabled || form.processing}>
                    <FieldLegend className="sr-only">
                        Console accessibili
                    </FieldLegend>
                    <FieldGroup className="gap-3 sm:grid sm:grid-cols-2">
                        {(connection.hosts ?? []).map((host) => (
                            <Field
                                key={host.id}
                                orientation="horizontal"
                                className="rounded-lg border p-3"
                            >
                                <Checkbox
                                    id={`unifi-console-${connection.id}-${host.id}`}
                                    checked={form.data.selected_console_ids.includes(
                                        host.id,
                                    )}
                                    disabled={disabled || form.processing}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'selected_console_ids',
                                            checked === true
                                                ? [
                                                      ...form.data
                                                          .selected_console_ids,
                                                      host.id,
                                                  ]
                                                : form.data.selected_console_ids.filter(
                                                      (id) => id !== host.id,
                                                  ),
                                        )
                                    }
                                />
                                <FieldLabel
                                    htmlFor={`unifi-console-${connection.id}-${host.id}`}
                                    className="min-w-0 break-words"
                                >
                                    {host.name}
                                </FieldLabel>
                            </Field>
                        ))}
                    </FieldGroup>
                </FieldSet>
                <FieldError
                    errors={Object.values(form.errors).map((message) => ({
                        message,
                    }))}
                />
                <SaveButton
                    type="button"
                    className="self-start"
                    processing={form.processing}
                    disabled={disabled || !changed}
                    saved={!changed}
                    onClick={() => (removed.length ? setConfirm(true) : save())}
                >
                    Salva console
                </SaveButton>
            </CardContent>
            <Dialog
                open={confirm}
                onOpenChange={(open) => {
                    if (!form.processing) setConfirm(open);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Modificare le console selezionate?
                        </DialogTitle>
                        <DialogDescription>
                            Le nuove acquisizioni dei siti delle console
                            deselezionate verranno sospese.
                        </DialogDescription>
                    </DialogHeader>
                    <ul className="flex max-h-48 flex-col gap-2 overflow-y-auto text-sm">
                        {removed.map((source) => (
                            <li key={source.id}>
                                {source.console_name} · {source.name}
                            </li>
                        ))}
                    </ul>
                    <FieldError
                        errors={Object.values(form.errors).map((message) => ({
                            message,
                        }))}
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirm(false)}
                            disabled={form.processing}
                        >
                            Annulla
                        </Button>
                        <SaveButton
                            type="button"
                            processing={form.processing}
                            onClick={save}
                        >
                            Conferma
                        </SaveButton>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
