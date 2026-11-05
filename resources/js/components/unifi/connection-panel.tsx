import { useForm } from '@inertiajs/react';
import {
    Cloud,
    MoreHorizontal,
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
    update,
    verify,
} from '@/actions/App/Http/Controllers/UnifiController';
import {
    IntegrationStatus,
    type IntegrationState,
} from '@/components/integration-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Item,
    ItemActions,
    ItemContent,
    ItemDescription,
    ItemMedia,
    ItemTitle,
} from '@/components/ui/item';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/utils';
import { UnifiSiteList } from './site-list';
import { UnifiInventoryPreview } from './inventory-preview';
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
    const state: IntegrationState = !connection.enabled
        ? 'suspended'
        : connection.check_status === 'ok'
          ? 'active'
          : connection.checked_at
            ? 'error'
            : 'pending';
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
                <Item variant="muted">
                    <ItemMedia variant="icon" className="size-10 bg-background">
                        {connection.mode === 'cloud' ? <Cloud /> : <Server />}
                    </ItemMedia>
                    <ItemContent className="min-w-0">
                        <ItemTitle className="text-base">
                            <h2
                                id={`unifi-connection-${connection.id}`}
                                className="break-words"
                            >
                                {connection.name}
                            </h2>
                            <IntegrationStatus state={state} />
                        </ItemTitle>
                        <ItemDescription className="line-clamp-none break-all">
                            {[
                                connection.mode === 'cloud'
                                    ? 'Site Manager (cloud)'
                                    : 'UniFi Network (diretta)',
                                !readonly && connection.mode === 'direct'
                                    ? connection.server_url
                                    : null,
                                connection.checked_at
                                    ? `verificata il ${formatDateTime(connection.checked_at)}`
                                    : 'mai verificata',
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </ItemDescription>
                    </ItemContent>
                    {!readonly ? (
                        <ItemActions className="flex-wrap">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={
                                    form.processing || !connection.enabled
                                }
                                onClick={() => run('verify')}
                            >
                                {pending === 'verify' ? (
                                    <Spinner />
                                ) : (
                                    <ShieldCheck data-icon="inline-start" />
                                )}
                                Verifica
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
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
                                Aggiorna siti
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Altre azioni per ${connection.name}`}
                                        disabled={form.processing}
                                    >
                                        {pending === 'toggle' ? (
                                            <Spinner />
                                        ) : (
                                            <MoreHorizontal />
                                        )}
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuGroup>
                                        <DropdownMenuItem onSelect={onEdit}>
                                            <Pencil />
                                            Modifica
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                connection.enabled
                                                    ? setConfirm('suspend')
                                                    : run('toggle')
                                            }
                                        >
                                            {connection.enabled ? (
                                                <Pause />
                                            ) : (
                                                <Play />
                                            )}
                                            {connection.enabled
                                                ? 'Sospendi'
                                                : 'Riattiva'}
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={() => setConfirm('destroy')}
                                    >
                                        <Trash2 />
                                        Rimuovi
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </ItemActions>
                    ) : null}
                </Item>
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
            </div>
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
            <div className="flex flex-wrap gap-2">
                <UnifiInventoryPreview
                    connection={connection}
                    readonly={readonly}
                />
            </div>
            <UnifiSiteList
                key={`${connection.id}:${savedConsoles.join(',')}`}
                connection={connection}
                companies={companies}
                search={search}
                readonly={readonly}
                disabled={!connection.enabled || form.processing}
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
